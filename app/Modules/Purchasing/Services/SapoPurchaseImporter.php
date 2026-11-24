<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductImporter;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Purchasing\Models\SupplierReturnLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Chuyển dữ liệu" › Nhà cung cấp và nhập hàng: suppliers, purchase orders ("đơn nhập hàng"), returns to suppliers and
 * what was paid, read from Sapo GO by the "Lấy nhập hàng Sapo" bookmark
 * (resources/js/apps/tenant/pages/settings/sapoGoPurchasesExport.ts).
 *
 * Everything is history (imported_from = 'sapo'): the goods were put in and taken out of stock in Sapo and the stock
 * brought over with the products is today's, so nothing here moves stock or average cost. The payments were made from
 * Sapo's funds, so they stay out of the cash book. They do make up each supplier's debt exactly as in Sapo (goods
 * received less goods returned less payments plus refunds), which the shop can then pay off here as usual, and they
 * count in the purchase reports.
 *
 * Orders still waiting for goods in Sapo are left there: the shop finishes them in Sapo, or makes them again here.
 * Sending the file again finds orders and returns by their Sapo code and brings status and payments up to date.
 */
class SapoPurchaseImporter
{
    public const FORMAT = 'ezsell-sapo-go-purchases';

    public const SOURCE = 'sapo';

    private const UNMATCHED_SHOWN = 30;

    /** @var array<string, ProductVariant|null> */
    private array $variants = [];

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    /** @var array<string, int|null> */
    private array $warehouses = [];

    /**
     * @return array{suppliers: list<array<string, mixed>>, purchases: list<array<string, mixed>>, returns: list<array<string, mixed>>, store: string|null}|null
     */
    public function read(string $path): ?array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['purchases'] ?? null)) {
            return null;
        }
        $list = fn (string $key) => array_values(array_filter((array) ($data[$key] ?? []), 'is_array'));

        return [
            'suppliers' => $list('suppliers'),
            'purchases' => $list('purchases'),
            'returns' => $list('returns'),
            'store' => isset($data['store']) ? (string) $data['store'] : null,
        ];
    }

    /**
     * Checks (dry run) or imports one file. Nothing is written on a dry run.
     *
     * @param  array{suppliers: list<array<string, mixed>>, purchases: list<array<string, mixed>>, returns: list<array<string, mixed>>}  $file
     * @return array<string, mixed>
     */
    public function import(array $file, bool $dryRun): array
    {
        $summary = [
            'suppliers_total' => 0, 'suppliers_found' => 0, 'suppliers_new' => 0,
            'total' => count($file['purchases']), 'created' => 0, 'updated' => 0, 'skipped_open' => 0, 'cancelled' => 0,
            'from' => null, 'to' => null, 'value' => '0', 'paid' => '0', 'lines' => 0, 'unmatched_lines' => 0, 'unmatched' => [],
            'returns' => 0, 'returned' => '0', 'refunded' => '0', 'debt' => '0', 'errors' => [],
        ];
        $unmatched = [];

        $run = function () use ($file, $dryRun, &$summary, &$unmatched) {
            // Suppliers first: the list from Sapo, then any named only on an order or a return.
            $bySapoId = [];
            foreach ($file['suppliers'] as $data) {
                $key = $this->supplierKey($data);
                if ($key === null || isset($bySapoId['k:'.$key])) {
                    continue;
                }
                $bySapoId['k:'.$key] = true;
                $summary['suppliers_total']++;
                $this->findSupplier($data) !== null ? $summary['suppliers_found']++ : $summary['suppliers_new']++;
                if (! $dryRun) {
                    $supplier = $this->supplier($data);
                    if (filled($data['id'] ?? null)) {
                        $this->suppliers['id:'.$data['id']] = $supplier;
                    }
                }
            }
            $supplierOf = function (array $doc) use ($dryRun, &$summary, &$bySapoId): ?Supplier {
                $data = (array) ($doc['supplier'] ?? []);
                if (filled($data['id'] ?? null) && isset($this->suppliers['id:'.$data['id']])) {
                    return $this->suppliers['id:'.$data['id']];
                }
                $data = array_filter($data, 'filled') + ['name' => 'Nhà cung cấp Sapo'];
                $key = $this->supplierKey($data);
                if ($key !== null && ! isset($bySapoId['k:'.$key])) {
                    $bySapoId['k:'.$key] = true;
                    $summary['suppliers_total']++;
                    $this->findSupplier($data) !== null ? $summary['suppliers_found']++ : $summary['suppliers_new']++;
                }

                return $dryRun ? null : $this->supplier($data);
            };

            $codes = array_values(array_filter(array_map(fn ($p) => $this->code($p), $file['purchases'])));
            $existing = PurchaseOrder::query()->whereIn('number', $codes)->get()->keyBy('number');
            $debt = '0';
            $orders = [];

            foreach ($file['purchases'] as $index => $data) {
                $code = $this->code($data);
                if ($code === '') {
                    $summary['errors'][] = ['row' => $index + 1, 'message' => 'Đơn nhập không có mã.'];

                    continue;
                }
                $po = $existing[$code] ?? null;
                if ($po !== null && $po->imported_from !== self::SOURCE) {
                    $summary['errors'][] = ['row' => $index + 1, 'message' => "Mã {$code} đã dùng cho một đơn nhập tạo trong EzChat Bán hàng: bỏ qua."];

                    continue;
                }
                $state = $this->status($data);
                if ($state === null) {
                    $summary['skipped_open']++;

                    continue;
                }
                $created = $this->date($data['created_on'] ?? null) ?? now();
                $summary['from'] = $summary['from'] === null || $created->lt($summary['from']) ? $created : $summary['from'];
                $summary['to'] = $summary['to'] === null || $created->gt($summary['to']) ? $created : $summary['to'];

                $lines = $this->lines($data, $state);
                foreach ($lines as $line) {
                    $summary['lines']++;
                    if ($line['variant'] === null) {
                        $summary['unmatched_lines']++;
                        $key = $line['sku'] ?: $line['name'];
                        $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
                    }
                }
                $value = $this->receivedValue($data, $state, $lines);
                $payments = $this->payments($data, $state === PurchaseOrder::CANCELLED ? '0' : $value);
                $paid = array_reduce($payments, fn ($sum, $p) => bcadd($sum, $p['amount'], 2), '0');
                if ($state === PurchaseOrder::CANCELLED) {
                    $summary['cancelled']++;
                } else {
                    $summary['value'] = bcadd($summary['value'], $value, 2);
                }
                $summary['paid'] = bcadd($summary['paid'], $paid, 2);
                $debt = bcadd($debt, bcsub($value, $paid, 2), 2);
                $po !== null ? $summary['updated']++ : $summary['created']++;

                if (! $dryRun) {
                    $supplier = $supplierOf($data);
                    $orders[$code] = $this->save($po, $data, $code, $state, $supplier, $lines, $value, $payments, $created);
                } else {
                    $supplierOf($data);
                }
            }

            foreach ($file['returns'] as $index => $data) {
                if (in_array(mb_strtolower((string) ($data['status'] ?? '')), ['cancelled', 'canceled', 'deleted', 'draft'], true)) {
                    continue;
                }
                $code = $this->code($data);
                if ($code === '') {
                    continue;
                }
                $total = $this->money($data['total'] ?? 0);
                $refunds = $this->refunds($data);
                $refunded = array_reduce($refunds, fn ($sum, $p) => bcadd($sum, $p['amount'], 2), '0');
                $summary['returns']++;
                $summary['returned'] = bcadd($summary['returned'], $total, 2);
                $summary['refunded'] = bcadd($summary['refunded'], $refunded, 2);
                $debt = bcadd(bcsub($debt, $total, 2), $refunded, 2);
                if ($dryRun) {
                    $supplierOf($data);

                    continue;
                }
                $po = $orders[trim((string) ($data['purchase_code'] ?? ''))]
                    ?? (filled($data['purchase_code'] ?? null) ? PurchaseOrder::query()->where('number', $data['purchase_code'])->where('imported_from', self::SOURCE)->first() : null);
                $supplier = $po?->supplier_id ? Supplier::query()->find($po->supplier_id) : $supplierOf($data);
                if ($supplier === null) {
                    continue;
                }
                $this->saveReturn($data, $code, $supplier, $po, $total, $refunds);
            }

            if (! $dryRun) {
                foreach ($orders as $po) {
                    $this->recompute($po);
                }
                $this->mainSuppliers(array_values($orders));
            }
            $summary['debt'] = $debt;
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        arsort($unmatched);
        $shown = array_slice($unmatched, 0, self::UNMATCHED_SHOWN, true);
        $summary['unmatched'] = array_map(fn ($name, $count) => ['name' => (string) $name, 'lines' => $count], array_keys($shown), $shown);
        $summary['unmatched_skus'] = count($unmatched);
        $summary['from'] = $summary['from']?->toDateString();
        $summary['to'] = $summary['to']?->toDateString();

        return $summary;
    }

    /**
     * The order's status here, or null for an order still waiting for goods in Sapo (left there).
     * Received in full → received; part received and closed in Sapo → closed; cancelled → cancelled.
     */
    private function status(array $data): ?string
    {
        $status = mb_strtolower((string) ($data['status'] ?? ''));
        $receive = mb_strtolower((string) ($data['receive_status'] ?? ''));
        if (in_array($status, ['cancelled', 'canceled', 'deleted'], true)) {
            return PurchaseOrder::CANCELLED;
        }
        $full = in_array($receive, ['received', 'full', 'fully_received', 'completed', 'stocked'], true);
        $part = in_array($receive, ['partial', 'partially_received', 'partial_received'], true);
        if (! $full && ! $part && $receive === '') {
            // No receive status in the file: tell from the lines.
            $ordered = array_sum(array_map(fn ($l) => (float) ($l['quantity'] ?? 0), (array) ($data['lines'] ?? [])));
            $got = array_sum(array_map(fn ($l) => (float) ($l['received'] ?? $l['quantity'] ?? 0), (array) ($data['lines'] ?? [])));
            $full = $status === 'completed' || ($ordered > 0 && $got >= $ordered && filled($data['received_on'] ?? null));
            $part = ! $full && $got > 0 && filled($data['received_on'] ?? null);
        }
        if ($full) {
            return PurchaseOrder::RECEIVED;
        }
        if ($part && in_array($status, ['completed', 'closed', 'finished'], true)) {
            return PurchaseOrder::CLOSED;
        }

        return null;
    }

    /**
     * @return list<array{variant: ProductVariant|null, sku: string, name: string, quantity: int, received: int, unit_cost: string, tax_rate: string}>
     */
    private function lines(array $data, string $state): array
    {
        $out = [];
        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $quantity = max(0, (int) round((float) ($line['quantity'] ?? 0)));
            $received = match ($state) {
                PurchaseOrder::RECEIVED => $quantity,
                PurchaseOrder::CANCELLED => 0,
                default => min($quantity, max(0, (int) round((float) ($line['received'] ?? 0)))),
            };
            $price = (float) ($line['price'] ?? 0);
            $discount = (float) ($line['discount'] ?? 0);
            $unit = $quantity > 0 ? max(0, $price - $discount / $quantity) : $price;
            $out[] = [
                'variant' => $this->variant($line),
                'sku' => trim((string) ($line['sku'] ?? '')),
                'name' => trim((string) ($line['name'] ?? '')) ?: 'Sản phẩm',
                'quantity' => $quantity,
                'received' => $received,
                'unit_cost' => number_format($unit, 4, '.', ''),
                'tax_rate' => number_format(min(100, max(0, (float) ($line['tax_rate'] ?? 0))), 2, '.', ''),
            ];
        }

        return $out;
    }

    /** What the shop owes the supplier for the goods: Sapo's total, or the goods received of an order closed short. */
    private function receivedValue(array $data, string $state, array $lines): string
    {
        if ($state === PurchaseOrder::CANCELLED) {
            return '0.00';
        }
        $total = $this->money($data['total'] ?? 0);
        if ($state === PurchaseOrder::CLOSED) {
            $ordered = array_reduce($lines, fn ($s, $l) => $s + $l['quantity'] * (float) $l['unit_cost'], 0.0);
            $got = array_reduce($lines, fn ($s, $l) => $s + $l['received'] * (float) $l['unit_cost'], 0.0);

            return $ordered > 0 ? $this->money((float) $total * $got / $ordered) : $total;
        }

        return $total;
    }

    /**
     * Payments as Sapo kept them, or one payment of the paid amount when the file has no list.
     *
     * @return list<array{amount: string, method: string, paid_at: Carbon|null, reference: string|null}>
     */
    private function payments(array $data, string $cap): array
    {
        $out = [];
        foreach ((array) ($data['payments'] ?? []) as $p) {
            $amount = $this->money($p['amount'] ?? 0);
            if (is_array($p) && bccomp($amount, '0', 2) > 0) {
                $out[] = ['amount' => $amount, 'method' => $this->method($p['method'] ?? null), 'paid_at' => $this->date($p['paid_on'] ?? null), 'reference' => $this->text($p['reference'] ?? null, 100)];
            }
        }
        $paid = $this->money($data['paid'] ?? 0);
        if ($out === [] && bccomp($paid, '0', 2) > 0) {
            $out[] = ['amount' => $paid, 'method' => 'other', 'paid_at' => $this->date($data['received_on'] ?? $data['created_on'] ?? null), 'reference' => null];
        }

        return $out;
    }

    /** @return list<array{amount: string, method: string, paid_at: Carbon|null, reference: string|null}> */
    private function refunds(array $data): array
    {
        $out = [];
        foreach ((array) ($data['refunds'] ?? []) as $p) {
            $amount = $this->money($p['amount'] ?? 0);
            if (is_array($p) && bccomp($amount, '0', 2) > 0) {
                $out[] = ['amount' => $amount, 'method' => $this->method($p['method'] ?? null), 'paid_at' => $this->date($p['paid_on'] ?? null), 'reference' => $this->text($p['reference'] ?? null, 100)];
            }
        }
        $refunded = $this->money($data['refunded'] ?? 0);
        if ($out === [] && bccomp($refunded, '0', 2) > 0) {
            $out[] = ['amount' => $refunded, 'method' => 'other', 'paid_at' => $this->date($data['created_on'] ?? null), 'reference' => null];
        }

        return $out;
    }

    /**
     * @param  list<array{variant: ProductVariant|null, sku: string, name: string, quantity: int, received: int, unit_cost: string, tax_rate: string}>  $lines
     * @param  list<array{amount: string, method: string, paid_at: Carbon|null, reference: string|null}>  $payments
     */
    private function save(?PurchaseOrder $po, array $data, string $code, string $state, Supplier $supplier, array $lines, string $value, array $payments, Carbon $created): PurchaseOrder
    {
        $received = $state === PurchaseOrder::CANCELLED ? null : ($this->date($data['received_on'] ?? null) ?? $this->date($data['completed_on'] ?? null) ?? $created);
        $fields = [
            'status' => $state,
            'received_value' => $value,
            'received_at' => $received,
            'closed_at' => $state === PurchaseOrder::RECEIVED ? null : ($this->date($data['completed_on'] ?? null) ?? $this->date($data['cancelled_on'] ?? null) ?? $received),
        ];
        if ($po === null) {
            $po = new PurchaseOrder;
            $subtotal = array_reduce($lines, fn ($s, $l) => bcadd($s, bcmul((string) $l['quantity'], $l['unit_cost'], 4), 4), '0');
            $po->forceFill($fields + [
                'number' => $code,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $this->warehouse($data['location'] ?? null),
                'ordered_at' => $created,
                'due_date' => $this->date($data['due_on'] ?? null)?->toDateString(),
                'reference' => $this->text($data['reference'] ?? null, 100),
                'subtotal' => $this->money($subtotal),
                'discount' => $this->money($data['discount'] ?? 0),
                'tax_amount' => $this->money($data['tax'] ?? 0),
                'extra_cost' => $this->money($data['extra_cost'] ?? 0),
                'total' => $state === PurchaseOrder::CANCELLED ? $this->money($data['total'] ?? 0) : $this->money($data['total'] ?? $value),
                'note' => $this->text(trim(($data['note'] ?? '').(filled($data['staff'] ?? null) ? "\nNhân viên Sapo: ".$data['staff'] : '')), 1000),
                'tags' => array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 50), (array) ($data['tags'] ?? [])))) ?: null,
                'imported_from' => self::SOURCE,
                'created_at' => $created,
                'updated_at' => now(),
            ])->save();

            // One line per SKU (lines of the same SKU are added up); lines matching no SKU are left out, the total
            // still holds them.
            $merged = [];
            foreach ($lines as $line) {
                if ($line['variant'] === null) {
                    continue;
                }
                $id = $line['variant']->id;
                if (isset($merged[$id])) {
                    $qty = $merged[$id]['quantity'] + $line['quantity'];
                    $merged[$id]['unit_cost'] = $qty > 0 ? number_format(($merged[$id]['quantity'] * (float) $merged[$id]['unit_cost'] + $line['quantity'] * (float) $line['unit_cost']) / $qty, 4, '.', '') : $line['unit_cost'];
                    $merged[$id]['quantity'] = $qty;
                    $merged[$id]['received_qty'] += $line['received'];
                } else {
                    $merged[$id] = ['quantity' => $line['quantity'], 'received_qty' => $line['received'], 'unit_cost' => $line['unit_cost'], 'tax_rate' => $line['tax_rate']];
                }
            }
            foreach ($merged as $variantId => $line) {
                $row = new PurchaseOrderLine;
                $row->forceFill($line + ['purchase_order_id' => $po->id, 'product_variant_id' => $variantId])->save();
            }
        } else {
            $po->forceFill($fields)->save();
        }

        // The payments are taken again as Sapo has them now.
        SupplierPayment::query()->where('purchase_order_id', $po->id)->where('imported_from', self::SOURCE)->where('kind', SupplierPayment::PAYMENT)->delete();
        foreach ($payments as $payment) {
            $this->payment($supplier, $po, SupplierPayment::PAYMENT, $payment, $received ?? $created);
        }

        return $po;
    }

    /** @param  list<array{amount: string, method: string, paid_at: Carbon|null, reference: string|null}>  $refunds */
    private function saveReturn(array $data, string $code, Supplier $supplier, ?PurchaseOrder $po, string $total, array $refunds): void
    {
        $at = $this->date($data['created_on'] ?? null) ?? now();
        $return = SupplierReturn::query()->where('number', $code)->first();
        if ($return !== null && $return->imported_from !== self::SOURCE) {
            return;
        }
        if ($return === null) {
            $return = new SupplierReturn;
            $return->forceFill([
                'number' => $code,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $po?->id,
                'warehouse_id' => $po->warehouse_id ?? $this->warehouse($data['location'] ?? null),
                'total' => $total,
                'reason' => $this->text($data['reason'] ?? null, 500),
                'imported_from' => self::SOURCE,
                'created_at' => $at,
                'updated_at' => now(),
            ])->save();
            foreach ((array) ($data['lines'] ?? []) as $line) {
                $variant = is_array($line) ? $this->variant($line) : null;
                $quantity = (int) round((float) ($line['quantity'] ?? 0));
                if ($variant === null || $quantity <= 0) {
                    continue;
                }
                $row = new SupplierReturnLine;
                $row->forceFill(['supplier_return_id' => $return->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'unit_cost' => number_format((float) ($line['price'] ?? 0), 4, '.', '')])->save();
                if ($po !== null) {
                    PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->where('product_variant_id', $variant->id)
                        ->update(['returned_qty' => DB::raw('LEAST(received_qty, returned_qty + '.$quantity.')')]);
                }
            }
        }

        SupplierPayment::query()->where('imported_from', self::SOURCE)->where('kind', SupplierPayment::REFUND)->where('reference', 'sapo:'.$code)->delete();
        foreach ($refunds as $refund) {
            $this->payment($supplier, null, SupplierPayment::REFUND, ['reference' => 'sapo:'.$code] + $refund, $at);
        }
        if ($po !== null) {
            $this->recompute($po);
        }
    }

    /** @param  array{amount: string, method: string, paid_at: Carbon|null, reference: string|null}  $data */
    private function payment(Supplier $supplier, ?PurchaseOrder $po, string $kind, array $data, Carbon $fallback): void
    {
        $payment = new SupplierPayment;
        // No fund: the money left Sapo's funds, not this shop's cash book.
        $payment->forceFill([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po?->id,
            'kind' => $kind,
            'method' => $data['method'],
            'amount' => $data['amount'],
            'reference' => $data['reference'],
            'note' => 'Chuyển từ Sapo',
            'paid_at' => $data['paid_at'] ?? $fallback,
            'imported_from' => self::SOURCE,
        ])->save();
    }

    private function recompute(PurchaseOrder $po): void
    {
        $paid = (string) SupplierPayment::query()->where('purchase_order_id', $po->id)->where('kind', SupplierPayment::PAYMENT)->whereNull('voided_at')->sum('amount');
        $returned = (string) SupplierReturn::query()->where('purchase_order_id', $po->id)->sum('total');
        $po->forceFill(['paid_amount' => bcadd($paid, '0', 2), 'returned_value' => bcadd($returned, '0', 2)])->save();
    }

    /**
     * A SKU without a main supplier gets the supplier of its latest order from Sapo, as reorder suggestions use it.
     *
     * @param  list<PurchaseOrder>  $orders
     */
    private function mainSuppliers(array $orders): void
    {
        $latest = [];
        foreach ($orders as $po) {
            if ($po->status === PurchaseOrder::CANCELLED) {
                continue;
            }
            foreach (PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->pluck('product_variant_id') as $variantId) {
                if (! isset($latest[$variantId]) || $latest[$variantId][0]->lt($po->ordered_at)) {
                    $latest[$variantId] = [$po->ordered_at, $po->supplier_id];
                }
            }
        }
        foreach ($latest as $variantId => [, $supplierId]) {
            ProductVariant::query()->whereKey($variantId)->whereNull('supplier_id')->update(['supplier_id' => $supplierId]);
        }
    }

    private function supplierKey(array $data): ?string
    {
        $key = trim((string) ($data['code'] ?? '')) ?: mb_strtolower(trim((string) ($data['name'] ?? '')));

        return $key === '' ? null : $key;
    }

    private function findSupplier(array $data): ?Supplier
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $cache = 'k:'.($code ?: mb_strtolower($name));
        if (isset($this->suppliers[$cache])) {
            return $this->suppliers[$cache];
        }
        $found = ($code !== '' ? Supplier::query()->where('code', $code)->first() : null)
            ?? ($name !== '' ? Supplier::query()->where('name', $name)->first() : null);
        if ($found !== null) {
            $this->suppliers[$cache] = $found;
        }

        return $found;
    }

    /** The supplier with the Sapo code (else the same name), made when missing; blanks are filled from Sapo. */
    private function supplier(array $data): Supplier
    {
        $supplier = $this->findSupplier($data);
        $fields = array_filter([
            'contact_name' => $this->text($data['contact'] ?? null, 100),
            'phone' => $this->text($data['phone'] ?? null, 20),
            'email' => $this->text($data['email'] ?? null, 150),
            'address' => $this->text($data['address'] ?? null, 500),
            'tax_code' => $this->text($data['tax_code'] ?? null, 20),
            'note' => $this->text($data['note'] ?? null, 1000),
        ], 'filled');
        if ($supplier === null) {
            $code = $this->text($data['code'] ?? null, 32);
            if ($code === null || Supplier::query()->where('code', $code)->exists()) {
                $code = DocumentNumber::next('NCC');
            }
            $supplier = new Supplier;
            $supplier->forceFill($fields + [
                'code' => $code,
                'name' => $this->text($data['name'] ?? null, 150) ?? $code,
                'is_active' => ! in_array(mb_strtolower((string) ($data['status'] ?? 'active')), ['disable', 'disabled', 'inactive', 'deleted'], true),
            ])->save();
        } else {
            $missing = array_filter($fields, fn ($value, $key) => blank($supplier->{$key}), ARRAY_FILTER_USE_BOTH);
            if ($missing !== []) {
                $supplier->forceFill($missing)->save();
            }
        }
        $key = 'k:'.(trim((string) ($data['code'] ?? '')) ?: mb_strtolower(trim((string) ($data['name'] ?? ''))));

        return $this->suppliers[$key] = $supplier;
    }

    private function variant(array $line): ?ProductVariant
    {
        foreach (['sku', 'barcode'] as $field) {
            $value = mb_strtolower(trim((string) ($line[$field] ?? '')));
            if ($value === '') {
                continue;
            }
            $key = $field.':'.$value;
            if (! array_key_exists($key, $this->variants)) {
                // The product import turned spaces and other odd characters of a Sapo SKU into "-": look for that too.
                $tidy = $field === 'sku' ? mb_strtolower(ProductImporter::tidySku($value)) : $value;
                $this->variants[$key] = ProductVariant::query()->where($field, $value)->first()
                    ?? ($tidy !== $value ? ProductVariant::query()->where($field, $tidy)->first() : null);
            }
            if ($this->variants[$key] !== null) {
                return $this->variants[$key];
            }
        }

        return null;
    }

    private function warehouse(mixed $location): int
    {
        $name = trim((string) $location);
        if (! array_key_exists($name, $this->warehouses)) {
            $this->warehouses[$name] = ($name !== '' ? Warehouse::query()->where('name', $name)->value('id') : null)
                ?? Warehouse::query()->where('is_default', true)->value('id')
                ?? Warehouse::query()->orderBy('id')->value('id');
        }

        return (int) $this->warehouses[$name];
    }

    private function method(mixed $name): string
    {
        $name = mb_strtolower(trim((string) $name));

        return match (true) {
            $name === '' => 'other',
            in_array($name, SupplierPayment::METHODS, true) => $name,
            str_contains($name, 'mặt') || str_contains($name, 'cash') => 'cash',
            str_contains($name, 'chuyển') || str_contains($name, 'bank') || str_contains($name, 'transfer') || str_contains($name, 'ngân hàng') => 'bank_transfer',
            str_contains($name, 'thẻ') || str_contains($name, 'card') || str_contains($name, 'pos') => 'card',
            default => 'other',
        };
    }

    private function code(array $data): string
    {
        return mb_substr(trim((string) ($data['code'] ?? '')), 0, 32);
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(mixed $value): string
    {
        return bcadd(is_numeric($value) ? (string) round((float) $value, 2) : '0', '0', 2);
    }
}
