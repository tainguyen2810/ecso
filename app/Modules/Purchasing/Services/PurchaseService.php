<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders: draft → ordered → partial → received, or closed early / cancelled.
 * Goods arrive through stock receipts (RCV) linked to the order, so the weighted average cost is updated by
 * InventoryService exactly as for a manual receipt. What is owed is the value received less the value returned.
 */
class PurchaseService
{
    public function __construct(private readonly InventoryDocumentService $documents, private readonly AuditLogger $audit) {}

    /**
     * @param  array{supplier: Supplier, warehouse: Warehouse, lines: array<int, array{variant_id: int, quantity: int, unit_cost: string|int|float, tax_rate?: string|int|float|null}>, expected_at?: string|null, note?: string|null, invoice_date?: string|null, reference?: string|null, tags?: array<int, string>|null, place?: bool, discount?: string|int|float|null, extra_cost?: string|int|float|null, extra_cost_note?: string|null, extra_cost_to_supplier?: bool, vat_in_cost?: bool}  $data
     */
    public function create(array $data): PurchaseOrder
    {
        $this->assertSupplier($data['supplier']);

        return DB::transaction(function () use ($data) {
            $po = new PurchaseOrder([
                'supplier_id' => $data['supplier']->id,
                'warehouse_id' => $data['warehouse']->id,
                'status' => PurchaseOrder::DRAFT,
                'expected_at' => $data['expected_at'] ?? null,
                'note' => $data['note'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'reference' => $data['reference'] ?? null,
                'tags' => $data['tags'] ?? null,
                ...$this->costHeader($data),
            ]);
            $po->forceFill(['number' => DocumentNumber::next('PO'), 'created_by' => Auth::id()])->save();
            $this->replaceLines($po, $data['lines']);
            $this->audit->record('purchase.created', $po, ['number' => $po->number]);

            if ($data['place'] ?? false) {
                $this->markOrdered($po);
            }

            return $po;
        });
    }

    /**
     * Header and lines can change until the first goods arrive.
     *
     * @param  array{supplier?: Supplier, warehouse?: Warehouse, lines?: array<int, array{variant_id: int, quantity: int, unit_cost: string|int|float, tax_rate?: string|int|float|null}>, expected_at?: string|null, note?: string|null, invoice_date?: string|null, reference?: string|null, tags?: array<int, string>|null, discount?: string|int|float|null, extra_cost?: string|int|float|null, extra_cost_note?: string|null, extra_cost_to_supplier?: bool, vat_in_cost?: bool}  $data
     */
    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data) {
            $po = $this->lock($po);
            if (! $this->editable($po)) {
                throw ValidationException::withMessages(['status' => 'Đơn nhập đã nhận hàng hoặc đã đóng, không sửa được nữa.']);
            }
            if (isset($data['supplier'])) {
                $this->assertSupplier($data['supplier']);
                $po->supplier_id = $data['supplier']->id;
            }
            if (isset($data['warehouse'])) {
                $po->warehouse_id = $data['warehouse']->id;
            }
            $po->fill(array_intersect_key($data, array_flip(['expected_at', 'note', 'invoice_date', 'reference', 'tags'])));
            $po->fill(array_intersect_key($this->costHeader($data), $data));
            $po->save();
            if (isset($data['lines'])) {
                $this->replaceLines($po, $data['lines']);
            } else {
                $this->recomputeTotals($po);
            }
            $this->audit->record('purchase.updated', $po, ['number' => $po->number]);

            return $po;
        });
    }

    public function place(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            $po = $this->lock($po);
            if ($po->status !== PurchaseOrder::DRAFT) {
                throw ValidationException::withMessages(['status' => 'Chỉ đơn nháp mới đặt hàng được.']);
            }
            $this->markOrdered($po);

            return $po;
        });
    }

    /**
     * Receives some or all of the outstanding goods. The actual unit cost may differ from the ordered one
     * (price change, discount); the receipt and the average cost use the actual cost.
     *
     * @param  array<int, array{variant_id: int, quantity: int, unit_cost?: string|int|float|null}>  $lines
     */
    public function receive(PurchaseOrder $po, array $lines, ?string $note = null): StockReceipt
    {
        return DB::transaction(function () use ($po, $lines, $note) {
            $po = $this->lock($po);
            if (! in_array($po->status, PurchaseOrder::OPEN, true)) {
                throw ValidationException::withMessages(['status' => 'Đơn nhập đã hoàn tất, đóng hoặc hủy.']);
            }

            /** @var Collection<int, PurchaseOrderLine> $poLines */
            $poLines = $po->lines()->lockForUpdate()->get()->keyBy('product_variant_id');
            $receiptLines = [];
            $value = '0';
            $tax = '0';
            $extra = '0';

            foreach ($lines as $i => $line) {
                $poLine = $poLines->get($line['variant_id']);
                if (! $poLine) {
                    throw ValidationException::withMessages(["lines.{$i}.variant_id" => 'SKU không có trong đơn nhập.']);
                }
                if ($line['quantity'] < 1) {
                    continue;
                }
                if ($line['quantity'] > $poLine->remaining()) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => "Chỉ còn {$poLine->remaining()} chưa nhận."]);
                }
                $qty = (string) $line['quantity'];
                $cost = bcadd((string) ($line['unit_cost'] ?? $poLine->unit_cost), '0', 4);
                $unitTax = bcdiv(bcmul($cost, (string) $poLine->tax_rate, 6), '100', 4);
                $unitExtra = $this->extraCostPerUnit($po, $poLine, $poLines);
                $unitDiscount = $this->discountPerUnit($po, $poLine, $poLines);
                $landed = $this->atLeastZero(bcsub(bcadd(bcadd($cost, $po->vat_in_cost ? $unitTax : '0', 4), $unitExtra, 4), $unitDiscount, 4));
                $receiptLines[] = ['variant_id' => $poLine->product_variant_id, 'quantity' => $line['quantity'], 'unit_cost' => $landed];

                $value = bcadd($value, bcmul(bcsub(bcadd($cost, $unitTax, 4), $unitDiscount, 4), $qty, 4), 4);
                $tax = bcadd($tax, bcmul($unitTax, $qty, 4), 4);
                $extra = bcadd($extra, bcmul($unitExtra, $qty, 4), 4);
                $poLine->increment('received_qty', $line['quantity']);
            }

            if ($receiptLines === []) {
                throw ValidationException::withMessages(['lines' => 'Nhập số lượng nhận cho ít nhất một dòng.']);
            }

            $warehouse = Warehouse::query()->findOrFail($po->warehouse_id);
            $receipt = $this->documents->receive($warehouse, $receiptLines, $note ?? "Nhận hàng {$po->number}", StockReceipt::SOURCE_PURCHASE, $po->id);

            $complete = $poLines->every(fn (PurchaseOrderLine $l) => $l->remaining() === 0);
            if ($po->extra_cost_to_supplier) {
                $value = bcadd($value, $extra, 4);
            }
            $po->forceFill([
                'received_value' => bcadd((string) $po->received_value, $value, 2),
                'received_tax' => bcadd((string) $po->received_tax, $tax, 2),
                'received_extra_cost' => bcadd((string) $po->received_extra_cost, $extra, 2),
                'status' => $complete ? PurchaseOrder::RECEIVED : PurchaseOrder::PARTIAL,
                'ordered_at' => $po->ordered_at ?? now(),
                'received_at' => now(),
            ])->save();
            $this->audit->record('purchase.received', $po, ['number' => $po->number, 'receipt' => $receipt->number, 'value' => bcadd($value, '0', 2), 'tax' => bcadd($tax, '0', 2), 'extra_cost' => bcadd($extra, '0', 2)]);

            return $receipt;
        });
    }

    /** Stops waiting for the rest of a partly received order. */
    public function close(PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reason) {
            $po = $this->lock($po);
            if ($po->status !== PurchaseOrder::PARTIAL) {
                throw ValidationException::withMessages(['status' => 'Chỉ đóng được đơn đã nhận một phần. Đơn chưa nhận hàng thì hủy.']);
            }
            $po->forceFill(['status' => PurchaseOrder::CLOSED, 'closed_at' => now()])->save();
            $this->audit->record('purchase.closed', $po, ['number' => $po->number, 'reason' => $reason]);

            return $po;
        });
    }

    public function cancel(PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reason) {
            $po = $this->lock($po);
            if (! in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED], true)) {
                throw ValidationException::withMessages(['status' => 'Đơn đã nhận hàng, không hủy được. Hãy đóng đơn hoặc tạo phiếu trả hàng.']);
            }
            if ($po->payments()->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['status' => 'Đơn đã có phiếu chi, hãy hủy phiếu chi trước.']);
            }
            $po->forceFill(['status' => PurchaseOrder::CANCELLED, 'closed_at' => now()])->save();
            $this->audit->record('purchase.cancelled', $po, ['number' => $po->number, 'reason' => $reason]);

            return $po;
        });
    }

    public function editable(PurchaseOrder $po): bool
    {
        return in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED], true) && (float) $po->received_value == 0.0;
    }

    private function markOrdered(PurchaseOrder $po): void
    {
        $po->forceFill(['status' => PurchaseOrder::ORDERED, 'ordered_at' => now()])->save();
        $this->audit->record('purchase.ordered', $po, ['number' => $po->number]);
    }

    /**
     * @param  array<int, array{variant_id: int, quantity: int, unit_cost: string|int|float, tax_rate?: string|int|float|null}>  $lines
     */
    private function replaceLines(PurchaseOrder $po, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Đơn nhập cần ít nhất một sản phẩm.']);
        }
        $po->lines()->delete();
        $merged = [];
        foreach ($lines as $line) {
            $key = $line['variant_id'];
            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += $line['quantity'];
            } else {
                $merged[$key] = [
                    'product_variant_id' => $key,
                    'quantity' => $line['quantity'],
                    'unit_cost' => bcadd((string) $line['unit_cost'], '0', 4),
                    'tax_rate' => bcadd((string) ($line['tax_rate'] ?? 0), '0', 2),
                ];
            }
        }
        $po->lines()->createMany(array_values($merged));
        $this->recomputeTotals($po);
    }

    /** Goods + VAT, plus the purchase costs when they are on the supplier's invoice. */
    private function recomputeTotals(PurchaseOrder $po): void
    {
        $subtotal = '0';
        $tax = '0';
        foreach ($po->lines()->get() as $line) {
            $goods = bcmul((string) $line->unit_cost, (string) $line->quantity, 4);
            $subtotal = bcadd($subtotal, $goods, 4);
            $tax = bcadd($tax, bcdiv(bcmul($goods, (string) $line->tax_rate, 6), '100', 4), 4);
        }
        $total = bcsub(bcadd($subtotal, $tax, 4), (string) $po->discount, 4);
        if (bccomp($total, '0', 4) < 0) {
            throw ValidationException::withMessages(['discount' => 'Chiết khấu không được lớn hơn tiền hàng.']);
        }
        if ($po->extra_cost_to_supplier) {
            $total = bcadd($total, (string) $po->extra_cost, 4);
        }
        $po->forceFill(['subtotal' => bcadd($subtotal, '0', 2), 'tax_amount' => bcadd($tax, '0', 2), 'total' => bcadd($total, '0', 2)])->save();
    }

    /**
     * Purchase costs are spread over the ordered goods by value (by quantity when everything is free), so each unit
     * received carries its share whatever the delivery it arrives in.
     *
     * @param  Collection<int, PurchaseOrderLine>  $lines
     */
    public function extraCostPerUnit(PurchaseOrder $po, PurchaseOrderLine $line, Collection $lines): string
    {
        if (bccomp((string) $po->extra_cost, '0', 2) <= 0) {
            return '0';
        }
        $orderedValue = $lines->reduce(fn (string $sum, PurchaseOrderLine $l) => bcadd($sum, bcmul((string) $l->unit_cost, (string) $l->quantity, 4), 4), '0');
        if (bccomp($orderedValue, '0', 4) > 0) {
            return bcdiv(bcmul((string) $po->extra_cost, (string) $line->unit_cost, 8), $orderedValue, 4);
        }
        $units = (string) $lines->sum('quantity');

        return $units === '0' ? '0' : bcdiv((string) $po->extra_cost, $units, 4);
    }

    /**
     * The order discount is spread over the ordered goods by value, like the purchase costs.
     *
     * @param  Collection<int, PurchaseOrderLine>  $lines
     */
    public function discountPerUnit(PurchaseOrder $po, PurchaseOrderLine $line, Collection $lines): string
    {
        if (bccomp((string) $po->discount, '0', 2) <= 0) {
            return '0';
        }
        $orderedValue = $lines->reduce(fn (string $sum, PurchaseOrderLine $l) => bcadd($sum, bcmul((string) $l->unit_cost, (string) $l->quantity, 4), 4), '0');

        return bccomp($orderedValue, '0', 4) > 0 ? bcdiv(bcmul((string) $po->discount, (string) $line->unit_cost, 8), $orderedValue, 4) : '0';
    }

    private function atLeastZero(string $amount): string
    {
        return bccomp($amount, '0', 4) < 0 ? '0' : $amount;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{discount: string, extra_cost: string, extra_cost_note: string|null, extra_cost_to_supplier: bool, vat_in_cost: bool}
     */
    private function costHeader(array $data): array
    {
        return [
            'discount' => bcadd((string) ($data['discount'] ?? 0), '0', 2),
            'extra_cost' => bcadd((string) ($data['extra_cost'] ?? 0), '0', 2),
            'extra_cost_note' => $data['extra_cost_note'] ?? null,
            'extra_cost_to_supplier' => (bool) ($data['extra_cost_to_supplier'] ?? false),
            'vat_in_cost' => (bool) ($data['vat_in_cost'] ?? false),
        ];
    }

    private function assertSupplier(Supplier $supplier): void
    {
        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Nhà cung cấp đã ngừng giao dịch.']);
        }
    }

    private function lock(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
    }
}
