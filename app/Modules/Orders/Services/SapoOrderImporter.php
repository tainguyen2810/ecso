<?php

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductImporter;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnLine;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Services\CustomerResolver;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "Chuyển dữ liệu" › Đơn hàng cũ: the order history read from Sapo GO by the "Lấy đơn hàng Sapo" bookmark
 * (resources/js/apps/tenant/pages/settings/sapoGoOrdersExport.ts), one file of up to 2,000 orders at a time.
 *
 * The orders are history (orders.historical, imported_from = 'sapo'): their stock was already handled in Sapo and the
 * stock brought over with the products is today's, so they never hold or take stock, are never pushed to a channel and
 * never message the buyer. What they owed was brought over as the customer's opening debt, so they carry no debt
 * either (CustomerDebt). They do count in every report, like the shop's own orders: revenue at delivery, profit at the
 * cost Sapo kept on the line (else the SKU's current average cost).
 *
 * Sending a file again finds its orders by the Sapo code and only brings their status and payment up to date, so the
 * shop can take the history once and the last days again on the day it switches.
 */
class SapoOrderImporter
{
    public const FORMAT = 'ezsell-sapo-go-orders';

    public const SOURCE = 'sapo';

    /** A shipped order younger than this may still be on its way: it is left to finish in Sapo. */
    private const RECENT_DAYS = 14;

    /** Lines of an order that matched no SKU are listed by name, at most this many. */
    private const UNMATCHED_SHOWN = 30;

    /** @var array<string, ProductVariant|null> */
    private array $variants = [];

    /** @var array<string, SalesChannel> */
    private array $channels = [];

    /** @var array<string, int|null> */
    private array $warehouses = [];

    public function __construct(private readonly CustomerResolver $customers) {}

    /**
     * @return array{orders: list<array<string, mixed>>, returns: list<array<string, mixed>>, store: string|null, part: int|null, parts: int|null}|null
     */
    public function read(string $path): ?array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['orders'] ?? null)) {
            return null;
        }

        return [
            'orders' => array_values(array_filter($data['orders'], 'is_array')),
            'returns' => array_values(array_filter((array) ($data['returns'] ?? []), 'is_array')),
            'store' => isset($data['store']) ? (string) $data['store'] : null,
            'part' => isset($data['part']) ? (int) $data['part'] : null,
            'parts' => isset($data['parts']) ? (int) $data['parts'] : null,
        ];
    }

    /**
     * Checks (dry run) or imports one file. Nothing is written on a dry run.
     *
     * @param  array{orders: list<array<string, mixed>>, returns: list<array<string, mixed>>}  $file
     * @return array<string, mixed>
     */
    public function import(array $file, bool $dryRun): array
    {
        $existing = Order::query()->where('imported_from', self::SOURCE)
            ->whereIn('external_order_id', array_map(fn ($o) => $this->code($o), $file['orders']))
            ->get(['id', 'external_order_id', 'status'])->keyBy('external_order_id');
        // A marketplace order Sapo also kept (its reference is the marketplace's order number) and that already came
        // in through the shop's connection is not brought in a second time.
        $references = array_values(array_filter(array_map(fn ($o) => trim((string) ($o['reference'] ?? '')), $file['orders'])));
        $synced = $references === [] ? [] : array_flip(Order::query()->where('marketplace', '!=', Order::SOURCE_MANUAL)
            ->whereIn('external_order_id', $references)->pluck('external_order_id')->all());

        $summary = [
            'total' => count($file['orders']), 'created' => 0, 'updated' => 0, 'skipped_synced' => 0, 'skipped_open' => 0,
            'from' => null, 'to' => null, 'revenue' => '0', 'lines' => 0, 'unmatched_lines' => 0, 'unmatched' => [],
            'customers_found' => 0, 'customers_new' => 0, 'walk_in' => 0, 'channels' => [], 'returns' => 0, 'errors' => [],
        ];
        $unmatched = [];

        $run = function () use ($file, $dryRun, $existing, $synced, &$summary, &$unmatched) {
            $seen = [];
            foreach ($file['orders'] as $index => $data) {
                $code = $this->code($data);
                if ($code === '') {
                    $summary['errors'][] = ['row' => $index + 1, 'message' => 'Đơn không có mã.'];

                    continue;
                }
                $reference = trim((string) ($data['reference'] ?? ''));
                if ($reference !== '' && isset($synced[$reference]) && ! isset($existing[$code])) {
                    $summary['skipped_synced']++;

                    continue;
                }

                $placed = $this->date($data['created_on'] ?? null) ?? now();
                $summary['from'] = $summary['from'] === null || $placed->lt($summary['from']) ? $placed : $summary['from'];
                $summary['to'] = $summary['to'] === null || $placed->gt($summary['to']) ? $placed : $summary['to'];
                $state = $this->status($data, $placed);
                if ($state === null) {
                    $summary['skipped_open']++;

                    continue;
                }
                [$status] = $state;
                if (in_array($status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true)) {
                    $summary['revenue'] = bcadd($summary['revenue'], (string) (float) ($data['total'] ?? 0), 2);
                }
                $channel = $this->channelName($data);
                $summary['channels'][$channel] = ($summary['channels'][$channel] ?? 0) + 1;

                foreach ((array) ($data['lines'] ?? []) as $line) {
                    $summary['lines']++;
                    if (empty($line['service']) && $this->variant($line) === null) {
                        $summary['unmatched_lines']++;
                        $key = trim((string) ($line['sku'] ?? '')) ?: trim((string) ($line['name'] ?? ''));
                        $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
                    }
                }

                $customer = $this->findCustomer((array) ($data['customer'] ?? []));
                $buyer = (array) ($data['customer'] ?? []);
                // Customers are counted once each, however many orders they have.
                $who = $customer !== null ? 'c'.$customer->id : 's'.($buyer['id'] ?? $buyer['code'] ?? $buyer['phone'] ?? '');
                if ($customer === null && ! (filled($buyer['name'] ?? null) && (filled($buyer['phone'] ?? null) || filled($buyer['id'] ?? null)))) {
                    $summary['walk_in']++;
                } elseif (! isset($seen[$who])) {
                    $seen[$who] = true;
                    $customer !== null ? $summary['customers_found']++ : $summary['customers_new']++;
                }

                if (isset($existing[$code])) {
                    $summary['updated']++;
                    if (! $dryRun) {
                        $this->refresh($existing[$code], $data);
                    }

                    continue;
                }
                $summary['created']++;
                if (! $dryRun) {
                    $this->create($data, $code, $customer);
                }
            }

            $summary['returns'] = $this->returns($file['returns'], $dryRun);
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        arsort($unmatched);
        $summary['unmatched'] = array_map(fn ($name, $count) => ['name' => (string) $name, 'lines' => $count], array_keys(array_slice($unmatched, 0, self::UNMATCHED_SHOWN, true)), array_slice($unmatched, 0, self::UNMATCHED_SHOWN, true));
        $summary['unmatched_skus'] = count($unmatched);
        $summary['from'] = $summary['from']?->toDateString();
        $summary['to'] = $summary['to']?->toDateString();

        return $summary;
    }

    /** @param  array<string, mixed>  $data */
    private function create(array $data, string $code, ?Customer $customer): void
    {
        $placed = $this->date($data['created_on'] ?? null) ?? now();
        [$status, $dates] = $this->status($data, $placed) ?? [OrderStatus::DELIVERED, []];
        $buyer = (array) ($data['customer'] ?? []);
        $customer ??= $this->newCustomer($buyer, $data);
        $phone = PhoneNumber::normalize($buyer['phone'] ?? null);
        $channel = $this->channel($this->channelName($data));

        $order = new Order;
        $order->forceFill([
            'marketplace' => Order::SOURCE_MANUAL,
            'external_order_id' => $code,
            // Sapo's number is the order's Mã đơn (SON50891), unless the shop's own numbering already gave it out.
            'code' => strlen($code) <= 30 && OrderCodes::isFree((int) app(TenantContext::class)->id(), $code) ? $code : null,
            'reference' => ($ref = trim((string) ($data['reference'] ?? ''))) !== '' ? mb_substr($ref, 0, 100) : null,
            'imported_from' => self::SOURCE,
            'historical' => true,
            'sales_channel_id' => $channel->id,
            'warehouse_id' => $this->warehouse($data['location'] ?? null),
            'customer_id' => $customer?->id,
            'buyer_name' => $buyer['name'] ?? $customer?->name,
            'buyer_phone' => $phone,
            'buyer_phone_masked' => OrderIngestor::maskPhone($phone),
            'buyer_phone_hash' => PhoneNumber::hash($phone),
            'shipping_address' => $data['address'] ?? null,
            'buyer_note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 500) : null,
            'shipping_carrier' => isset($data['carrier']) ? mb_substr((string) $data['carrier'], 0, 100) : null,
            'tracking_number' => isset($data['tracking']) ? mb_substr((string) $data['tracking'], 0, 100) : null,
            'is_cod' => (float) ($data['cod'] ?? 0) > 0,
            'shipping_fee' => $this->money($data['shipping_fee'] ?? 0),
            'discount' => $this->money($data['discount'] ?? 0),
            'tax_amount' => $this->money($data['tax'] ?? 0),
            'tax_rate' => 0,
            'prices_include_tax' => true,
            'tags' => array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) ($data['tags'] ?? [])))) ?: null,
            'placed_at' => $placed,
            'raw_status' => mb_substr((string) ($data['status'] ?? ''), 0, 64) ?: $status,
            'status' => $status,
            'version' => 0,
            'raw' => ['sapo' => array_filter([
                'id' => $data['id'] ?? null, 'reference' => $data['reference'] ?? null, 'source' => $data['source'] ?? null,
                'staff' => $data['staff'] ?? null, 'location' => $data['location'] ?? null,
            ], fn ($v) => $v !== null)],
        ] + $dates + $this->payment($data));
        $order->save();

        $subtotal = '0';
        foreach (array_values((array) ($data['lines'] ?? [])) as $i => $line) {
            $variant = empty($line['service']) ? $this->variant($line) : null;
            $quantity = max(0, (int) round((float) ($line['quantity'] ?? 0)));
            $price = $this->money($line['price'] ?? 0);
            $discount = $this->money($line['discount'] ?? 0);
            $cost = is_numeric($line['cost'] ?? null) ? (string) $line['cost'] : ($variant?->cost_price !== null ? (string) $variant->cost_price : null);
            (new OrderItem)->forceFill([
                'order_id' => $order->id,
                'external_item_id' => (string) ($line['id'] ?? ($i + 1)),
                'product_variant_id' => $variant?->id,
                'seller_sku' => isset($line['sku']) ? mb_substr((string) $line['sku'], 0, 100) : null,
                'name' => mb_substr(trim((string) ($line['name'] ?? '')) ?: ($variant?->product->name ?? 'Sản phẩm'), 0, 255),
                'variant_name' => isset($line['variant']) ? mb_substr((string) $line['variant'], 0, 255) : $variant?->name,
                'quantity' => $quantity,
                'unit_price' => $price,
                'discount' => $discount,
                'cost_snapshot' => $cost,
                'mapping_status' => ! empty($line['service']) ? OrderItem::SERVICE : ($variant ? 'mapped' : 'unmapped'),
            ])->save();
            $subtotal = bcadd($subtotal, bcsub(bcmul($price, (string) $quantity, 2), $discount, 2), 2);
        }

        // Sapo's own total is kept: it is what the buyer was asked to pay.
        $order->forceFill([
            'subtotal' => $subtotal,
            'total' => is_numeric($data['total'] ?? null) ? $this->money($data['total']) : bcsub(bcadd($subtotal, (string) $order->shipping_fee, 2), (string) $order->discount, 2),
        ])->save();

        OrderStatusHistory::query()->create([
            'order_id' => $order->id, 'from_status' => null, 'to_status' => $status, 'raw_status' => $order->raw_status,
            'source' => 'import', 'actor_id' => Auth::id(), 'note' => 'Lấy từ Sapo', 'occurred_at' => $dates['completed_at'] ?? $dates['delivered_at'] ?? $dates['cancelled_at'] ?? $placed,
        ]);
    }

    /** A Sapo order sent again: its status and payment may have moved on since the first file. */
    private function refresh(Order $order, array $data): void
    {
        $placed = $this->date($data['created_on'] ?? null) ?? now();
        $state = $this->status($data, $placed);
        if ($state === null) {
            return;
        }
        [$status, $dates] = $state;
        $order->forceFill(['status' => $status, 'raw_status' => mb_substr((string) ($data['status'] ?? ''), 0, 64) ?: $status] + $dates + $this->payment($data))->save();
    }

    /**
     * Returns of the imported orders, as closed returns that never move stock (Sapo already took the goods back).
     *
     * @param  list<array<string, mixed>>  $returns
     */
    private function returns(array $returns, bool $dryRun): int
    {
        $count = 0;
        foreach ($returns as $data) {
            $code = trim((string) ($data['order_code'] ?? ''));
            if (($data['status'] ?? '') === 'cancelled') {
                continue;
            }
            // A check runs before any file is imported, and returns come with the last file: count them all.
            if ($dryRun) {
                $count++;

                continue;
            }
            $order = $code !== '' ? Order::query()->where('imported_from', self::SOURCE)->where('external_order_id', $code)->first()
                : Order::query()->where('imported_from', self::SOURCE)->where('raw->sapo->id', $data['order_id'] ?? 0)->first();
            if ($order === null) {
                continue;
            }
            $key = 'sapo:'.($data['id'] ?? $data['code'] ?? '');
            if (OrderReturn::query()->where('order_id', $order->id)->where('source_key', $key)->exists()) {
                continue;
            }
            $count++;

            $at = $this->date($data['received_on'] ?? null) ?? $this->date($data['created_on'] ?? null) ?? now();
            $return = new OrderReturn;
            $return->forceFill([
                'order_id' => $order->id, 'number' => DocumentNumber::next('RET'), 'type' => OrderReturn::TYPE_RETURN, 'status' => OrderReturn::CLOSED,
                'source_key' => $key, 'external_return_id' => isset($data['code']) ? mb_substr((string) $data['code'], 0, 64) : null,
                'reason' => isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 500) : null,
                'refund_amount' => is_numeric($data['refund'] ?? null) && (float) $data['refund'] > 0 ? $this->money($data['refund']) : null,
                'warehouse_id' => $order->warehouse_id, 'created_by' => Auth::id(), 'received_at' => $at, 'inspected_at' => $at, 'closed_at' => $at,
                'note' => 'Lấy từ Sapo',
            ])->save();
            $return->forceFill(['created_at' => $this->date($data['created_on'] ?? null) ?? $at])->save();

            $items = $order->items()->get();
            foreach ((array) ($data['lines'] ?? []) as $line) {
                $sku = mb_strtolower(trim((string) ($line['sku'] ?? '')));
                $item = $items->first(fn (OrderItem $i) => (string) $i->external_item_id === (string) ($line['line_id'] ?? '-'))
                    ?? $items->first(fn (OrderItem $i) => $sku !== '' && mb_strtolower((string) $i->seller_sku) === $sku);
                $quantity = (int) round((float) ($line['quantity'] ?? 0));
                if ($item === null || $quantity <= 0 || OrderReturnLine::query()->where('order_return_id', $return->id)->where('order_item_id', $item->id)->exists()) {
                    continue;
                }
                (new OrderReturnLine)->forceFill([
                    'order_return_id' => $return->id, 'order_item_id' => $item->id, 'product_variant_id' => $item->product_variant_id,
                    'quantity' => min($quantity, (int) $item->quantity), 'received_qty' => min($quantity, (int) $item->quantity), 'sellable_qty' => min($quantity, (int) $item->quantity),
                ])->save();
            }
        }

        return $count;
    }

    /**
     * Status of the order here and the dates that go with it, or null for an order still being handled in Sapo
     * (draft, not shipped yet, or shipped in the last days): it is left out and comes over once finished there.
     *
     * Sapo keeps a shipped order that was not paid in full "Đang giao dịch" for good (the buyer owes the rest), so a
     * shipped order older than RECENT_DAYS counts as delivered.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: array<string, Carbon|null>}|null
     */
    private function status(array $data, Carbon $placed): ?array
    {
        $completed = $this->date($data['completed_on'] ?? null);
        $delivered = $this->date($data['delivered_on'] ?? null);
        $fulfilment = mb_strtolower((string) ($data['fulfillment_status'] ?? ''));
        $returned = mb_strtolower((string) ($data['return_status'] ?? '')) === 'returned';
        $shipped = in_array($fulfilment, ['shipped', 'partial', 'shipping', 'received', 'delivered'], true);

        return match (mb_strtolower((string) ($data['status'] ?? ''))) {
            'cancelled' => [OrderStatus::CANCELLED, ['cancelled_at' => $this->date($data['cancelled_on'] ?? null) ?? $placed]],
            'completed' => [$returned ? OrderStatus::RETURNED : OrderStatus::COMPLETED, [
                'handed_over_at' => $delivered ?? $completed ?? $placed, 'delivered_at' => $delivered ?? $completed ?? $placed, 'completed_at' => $completed ?? $delivered ?? $placed,
            ]],
            'draft' => null,
            default => $delivered !== null || ($shipped && $placed->lt(now()->subDays(self::RECENT_DAYS)))
                ? [$returned ? OrderStatus::RETURNED : OrderStatus::DELIVERED, ['handed_over_at' => $delivered ?? $placed, 'delivered_at' => $delivered ?? $placed]]
                : null,
        };
    }

    /** @return array{paid_amount: string, refunded_amount: string, payment_status: string, paid_at: Carbon|null} */
    private function payment(array $data): array
    {
        $total = (float) ($data['total'] ?? 0);
        $paid = max(0.0, (float) ($data['paid'] ?? 0));
        $sapo = mb_strtolower((string) ($data['payment_status'] ?? ''));
        if ($sapo === 'paid' && $paid <= 0) {
            $paid = $total;
        }
        $state = $paid <= 0 ? Order::PAYMENT_UNPAID : ($paid + 0.005 >= $total ? Order::PAYMENT_PAID : Order::PAYMENT_PARTIAL);

        return [
            'paid_amount' => $this->money($paid), 'refunded_amount' => '0.00', 'payment_status' => $state,
            'paid_at' => $state === Order::PAYMENT_PAID ? ($this->date($data['completed_on'] ?? null) ?? $this->date($data['created_on'] ?? null)) : null,
        ];
    }

    private function findCustomer(array $buyer): ?Customer
    {
        if (filled($buyer['code'] ?? null) && ($found = Customer::query()->where('code', mb_substr((string) $buyer['code'], 0, 32))->first())) {
            return $found;
        }
        $hash = PhoneNumber::hash(PhoneNumber::normalize($buyer['phone'] ?? null));

        return $hash !== null ? Customer::query()->where('phone_hash', $hash)->first() : null;
    }

    private function newCustomer(array $buyer, array $data): ?Customer
    {
        if (blank($buyer['name'] ?? null) || (blank($buyer['phone'] ?? null) && blank($buyer['id'] ?? null))) {
            return null; // khách lẻ: the order keeps the name it has, no card is made
        }

        return $this->customers->resolve(
            ['name' => $buyer['name'], 'phone' => $buyer['phone'] ?? null, 'email' => $buyer['email'] ?? null, 'address' => $data['address'] ?? null],
            filled($buyer['id'] ?? null) ? ['source' => self::SOURCE, 'external_id' => (string) $buyer['id'], 'label' => 'Sapo'] : null,
        );
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
                $this->variants[$key] = ProductVariant::query()->with('product')->where($field, $value)->first()
                    ?? ($tidy !== $value ? ProductVariant::query()->with('product')->where($field, $tidy)->first() : null);
            }
            if ($this->variants[$key] !== null) {
                return $this->variants[$key];
            }
        }

        return null;
    }

    private function channelName(array $data): string
    {
        return mb_substr(trim((string) ($data['source'] ?? '')), 0, 100) ?: SalesChannel::DEFAULT_NAME;
    }

    /** The shop's channel with the Sapo source's name, made (as type "other", "pos" for the counter) when missing. */
    private function channel(string $name): SalesChannel
    {
        if (isset($this->channels[$name])) {
            return $this->channels[$name];
        }
        $channel = SalesChannel::query()->where('name', $name)->first();
        if ($channel === null) {
            $lower = mb_strtolower($name);
            $channel = new SalesChannel;
            $channel->forceFill([
                'name' => $name,
                'type' => match (true) {
                    str_contains($lower, 'pos') || str_contains($lower, 'cửa hàng') || str_contains($lower, 'quầy') => 'pos',
                    str_contains($lower, 'facebook') => 'facebook',
                    str_contains($lower, 'zalo') => 'zalo',
                    str_contains($lower, 'web') => 'website',
                    str_contains($lower, 'điện thoại') || str_contains($lower, 'phone') => 'phone',
                    default => 'other',
                },
                // Made only to file old orders: it does not appear when creating a new order until switched on.
                'is_active' => false,
            ])->save();
        }

        return $this->channels[$name] = $channel;
    }

    private function warehouse(mixed $location): ?int
    {
        $name = trim((string) $location);
        if (! array_key_exists($name, $this->warehouses)) {
            $this->warehouses[$name] = ($name !== '' ? Warehouse::query()->where('name', $name)->value('id') : null)
                ?? Warehouse::query()->where('is_default', true)->value('id');
        }

        return $this->warehouses[$name];
    }

    private function code(array $data): string
    {
        return mb_substr(trim((string) ($data['code'] ?? '')), 0, 64);
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
