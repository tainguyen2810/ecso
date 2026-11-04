<?php

namespace App\Modules\Sales\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Events\OrderImported;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\ExchangeService;
use App\Modules\Orders\Services\OrderIngestor;
use App\Modules\Orders\Services\OrderStockSync;
use App\Modules\Orders\Support\OrderTags;
use App\Modules\Sales\Models\CashShift;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders of the seller's own channels (shop counter, Facebook, Zalo, website...). ECSO owns these orders, so status
 * changes are applied here directly instead of going to a marketplace. Stock goes through the same engine as
 * marketplace orders (OrderStockSync): open statuses hold stock, shipped statuses consume it, cancel releases it.
 *
 * One rule differs from marketplace orders: goods never leave the warehouse on a manual order while stock is short
 * (a marketplace order already exists and must be followed; a manual one can wait for stock or be edited).
 */
class ManualOrderService
{
    /** action => [statuses it can start from, target status] */
    public const ACTIONS = [
        'confirm' => [[OrderStatus::PENDING_CONFIRMATION], OrderStatus::READY_TO_PACK],
        'pack' => [[OrderStatus::READY_TO_PACK], OrderStatus::PACKED],
        'handover' => [[OrderStatus::READY_TO_PACK, OrderStatus::PACKED], OrderStatus::HANDED_OVER],
        'deliver' => [[OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT], OrderStatus::DELIVERED],
        'complete' => [[OrderStatus::PENDING_CONFIRMATION, OrderStatus::READY_TO_PACK, OrderStatus::PACKED, OrderStatus::DELIVERED], OrderStatus::COMPLETED],
        'fail_delivery' => [[OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT], OrderStatus::FAILED_DELIVERY],
        'cancel' => [OrderStatus::OPEN, OrderStatus::CANCELLED],
    ];

    /** Statuses in which the lines, buyer and amounts can still be edited. */
    public const EDITABLE = [OrderStatus::PENDING_CONFIRMATION, OrderStatus::READY_TO_PACK];

    public function __construct(
        private readonly OrderStockSync $stock,
        private readonly ReservationLedger $ledger,
        private readonly CustomerService $customers,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
        private readonly ExchangeService $exchanges,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by SalesOrderController
     */
    public function create(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $channel = SalesChannel::query()->where('ulid', $data['channel_id'])->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['channel_id' => 'Kênh bán không tồn tại hoặc đã tắt.']);
            $warehouse = $this->warehouse($data['warehouse_id'] ?? null, $channel);
            $shift = $this->counterShift((bool) ($data['counter'] ?? false));
            $customer = $this->customer($data);
            $target = match ($data['stage'] ?? 'pending') {
                'confirmed' => OrderStatus::READY_TO_PACK,
                'completed' => OrderStatus::COMPLETED,
                default => OrderStatus::PENDING_CONFIRMATION,
            };

            $order = new Order;
            $number = DocumentNumber::next('SO');
            $order->forceFill([
                'marketplace' => Order::SOURCE_MANUAL,
                'external_order_id' => $number,
                'sales_channel_id' => $channel->id,
                'warehouse_id' => $warehouse->id,
                'cash_shift_id' => $shift?->id,
                // The tax rate in force when the order is made stays with the order.
                'tax_rate' => (float) TenantSettings::current()->get('tax.sale_rate'),
                'prices_include_tax' => (bool) TenantSettings::current()->get('tax.prices_include_tax'),
                'created_by' => Auth::id(),
                'assigned_to' => Auth::id(),
                'placed_at' => isset($data['placed_at']) ? now()->parse($data['placed_at']) : now(),
                'payment_status' => Order::PAYMENT_UNPAID,
                'paid_amount' => 0,
                'refunded_amount' => 0,
                'raw_status' => OrderStatus::PENDING_CONFIRMATION,
                'status' => OrderStatus::PENDING_CONFIRMATION,
            ]);
            $this->fillDetails($order, $data, $customer);
            $order->save();
            $this->replaceItems($order, $data['lines']);
            $this->refreshTotals($order);

            $this->history($order, null, OrderStatus::PENDING_CONFIRMATION, 'Tạo đơn');
            $this->stock->apply($order);

            if ($target !== OrderStatus::PENDING_CONFIRMATION) {
                $this->moveTo($order, $target);
            }

            // Exchange: the returned goods pay for (part of) this order before any money changes hands.
            if ($returnId = $data['exchange_return_id'] ?? null) {
                $return = OrderReturn::query()->where('ulid', $returnId)->first()
                    ?? throw ValidationException::withMessages(['exchange_return_id' => 'Phiếu trả hàng không tồn tại.']);
                $this->exchanges->attach($return, $order);
                $order->refresh();
            }

            // A walk-in buyer who takes the goods must pay in full: there is nobody to record the debt against.
            $paying = collect($data['payments'] ?? [])->reduce(fn (string $sum, $p) => bcadd($sum, (string) $p['amount'], 2), (string) $order->paid_amount);
            if ($target === OrderStatus::COMPLETED && $customer === null && bccomp($paying, (string) $order->total, 2) < 0) {
                throw ValidationException::withMessages(['payments' => 'Khách lẻ phải thanh toán đủ. Chọn khách hàng để ghi nợ.']);
            }

            foreach ($data['payments'] ?? [] as $payment) {
                if ((float) $payment['amount'] > 0) {
                    $this->payments->record($order, $payment);
                }
            }

            $tenantId = $order->tenant_id;
            $orderId = $order->id;
            DB::afterCommit(fn () => OrderImported::dispatch($tenantId, $orderId));
            $this->audit->record('order.created', $order, ['order' => $number, 'channel' => $channel->name, 'total' => $order->total]);

            return $order->refresh();
        });
    }

    /**
     * Edits an order that has not left the warehouse yet. Changing the lines releases every reservation and holds
     * stock again for the new lines.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $order = $this->lock($order);
            if (! in_array($order->status, self::EDITABLE, true)) {
                throw ValidationException::withMessages(['order' => 'Chỉ sửa được đơn chưa đóng gói.']);
            }
            // Two people editing the same order: the later save would silently overwrite the first one.
            if (isset($data['version']) && (int) $data['version'] !== (int) $order->version) {
                throw ValidationException::withMessages(['version' => 'Đơn vừa được người khác sửa hoặc đổi trạng thái. Tải lại trang để xem bản mới nhất rồi sửa lại.']);
            }

            $before = $this->editSnapshot($order);
            $previousCustomer = $order->customer_id !== null ? (int) $order->customer_id : null;
            $customer = array_key_exists('customer_id', $data) || isset($data['customer']) ? $this->customer($data, $previousCustomer) : $order->customer;
            $this->fillDetails($order, $data, $customer);
            if (($order->customer_id !== null ? (int) $order->customer_id : null) !== $previousCustomer) {
                // Payments follow the order to its new buyer, so their payment history and debt stay right.
                OrderPayment::query()->where('order_id', $order->id)->update(['customer_id' => $order->customer_id]);
            }

            if (isset($data['warehouse_id']) || isset($data['lines'])) {
                $ids = $order->items()->pluck('id')->map(fn ($id) => (int) $id)->all();
                $this->ledger->releaseAll(Order::STOCK_REFERENCE, $ids, "Sửa đơn {$order->external_order_id}");
                if (isset($data['warehouse_id'])) {
                    $order->warehouse_id = $this->warehouse($data['warehouse_id'], $order->channel)->id;
                }
                $this->replaceItems($order, $data['lines'] ?? $this->currentLines($order));
            }

            $order->version = $order->version + 1;
            $order->save();
            $this->refreshTotals($order);
            $this->stock->apply($order);
            $this->payments->recompute($order);

            if (bccomp((string) $order->paid_amount, (string) $order->total, 2) > 0) {
                $order->forceFill(['sync_warning' => 'Khách đã trả nhiều hơn tổng tiền mới của đơn: cần hoàn lại phần chênh lệch.'])->save();
            }

            $diff = $this->editDiff($before, $this->editSnapshot($order->refresh()));
            $this->audit->record('order.updated', $order, ['order' => $order->external_order_id, 'total' => $order->total, 'diff' => $diff]);

            return $order->refresh();
        });
    }

    /**
     * @param  array{reason?: string|null, carrier?: string|null, tracking_number?: string|null}  $params
     */
    public function transition(Order $order, string $action, array $params = []): Order
    {
        if (! isset(self::ACTIONS[$action])) {
            throw ValidationException::withMessages(['action' => 'Thao tác không hợp lệ.']);
        }

        return DB::transaction(function () use ($order, $action, $params) {
            $order = $this->lock($order);
            [$from, $to] = self::ACTIONS[$action];
            if (! in_array($order->status, $from, true)) {
                throw ValidationException::withMessages(['action' => 'Đơn đang ở trạng thái không cho phép thao tác này.']);
            }

            if ($action === 'handover') {
                $order->shipping_carrier = $params['carrier'] ?? $order->shipping_carrier;
                $order->tracking_number = $params['tracking_number'] ?? $order->tracking_number;
            }
            if ($action === 'cancel') {
                $order->cancel_reason = $params['reason'] ?? null;
            }

            $this->moveTo($order, $to, $params['reason'] ?? null);

            if ($action === 'cancel' && bccomp((string) $order->paid_amount, (string) $order->refunded_amount, 2) > 0) {
                $order->forceFill(['sync_warning' => 'Đơn đã hủy nhưng khách đã trả tiền: hãy ghi nhận hoàn tiền.'])->save();
            }

            $this->audit->record("order.{$action}", $order, ['order' => $order->external_order_id]);

            return $order->refresh();
        });
    }

    /** Actions available for the order in its current status. */
    public static function availableActions(Order $order): array
    {
        return collect(self::ACTIONS)->filter(fn ($def) => in_array($order->status, $def[0], true))->keys()->values()->all();
    }

    /** Must run inside the transaction holding the order lock. */
    private function moveTo(Order $order, string $to, ?string $note = null): void
    {
        $from = $order->status;
        if (! OrderStatus::canMove($from, $to)) {
            throw ValidationException::withMessages(['action' => 'Không thể chuyển đơn sang trạng thái này.']);
        }

        if (OrderStatus::isShipped($to) && ! OrderStatus::isShipped($from)) {
            $this->ensureStock($order);
        }

        $order->forceFill([
            'status' => $to,
            'raw_status' => $to,
            'external_updated_at' => now(),
            'handed_over_at' => OrderStatus::isShipped($to) ? ($order->handed_over_at ?? now()) : $order->handed_over_at,
            'delivered_at' => in_array($to, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true) ? ($order->delivered_at ?? now()) : $order->delivered_at,
            'cancelled_at' => $to === OrderStatus::CANCELLED ? now() : $order->cancelled_at,
            'sync_warning' => null,
            'version' => $order->version + 1,
        ])->save();

        $this->history($order, $from, $to, $note);
        $this->stock->apply($order);
    }

    /**
     * Before goods leave: holds stock (if not yet held), tops up short reservations with what is available now and
     * refuses when stock is still short.
     */
    private function ensureStock(Order $order): void
    {
        $all = $order->items()->get();
        if ($all->contains(fn (OrderItem $i) => $i->product_variant_id === null && $i->mapping_status !== OrderItem::SERVICE)) {
            throw ValidationException::withMessages(['action' => 'Đơn có sản phẩm chưa liên kết SKU.']);
        }
        // Service lines have no stock to hold.
        $items = $all->whereNotNull('product_variant_id')->values();

        $warehouse = Warehouse::query()->withTrashed()->findOrFail($order->warehouse_id);
        foreach ($items->sortBy('product_variant_id') as $item) {
            $this->ledger->hold(Order::STOCK_REFERENCE, $item->id, $warehouse, $item->variant, $item->quantity);
        }

        $short = [];
        foreach ($this->ledger->topUp(Order::STOCK_REFERENCE, $items->pluck('id')->map(fn ($id) => (int) $id)->all()) as $itemId => $shortage) {
            $item = $items->firstWhere('id', $itemId);
            $short[] = ($item->seller_sku ?? $item->name).": thiếu {$shortage}";
        }

        if ($short !== []) {
            throw ValidationException::withMessages(['stock' => 'Kho '.$warehouse->name.' không đủ hàng ('.implode('; ', $short).'). Nhập thêm hàng hoặc sửa số lượng trước khi xuất kho.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillDetails(Order $order, array $data, ?Customer $customer): void
    {
        $phone = array_key_exists('buyer_phone', $data) ? $data['buyer_phone'] : ($order->exists ? $order->buyer_phone : $customer?->phone);
        $name = array_key_exists('buyer_name', $data) ? $data['buyer_name'] : ($order->exists ? $order->buyer_name : $customer?->name);
        $address = array_key_exists('shipping_address', $data) ? $data['shipping_address'] : ($order->exists ? $order->shipping_address : $customer?->address);

        $order->forceFill([
            'customer_id' => $customer?->id,
            'buyer_name' => $name,
            'buyer_phone' => $phone,
            'buyer_phone_masked' => OrderIngestor::maskPhone($phone),
            'buyer_phone_hash' => PhoneNumber::hash(PhoneNumber::normalize($phone)),
            'shipping_address' => $address,
        ]);

        foreach (['shipping_fee', 'discount'] as $field) {
            if (array_key_exists($field, $data)) {
                $order->forceFill([$field => bcadd((string) ($data[$field] ?? 0), '0', 2)]);
            }
        }
        foreach (['note' => 'buyer_note', 'carrier' => 'shipping_carrier', 'tracking_number' => 'tracking_number', 'ship_by_at' => 'ship_by_at', 'deliver_on' => 'deliver_on'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $order->{$column} = $data[$input];
            }
        }
        if (array_key_exists('tags', $data)) {
            $order->tags = OrderTags::normalize($data['tags']);
        }
        if (array_key_exists('is_cod', $data)) {
            $order->is_cod = (bool) $data['is_cod'];
            $order->payment_method = $data['is_cod'] ? 'cod' : $order->payment_method;
        }
    }

    /**
     * @param  array<int, array{variant_id?: string|null, name?: string|null, quantity: int, unit_price?: string|int|float|null, discount?: string|int|float|null}>  $lines
     */
    private function replaceItems(Order $order, array $lines): void
    {
        $variants = ProductVariant::query()->with('product')->whereIn('ulid', collect($lines)->pluck('variant_id')->filter())->get()->keyBy('ulid');
        // A stopped SKU (Ngừng bán / Lưu trữ) cannot be added; lines already on the order may stay when it is edited.
        $already = $order->items()->whereNotNull('product_variant_id')->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all();

        $order->items()->delete();
        foreach (array_values($lines) as $i => $line) {
            // Service line (repair fee, installation…): own name and price, no SKU and no stock movement.
            if (empty($line['variant_id'])) {
                $price = bcadd((string) ($line['unit_price'] ?? 0), '0', 2);
                $discount = bcadd((string) ($line['discount'] ?? 0), '0', 2);
                if (bccomp($discount, bcmul($price, (string) $line['quantity'], 2), 2) > 0) {
                    throw ValidationException::withMessages(["lines.{$i}.discount" => 'Giảm giá lớn hơn thành tiền.']);
                }
                $item = new OrderItem;
                $item->forceFill([
                    'order_id' => $order->id,
                    'external_item_id' => (string) ($i + 1).'-'.$order->version,
                    'product_variant_id' => null,
                    'name' => trim((string) $line['name']),
                    'quantity' => (int) $line['quantity'],
                    'unit_price' => $price,
                    'discount' => $discount,
                    'mapping_status' => OrderItem::SERVICE,
                ])->save();

                continue;
            }

            $variant = $variants[$line['variant_id']] ?? throw ValidationException::withMessages(["lines.{$i}.variant_id" => 'SKU không tồn tại.']);
            if (! $variant->isSellable() && ! in_array($variant->id, $already, true)) {
                throw ValidationException::withMessages(["lines.{$i}.variant_id" => "SKU {$variant->sku} đang ngừng bán."]);
            }
            $price = bcadd((string) ($line['unit_price'] ?? $variant->ref_price), '0', 2);
            $discount = bcadd((string) ($line['discount'] ?? 0), '0', 2);
            if (bccomp($discount, bcmul($price, (string) $line['quantity'], 2), 2) > 0) {
                throw ValidationException::withMessages(["lines.{$i}.discount" => 'Giảm giá lớn hơn thành tiền.']);
            }

            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'external_item_id' => (string) ($i + 1).'-'.$order->version,
                'product_variant_id' => $variant->id,
                'seller_sku' => $variant->sku,
                'name' => $variant->product->name ?? $variant->sku,
                'variant_name' => $variant->name,
                'quantity' => (int) $line['quantity'],
                'unit_price' => $price,
                'discount' => $discount,
                'mapping_status' => 'mapped',
            ])->save();
        }
    }

    /** @return array<int, array{variant_id: string|null, name?: string, quantity: int, unit_price: string, discount: string}> */
    /**
     * What an edit can change, in words a seller reads: lines by SKU or name, buyer, delivery, money.
     *
     * @return array{lines: array<string, array{qty: int, price: string, discount: string}>, fields: array<string, string>}
     */
    private function editSnapshot(Order $order): array
    {
        $lines = [];
        foreach ($order->items()->with('variant')->get() as $i) {
            $key = $i->variant->sku ?? $i->name;
            $lines[$key] = ['qty' => (int) $i->quantity + (int) ($lines[$key]['qty'] ?? 0), 'price' => (string) $i->unit_price, 'discount' => (string) $i->discount];
        }

        return ['lines' => $lines, 'fields' => [
            'Khách hàng' => (string) ($order->customer->name ?? ''),
            'Người nhận' => (string) $order->buyer_name,
            'SĐT người nhận' => (string) $order->buyer_phone_masked,
            'Địa chỉ giao' => (string) $order->shipping_address,
            'Kho xuất' => (string) ($order->warehouse->name ?? ''),
            'Phí giao hàng' => (string) $order->shipping_fee,
            'Chiết khấu đơn' => (string) $order->discount,
            'Tổng tiền' => (string) $order->total,
        ]];
    }

    /**
     * Human-readable changes between two snapshots, e.g. "ATC-01: SL 1 → 2" or "Tổng tiền: 100.000 → 200.000".
     *
     * @param  array{lines: array<string, array{qty: int, price: string, discount: string}>, fields: array<string, string>}  $a
     * @param  array{lines: array<string, array{qty: int, price: string, discount: string}>, fields: array<string, string>}  $b
     * @return array<int, string>
     */
    private function editDiff(array $a, array $b): array
    {
        $money = fn (string $v) => is_numeric($v) ? number_format((float) $v, 0, ',', '.') : $v;
        $diff = [];
        foreach ($b['lines'] as $key => $line) {
            $old = $a['lines'][$key] ?? null;
            if ($old === null) {
                $diff[] = "Thêm {$key} × {$line['qty']}";

                continue;
            }
            if ($old['qty'] !== $line['qty']) {
                $diff[] = "{$key}: SL {$old['qty']} → {$line['qty']}";
            }
            if (bccomp($old['price'], $line['price'], 2) !== 0) {
                $diff[] = "{$key}: giá {$money($old['price'])} → {$money($line['price'])}";
            }
            if (bccomp($old['discount'], $line['discount'], 2) !== 0) {
                $diff[] = "{$key}: giảm {$money($old['discount'])} → {$money($line['discount'])}";
            }
        }
        foreach (array_diff_key($a['lines'], $b['lines']) as $key => $line) {
            $diff[] = "Bỏ {$key} × {$line['qty']}";
        }
        foreach ($b['fields'] as $label => $value) {
            $old = $a['fields'][$label] ?? '';
            $same = is_numeric($old) && is_numeric($value) ? bccomp($old, $value, 2) === 0 : $old === $value;
            if (! $same) {
                $diff[] = $label === 'Địa chỉ giao' ? 'Đổi địa chỉ giao' : "{$label}: ".($old === '' ? '(trống)' : $money($old)).' → '.($value === '' ? '(trống)' : $money($value));
            }
        }

        return $diff;
    }

    private function currentLines(Order $order): array
    {
        return $order->items()->with('variant')->get()->map(fn (OrderItem $i) => $i->variant === null
            ? ['variant_id' => null, 'name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => (string) $i->unit_price, 'discount' => (string) $i->discount]
            : ['variant_id' => $i->variant->ulid, 'quantity' => $i->quantity, 'unit_price' => (string) $i->unit_price, 'discount' => (string) $i->discount],
        )->all();
    }

    private function refreshTotals(Order $order): void
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $order->items()->get();
        $subtotal = $items->reduce(fn (string $sum, OrderItem $i) => bcadd($sum, bcsub(bcmul((string) $i->unit_price, (string) $i->quantity, 2), (string) $i->discount, 2), 2), '0');
        $total = bcsub(bcadd($subtotal, (string) $order->shipping_fee, 2), (string) $order->discount, 2);
        if (bccomp($total, '0', 2) < 0) {
            throw ValidationException::withMessages(['discount' => 'Giảm giá lớn hơn tổng tiền đơn.']);
        }

        // VAT on the goods after the order discount (not on shipping), at the rate the order was created with.
        $tax = self::saleTax(bcsub($subtotal, (string) $order->discount, 2), (string) $order->tax_rate, (bool) $order->prices_include_tax);
        if (! $order->prices_include_tax) {
            $total = bcadd($total, $tax, 2);
        }

        $order->forceFill(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $total])->save();
    }

    /**
     * Sale tax on an amount, rounded to the đồng. Prices that include tax hold it inside (amount × r / (100 + r));
     * prices before tax get it added on top (amount × r / 100). Mirrored by saleTax() in resources/js/shared/lib/tax.ts.
     */
    public static function saleTax(string $amount, string $rate, bool $included): string
    {
        if (bccomp($rate, '0', 2) <= 0 || bccomp($amount, '0', 2) <= 0) {
            return '0.00';
        }
        $tax = $included
            ? bcdiv(bcmul($amount, $rate, 8), bcadd('100', $rate, 8), 8)
            : bcdiv(bcmul($amount, $rate, 8), '100', 8);

        return number_format(round((float) $tax), 2, '.', '');
    }

    /**
     * The order's buyer. A customer who stopped trading cannot be put on an order (the one already on it can stay).
     *
     * @param  array<string, mixed>  $data
     */
    private function customer(array $data, ?int $current = null): ?Customer
    {
        $customer = null;
        if (! empty($data['customer_id'])) {
            $customer = Customer::query()->where('ulid', $data['customer_id'])->first()
                ?? throw ValidationException::withMessages(['customer_id' => 'Khách hàng không tồn tại.']);
        } elseif (! empty($data['customer']['name'])) {
            $type = empty($data['channel_id']) ? null : SalesChannel::query()->where('ulid', $data['channel_id'])->value('type');
            $customer = $this->customers->findOrCreate($data['customer'] + ['source' => $type]);
        }

        if ($customer !== null && $customer->status === 'inactive' && (int) $customer->id !== $current) {
            throw ValidationException::withMessages(['customer_id' => "Khách {$customer->code} · {$customer->name} đã ngừng giao dịch. Chuyển khách về \"Đang giao dịch\" trước khi tạo đơn."]);
        }

        return $customer;
    }

    /**
     * The seller's open shift, so the sale and its money count in that shift. A till sale (POS screen) is refused
     * without one when the shop requires shifts; an order typed in the back office never needs one.
     */
    private function counterShift(bool $atCounter): ?CashShift
    {
        if (! Auth::check()) {
            return null;
        }
        $shift = CashShift::query()->where('open_user_id', Auth::id())->first();
        if ($shift === null && $atCounter && TenantSettings::current()->get('sales.pos_require_shift')) {
            throw ValidationException::withMessages(['shift' => 'Chưa mở ca bán hàng. Mở ca và đếm tiền đầu ca trước khi bán tại quầy.']);
        }

        return $shift;
    }

    private function warehouse(?string $ulid, ?SalesChannel $channel): Warehouse
    {
        $warehouse = $ulid
            ? Warehouse::query()->where('ulid', $ulid)->first()
            : ($channel?->warehouse_id ? Warehouse::query()->find($channel->warehouse_id) : null)
                ?? Warehouse::query()->where('is_default', true)->first()
                ?? Warehouse::query()->orderBy('id')->first();

        if (! $warehouse || ! $warehouse->isActive()) {
            throw ValidationException::withMessages(['warehouse_id' => 'Kho xuất không tồn tại hoặc đã ngừng hoạt động.']);
        }

        return $warehouse;
    }

    private function lock(Order $order): Order
    {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isManual()) {
            throw ValidationException::withMessages(['order' => 'Đơn sàn được xử lý theo trạng thái của sàn.']);
        }

        return $locked;
    }

    private function history(Order $order, ?string $from, string $to, ?string $note): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'raw_status' => $to,
            'source' => 'user',
            'actor_id' => Auth::id(),
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }
}
