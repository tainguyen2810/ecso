<?php

namespace App\Modules\Orders\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Services\ComboResolver;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnLine;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Sales\Models\OrderPayment;
use App\Modules\Sales\Services\PaymentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns and refunds (ORDER-LIFECYCLE §5). Rules:
 *  - a refund never adds stock;
 *  - stock comes back only at inspection, for the quantity inspected as sellable, into the chosen warehouse,
 *    through InventoryService (RETURN_RESTOCK, idempotency key per return).
 */
class ReturnService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReservationLedger $ledger,
        private readonly AuditLogger $audit,
        private readonly ComboResolver $combos,
        private readonly WarehouseService $warehouses,
        private readonly ShipmentSync $shipments,
    ) {}

    /**
     * Opens (once) the return for goods that left the warehouse and are coming back: cancel after handover or
     * failed delivery. Runs inside the order transaction.
     */
    public function openAutomatic(Order $order, string $type, string $sourceKey, string $reason): OrderReturn
    {
        $existing = OrderReturn::query()->where('order_id', $order->id)->where('source_key', $sourceKey)->first();
        if ($existing) {
            // Failed again after a redelivery: the parcel that was withdrawn is on its way back once more.
            if ($existing->status === OrderReturn::REJECTED && $existing->received_at === null) {
                $existing->forceFill(['status' => OrderReturn::IN_TRANSIT, 'closed_at' => null, 'reason' => $reason])->save();
            }

            return $existing;
        }

        $items = $order->items()->get();
        $shipped = $this->ledger->snapshot(Order::STOCK_REFERENCE, $items->pluck('id')->all());

        $lines = $items->map(fn (OrderItem $item) => [
            'item' => $item,
            // Only what actually left the warehouse can come back into it.
            'quantity' => ($shipped[$item->id]['status'] ?? null) === 'consumed' ? $shipped[$item->id]['quantity'] : 0,
        ])->filter(fn ($l) => $l['quantity'] > 0)->values();

        return $this->persist($order, [
            'type' => $type,
            'status' => OrderReturn::IN_TRANSIT,
            'source_key' => $sourceKey,
            'reason' => $reason,
            'warehouse_id' => $order->warehouse_id,
        ], $lines->all());
    }

    /**
     * The parcel of a failed delivery went out again and was delivered (giao lại): the automatic return opened for it
     * is withdrawn, as long as nothing was received. Runs inside the order transaction.
     */
    public function withdrawAutomatic(Order $order, string $sourceKey, string $note): void
    {
        $return = OrderReturn::query()->where('order_id', $order->id)->where('source_key', $sourceKey)
            ->where('status', OrderReturn::IN_TRANSIT)->whereNull('received_at')->first();
        if ($return === null) {
            return;
        }
        $return->forceFill(['status' => OrderReturn::REJECTED, 'closed_at' => now(), 'note' => $note])->save();
        $this->audit->record('return.withdrawn', $return, ['number' => $return->number, 'order' => $order->external_order_id]);
    }

    /**
     * A return or refund recorded by a user (or later imported from the marketplace).
     *
     * @param  array{type: string, reason?: string|null, refund_amount?: string|float|null, lines?: array<int, array{order_item_id: int, quantity: int}>, source_key?: string|null}  $data
     */
    public function create(Order $order, array $data): OrderReturn
    {
        return DB::transaction(function () use ($order, $data) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $order->items()->get()->keyBy('id');
            $alreadyReturned = $this->returnedQuantities($order);

            $shipped = $this->shippedQuantities($order, $items->all());

            $lines = [];
            $seen = [];
            foreach ($data['lines'] ?? [] as $i => $line) {
                $item = $items[$line['order_item_id']] ?? throw ValidationException::withMessages(["lines.{$i}.order_item_id" => 'Sản phẩm không thuộc đơn này.']);
                if (isset($seen[$item->id])) {
                    throw ValidationException::withMessages(["lines.{$i}.order_item_id" => 'Một sản phẩm chỉ được chọn một lần trong phiếu trả.']);
                }
                $seen[$item->id] = true;

                // Only what actually left the warehouse can come back, minus what earlier returns already cover.
                $left = max(0, $shipped[$item->id] - (int) ($alreadyReturned[$item->id] ?? 0));
                if ($line['quantity'] > $left) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => $shipped[$item->id] === 0
                        ? 'Hàng của dòng này chưa xuất kho nên không thể trả. Hãy sửa hoặc hủy đơn.'
                        : "Chỉ còn {$left} sản phẩm có thể trả."]);
                }
                $lines[] = ['item' => $item, 'quantity' => (int) $line['quantity']];
            }

            if ($data['type'] !== OrderReturn::TYPE_REFUND_ONLY && $lines === []) {
                throw ValidationException::withMessages(['lines' => 'Chọn ít nhất một sản phẩm trả về.']);
            }

            $return = $this->persist($order, [
                'type' => $data['type'],
                'status' => OrderReturn::REQUESTED,
                'reason' => $data['reason'] ?? null,
                'refund_amount' => $data['refund_amount'] ?? null,
                'warehouse_id' => $order->warehouse_id,
                'created_by' => Auth::id(),
                'source_key' => $data['source_key'] ?? null,
            ], $data['type'] === OrderReturn::TYPE_REFUND_ONLY ? [] : $lines);

            $this->audit->record('return.created', $return, ['order' => $order->external_order_id, 'type' => $return->type]);

            return $return;
        });
    }

    /**
     * Pays money back to the buyer from a return of a manual order: a refund entry linked to the return, so the
     * return never pays back more than the goods are worth (less any exchange set-off and earlier refunds).
     * Marketplace orders are refunded by the marketplace, not here.
     *
     * @param  array{method: string, amount: string|int|float, reference?: string|null, note?: string|null}  $data
     */
    public function refund(OrderReturn $return, array $data): OrderReturn
    {
        return DB::transaction(function () use ($return, $data) {
            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $order = $return->order()->firstOrFail();
            if (! $order->isManual()) {
                throw ValidationException::withMessages(['order' => 'Đơn sàn do sàn hoàn tiền cho khách.']);
            }
            if ($return->status === OrderReturn::REJECTED) {
                throw ValidationException::withMessages(['status' => 'Phiếu trả đã bị từ chối.']);
            }

            $amount = bcadd((string) $data['amount'], '0', 2);
            $left = $return->refundable();
            if (bccomp($amount, $left, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Số tiền hoàn vượt quá số còn được hoàn của phiếu ('.number_format((float) $left, 0, ',', '.').' đ).']);
            }

            // PaymentService re-checks against what the buyer actually paid on the order.
            app(PaymentService::class)->record($order, [
                'kind' => OrderPayment::REFUND,
                'method' => $data['method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? $return->number,
                'note' => $data['note'] ?? "Hoàn tiền phiếu trả {$return->number}",
                'order_return_id' => $return->id,
            ]);
            $this->audit->record('return.refunded', $return, ['number' => $return->number, 'amount' => $amount, 'method' => $data['method']]);

            return $return->refresh();
        });
    }

    /**
     * "Hủy hóa đơn": undoes a completed sale of the seller's own channel in one step, for a sale rung up by mistake.
     * Nothing is deleted: a return of everything still returnable is recorded and inspected (all goods back on the
     * shelf of the order's warehouse) and what the buyer paid is refunded in the methods they paid with. Any part they
     * still owed stays on the return, which lowers their debt.
     */
    public function voidSale(Order $order, string $reason): OrderReturn
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isManual() || $order->status !== OrderStatus::COMPLETED) {
                throw ValidationException::withMessages(['order' => 'Chỉ hủy được hóa đơn bán đã hoàn tất của kênh bán riêng.']);
            }
            if (OrderReturn::query()->where('order_id', $order->id)->where('status', '!=', OrderReturn::REJECTED)->exists()) {
                throw ValidationException::withMessages(['order' => 'Đơn đã có phiếu trả hàng. Hãy xử lý tiếp trên phiếu trả.']);
            }
            $paid = OrderPayment::query()->where('order_id', $order->id)->whereNull('voided_at')->get();
            if ($paid->contains('method', OrderPayment::EXCHANGE)) {
                throw ValidationException::withMessages(['order' => 'Đơn đổi hàng: hãy làm phiếu trả hàng thay vì hủy hóa đơn.']);
            }

            $lines = collect($this->returnableQuantities($order))->filter(fn ($qty) => $qty > 0)
                ->map(fn ($qty, $id) => ['order_item_id' => (int) $id, 'quantity' => (int) $qty])->values()->all();
            if ($lines === []) {
                throw ValidationException::withMessages(['order' => 'Không còn hàng nào để trả lại kho.']);
            }

            $return = $this->create($order, ['type' => OrderReturn::TYPE_RETURN, 'reason' => "Hủy hóa đơn: {$reason}", 'lines' => $lines]);
            $this->move($return, OrderReturn::APPROVED);
            $this->move($return, OrderReturn::RECEIVED);
            $warehouse = Warehouse::query()->findOrFail($order->warehouse_id);
            $this->inspect($return, $warehouse, $return->lines()->get()->map(fn (OrderReturnLine $l) => [
                'line_id' => (int) $l->id, 'received_qty' => (int) $l->quantity, 'sellable_qty' => (int) $l->quantity, 'damaged_qty' => 0,
            ])->all(), "Hủy hóa đơn: {$reason}");

            // Pay back in each method the buyer used, never more than the return is worth.
            $left = $return->refresh()->refundable();
            $net = [];
            foreach ($paid as $p) {
                $net[$p->method] = bcadd($net[$p->method] ?? '0', $p->kind === OrderPayment::REFUND ? bcmul((string) $p->amount, '-1', 2) : (string) $p->amount, 2);
            }
            foreach ($net as $method => $amount) {
                $amount = bccomp($amount, $left, 2) > 0 ? $left : $amount;
                if (bccomp($amount, '0', 2) <= 0) {
                    continue;
                }
                $this->refund($return, ['method' => $method, 'amount' => $amount, 'note' => "Hủy hóa đơn {$order->displayCode()}"]);
                $left = bcsub($left, $amount, 2);
            }
            $this->move($return->refresh(), OrderReturn::CLOSED);

            $this->audit->record('order.sale_voided', $order, ['order' => $order->external_order_id, 'reason' => $reason, 'return' => $return->number]);

            return $return->refresh();
        });
    }

    /**
     * How much of what left the warehouse has come back on returns: none, partial or full. Rejected returns do not
     * count; a return counts from when it is recorded.
     */
    public function returnState(Order $order): string
    {
        $items = $order->items()->get();
        $shipped = array_sum($this->shippedQuantities($order, $items->all()));
        $returned = (int) $this->returnedQuantities($order)->sum();

        return match (true) {
            $returned <= 0 => 'none',
            $shipped > 0 && $returned >= $shipped => 'full',
            default => 'partial',
        };
    }

    public function move(OrderReturn $return, string $to, ?string $note = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $to, $note) {
            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! in_array($to, OrderReturn::FLOW[$return->status] ?? [], true) || $to === OrderReturn::INSPECTED) {
                throw ValidationException::withMessages(['status' => 'Không thể chuyển phiếu trả hàng sang trạng thái này.']);
            }
            if (! $return->expectsGoods() && in_array($to, [OrderReturn::IN_TRANSIT, OrderReturn::RECEIVED], true)) {
                throw ValidationException::withMessages(['status' => 'Hoàn tiền không kèm trả hàng: không có hàng để nhận.']);
            }
            if ($return->expectsGoods() && $to === OrderReturn::CLOSED && $return->inspected_at === null) {
                throw ValidationException::withMessages(['status' => 'Cần kiểm hàng trước khi đóng phiếu.']);
            }

            $return->forceFill([
                'status' => $to,
                'received_at' => $to === OrderReturn::RECEIVED ? now() : $return->received_at,
                'closed_at' => $to === OrderReturn::CLOSED ? now() : $return->closed_at,
                'note' => $note ?? $return->note,
            ])->save();

            // A refund-only request goes straight from approved to closed: there is nothing to receive.
            $this->audit->record('return.'.$to, $return, ['number' => $return->number]);

            return $return;
        });
    }

    /**
     * Records what came back and adds the sellable quantity to stock.
     *
     * @param  array<int, array{line_id: int, received_qty: int, sellable_qty: int, damaged_qty: int}>  $lines
     */
    public function inspect(OrderReturn $return, Warehouse $warehouse, array $lines, ?string $note = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $warehouse, $lines, $note) {
            $return = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (! $return->expectsGoods() || $return->status !== OrderReturn::RECEIVED) {
                throw ValidationException::withMessages(['status' => 'Chỉ kiểm được phiếu đã nhận hàng.']);
            }

            $byId = $return->lines()->get()->keyBy('id');
            $restock = [];
            $damaged = [];
            $seen = [];
            foreach ($lines as $i => $input) {
                $line = $byId[$input['line_id']] ?? throw ValidationException::withMessages(["lines.{$i}.line_id" => 'Dòng không thuộc phiếu này.']);
                if (isset($seen[$line->id])) {
                    throw ValidationException::withMessages(["lines.{$i}.line_id" => 'Mỗi dòng của phiếu chỉ được kiểm một lần.']);
                }
                $seen[$line->id] = true;
                if ($input['received_qty'] > $line->quantity) {
                    throw ValidationException::withMessages(["lines.{$i}.received_qty" => "Không được nhận quá {$line->quantity}."]);
                }
                if ($input['received_qty'] !== $input['sellable_qty'] + $input['damaged_qty']) {
                    throw ValidationException::withMessages(["lines.{$i}.sellable_qty" => 'Bán được + hỏng phải bằng số đã nhận.']);
                }
                if ($input['sellable_qty'] > 0 && $line->product_variant_id === null) {
                    throw ValidationException::withMessages(["lines.{$i}.sellable_qty" => 'Sản phẩm chưa liên kết SKU nội bộ, không thể nhập kho.']);
                }

                $line->forceFill(['received_qty' => $input['received_qty'], 'sellable_qty' => $input['sellable_qty'], 'damaged_qty' => $input['damaged_qty']])->save();
                if ($input['sellable_qty'] > 0) {
                    $restock[] = ['variant_id' => $line->product_variant_id, 'quantity' => $input['sellable_qty'], 'line_id' => $line->id];
                }
                // Damaged goods are kept on the books in the defective-goods warehouse, never in selling stock.
                if ($input['damaged_qty'] > 0 && $line->product_variant_id !== null) {
                    $damaged[] = ['variant_id' => $line->product_variant_id, 'quantity' => $input['damaged_qty'], 'line_id' => $line->id];
                }
            }

            // Goods go back into the lots they were shipped from (lot-tracked SKUs).
            $shippedLots = $this->ledger->shippedLots(Order::STOCK_REFERENCE, $byId->pluck('order_item_id')->filter()->map(fn ($id) => (int) $id)->values()->all());

            if ($restock !== []) {
                // A returned combo goes back into stock as its components.
                $restock = $this->withLots($this->withSoldCost($this->combos->expand($restock), $restock, $byId), $shippedLots);
                $this->inventory->restockReturn($warehouse, $restock, "restock:return:{$return->id}", $return, "Hàng hoàn {$return->number}");
            }

            $defective = null;
            if ($damaged !== []) {
                $defective = $this->warehouses->defective();
                $damagedLines = $this->withLots($this->withSoldCost($this->combos->expand($damaged), $damaged, $byId), $shippedLots);
                $this->inventory->restockReturn($defective, $damagedLines, "damaged:return:{$return->id}", $return, "Hàng lỗi từ phiếu trả {$return->number}");
            }

            $return->forceFill([
                'status' => OrderReturn::INSPECTED,
                'warehouse_id' => $warehouse->id,
                'damaged_warehouse_id' => $defective?->id,
                'inspected_at' => now(),
                'inspected_by' => Auth::id(),
                'note' => $note ?? $return->note,
            ])->save();

            $this->audit->record('return.inspected', $return, ['number' => $return->number, 'restocked' => array_sum(array_column($restock, 'quantity')), 'damaged' => array_sum(array_column($damaged, 'quantity'))]);
            $this->closeFailedDelivery($return);

            return $return;
        });
    }

    /**
     * A failed delivery of the shop's own order ends when its parcel is back and inspected: the order becomes "Đã hoàn".
     * Marketplace orders are left alone (the marketplace owns their status).
     */
    private function closeFailedDelivery(OrderReturn $return): void
    {
        if ($return->type !== OrderReturn::TYPE_FAILED_DELIVERY) {
            return;
        }
        $order = Order::query()->whereKey($return->order_id)->lockForUpdate()->first();
        if ($order === null || ! $order->isManual() || $order->status !== OrderStatus::FAILED_DELIVERY) {
            return;
        }
        $order->forceFill(['status' => OrderStatus::RETURNED, 'raw_status' => OrderStatus::RETURNED, 'external_updated_at' => now(), 'version' => $order->version + 1])->save();
        OrderStatusHistory::query()->create([
            'order_id' => $order->id, 'from_status' => OrderStatus::FAILED_DELIVERY, 'to_status' => OrderStatus::RETURNED,
            'raw_status' => OrderStatus::RETURNED, 'source' => 'user', 'actor_id' => Auth::id(), 'occurred_at' => now(),
            'note' => "Đã nhận và kiểm hàng hoàn {$return->number}",
        ]);
        $this->shipments->apply($order);
    }

    /**
     * Restocked goods carry the cost they were sold at (the order line's cost snapshot), so "giá vốn hàng trả lại"
     * matches the cost of goods sold. A SKU that also comes back as part of a combo, or was sold without a cost,
     * goes back at the current average.
     *
     * @param  array<int, array{variant_id: int, quantity: int}>  $expanded
     * @param  array<int, array{variant_id: int, quantity: int, line_id: int}>  $restock
     * @param  Collection<int, OrderReturnLine>  $lines
     * @return array<int, array{variant_id: int, quantity: int, unit_cost?: string}>
     */
    /**
     * Splits each line over the lots its SKU was shipped from; the pool is shared, so goods restocked and goods
     * sent to the defective warehouse do not count the same lot twice.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<int, array{lot_no: string, mfg_date: string|null, expiry_date: string|null, quantity: int}>>  $pool
     *
     * @param-out array<int, array<int, array{lot_no: string, mfg_date: string|null, expiry_date: string|null, quantity: int}>> $pool
     *
     * @return array<int, array<string, mixed>>
     */
    private function withLots(array $lines, array &$pool): array
    {
        foreach ($lines as $i => $line) {
            $remaining = (int) $line['quantity'];
            $variantId = (int) $line['variant_id'];
            $lots = [];
            foreach ($pool[$variantId] ?? [] as $k => $lot) {
                $take = min($lot['quantity'], $remaining);
                if ($take <= 0) {
                    continue;
                }
                $lots[] = ['quantity' => $take] + $lot;
                $pool[$variantId][$k] = ['quantity' => $lot['quantity'] - $take] + $lot;
                $remaining -= $take;
            }
            if ($lots !== []) {
                $lines[$i]['lots'] = $lots;
            }
        }

        return $lines;
    }

    private function withSoldCost(array $expanded, array $restock, $lines): array
    {
        $combos = $this->combos->componentsOf(array_column($restock, 'variant_id'));
        $items = OrderItem::query()->whereIn('id', $lines->pluck('order_item_id'))->pluck('cost_snapshot', 'id');
        $sold = [];
        foreach ($restock as $r) {
            $cost = $items[$lines[$r['line_id']]->order_item_id] ?? null;
            if (isset($combos[$r['variant_id']])) {
                continue;
            }
            $sold[$r['variant_id']] ??= ['qty' => 0, 'value' => '0', 'known' => true];
            $sold[$r['variant_id']]['qty'] += $r['quantity'];
            $sold[$r['variant_id']]['known'] = $sold[$r['variant_id']]['known'] && $cost !== null;
            $sold[$r['variant_id']]['value'] = bcadd($sold[$r['variant_id']]['value'], bcmul((string) ($cost ?? '0'), (string) $r['quantity'], 4), 4);
        }

        return array_map(function (array $line) use ($sold) {
            $s = $sold[$line['variant_id']] ?? null;
            if ($s && $s['known'] && $s['qty'] === $line['quantity']) {
                $line['unit_cost'] = bcdiv($s['value'], (string) $s['qty'], 4);
            }

            return $line;
        }, $expanded);
    }

    /**
     * What can still be returned per order item: shipped minus what earlier returns (not rejected) already cover.
     *
     * @return array<int, int> order item id => quantity
     */
    public function returnableQuantities(Order $order): array
    {
        $items = $order->items()->get();
        $shipped = $this->shippedQuantities($order, $items->all());
        $returned = $this->returnedQuantities($order);

        return $items->mapWithKeys(fn (OrderItem $i) => [$i->id => max(0, $shipped[$i->id] - (int) ($returned[$i->id] ?? 0))])->all();
    }

    /** @return Collection<int, int|string> order item id => quantity on returns that are not rejected */
    private function returnedQuantities(Order $order): Collection
    {
        return OrderReturnLine::query()
            ->whereIn('order_return_id', OrderReturn::query()->where('order_id', $order->id)->where('status', '!=', OrderReturn::REJECTED)->select('id'))
            ->selectRaw('order_item_id, SUM(quantity) as qty')->groupBy('order_item_id')->pluck('qty', 'order_item_id');
    }

    /**
     * Quantity of each order item that left the warehouse. A SKU line counts what its reservation shipped; a line with
     * no stock (service, or not linked to a SKU) counts its full quantity once the order has been handed over.
     *
     * @param  array<int, OrderItem>  $items
     * @return array<int, int> order item id => quantity
     */
    public function shippedQuantities(Order $order, array $items): array
    {
        $snapshot = $this->ledger->snapshot(Order::STOCK_REFERENCE, array_map(fn (OrderItem $i) => (int) $i->id, $items));
        $orderShipped = OrderStatus::isShipped((string) $order->status);

        $out = [];
        foreach ($items as $item) {
            $reservation = $snapshot[$item->id] ?? null;
            $out[$item->id] = match (true) {
                $reservation !== null => $reservation['status'] === 'consumed' ? (int) $reservation['quantity'] : 0,
                $item->product_variant_id === null && $orderShipped => (int) $item->quantity,
                default => 0,
            };
        }

        return $out;
    }

    /**
     * Đợt 6: a return or refund request read from the marketplace. Creates the return once (or adopts the one opened
     * when the order turned "Đã hoàn"), then follows the marketplace's status forward. What happens in the warehouse
     * (received, inspected, closed) is never undone by the marketplace.
     *
     * @param  array{id: string, order_id: string, status: string, raw_status: string, goods: bool, reason: string|null, refund: float|null, tracking_number: string|null, respond_by: \DateTimeInterface|null, items: list<array{external_sku_id: string|null, seller_sku: string|null, name: string, quantity: int}>, images: list<string>}  $r
     * @return array{0: OrderReturn, 1: bool} the return, and whether it is new
     */
    public function syncMarketplace(Order $order, array $r): array
    {
        return DB::transaction(function () use ($order, $r) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $key = 'mp:'.$r['id'];
            $return = OrderReturn::query()->where('order_id', $order->id)->where(fn ($q) => $q->where('source_key', $key)->orWhere('external_return_id', $r['id']))->first()
                // The return opened when the order turned "Đã hoàn" is this request.
                ?? OrderReturn::query()->where('order_id', $order->id)->whereNull('external_return_id')->whereIn('source_key', ['returned', 'cancel-after-ship'])->first();
            $type = $r['goods'] ? OrderReturn::TYPE_RETURN : OrderReturn::TYPE_REFUND_ONLY;
            $isNew = $return === null;

            if ($isNew) {
                $items = $order->items()->get();
                $left = $this->returnableQuantities($order);
                $lines = [];
                foreach ($r['goods'] ? $r['items'] : [] as $want) {
                    $item = $items->first(fn (OrderItem $i) => ($want['external_sku_id'] !== null && $i->external_sku_id === $want['external_sku_id'])
                        || ($want['seller_sku'] !== null && strcasecmp((string) $i->seller_sku, $want['seller_sku']) === 0));
                    if ($item && ($left[$item->id] ?? 0) > 0) {
                        $qty = min($want['quantity'], $left[$item->id]);
                        $left[$item->id] -= $qty;
                        $lines[] = ['item' => $item, 'quantity' => $qty];
                    }
                }
                // The marketplace named no SKU we can match: everything that shipped may come back.
                if ($r['goods'] && $lines === []) {
                    foreach ($items as $item) {
                        if (($left[$item->id] ?? 0) > 0) {
                            $lines[] = ['item' => $item, 'quantity' => $left[$item->id]];
                        }
                    }
                }
                if ($r['goods'] && $lines === []) {
                    // Nothing left the warehouse: the marketplace's request is a refund for the shop's records only.
                    $type = OrderReturn::TYPE_REFUND_ONLY;
                }
                $return = $this->persist($order, [
                    'type' => $type,
                    'status' => OrderReturn::REQUESTED,
                    'source_key' => $key,
                    'warehouse_id' => $order->warehouse_id,
                ], $lines);
            }

            $status = $return->status;
            $physical = in_array($status, [OrderReturn::RECEIVED, OrderReturn::INSPECTED, OrderReturn::CLOSED], true);
            if (! $physical) {
                $status = match ($r['status']) {
                    'approved' => OrderReturn::APPROVED,
                    'shipping' => $return->expectsGoods() ? OrderReturn::IN_TRANSIT : OrderReturn::APPROVED,
                    // Refunded on the sàn: a refund-only request is done; goods may still be on their way back.
                    'refunded' => $return->expectsGoods() ? ($r['tracking_number'] !== null || $status === OrderReturn::IN_TRANSIT ? OrderReturn::IN_TRANSIT : OrderReturn::APPROVED) : OrderReturn::CLOSED,
                    'rejected', 'cancelled' => OrderReturn::REJECTED,
                    default => $status,
                };
                // Never back from in transit to approved.
                if ($return->status === OrderReturn::IN_TRANSIT && $status === OrderReturn::APPROVED) {
                    $status = OrderReturn::IN_TRANSIT;
                }
            }
            $return->forceFill([
                'status' => $status,
                'external_return_id' => $r['id'],
                'marketplace_status' => mb_substr($r['raw_status'], 0, 50),
                'reason' => $r['reason'] !== null ? mb_substr($r['reason'], 0, 500) : $return->reason,
                'refund_amount' => $r['refund'] ?? $return->refund_amount,
                'return_tracking_number' => $r['tracking_number'] ?? $return->return_tracking_number,
                'respond_by' => $status === OrderReturn::REQUESTED ? $r['respond_by'] : null,
                'images' => $r['images'] !== [] ? $r['images'] : $return->images,
                'closed_at' => in_array($status, [OrderReturn::CLOSED, OrderReturn::REJECTED], true) ? ($return->closed_at ?? now()) : $return->closed_at,
            ])->save();
            if ($isNew) {
                $this->audit->record('return.marketplace', $return, ['order' => $order->external_order_id, 'return' => $r['id']]);
            }

            return [$return, $isNew];
        });
    }

    /**
     * Đợt 6 "Quét hàng hoàn": the parcel's tracking number (or the order code) finds the return waiting for it, which
     * is marked received so it can be inspected right away.
     */
    public function receiveByCode(string $code): ?OrderReturn
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $open = [OrderReturn::REQUESTED, OrderReturn::APPROVED, OrderReturn::IN_TRANSIT, OrderReturn::RECEIVED];
        $return = OrderReturn::query()->whereIn('status', $open)->where('type', '!=', OrderReturn::TYPE_REFUND_ONLY)
            ->where(fn ($q) => $q->where('return_tracking_number', $code)
                ->orWhereHas('order', fn ($o) => $o->where('tracking_number', $code)->orWhere('code', $code)->orWhere('external_order_id', $code)))
            ->orderByRaw('CASE WHEN return_tracking_number = ? THEN 0 ELSE 1 END', [$code])->latest('id')->first();
        if ($return === null) {
            return null;
        }
        if (in_array($return->status, [OrderReturn::APPROVED, OrderReturn::IN_TRANSIT], true)) {
            $return = $this->move($return, OrderReturn::RECEIVED);
        } elseif ($return->status === OrderReturn::REQUESTED) {
            // The parcel is here before anyone answered the request: it is accepted by receiving it.
            $return = $this->move($this->move($return, OrderReturn::APPROVED), OrderReturn::RECEIVED);
        }

        return $return;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{item: OrderItem, quantity: int}>  $lines
     */
    private function persist(Order $order, array $attributes, array $lines): OrderReturn
    {
        $return = new OrderReturn;
        $return->forceFill([...$attributes, 'order_id' => $order->id, 'number' => DocumentNumber::next('RET')])->save();

        foreach ($lines as $line) {
            $return->lines()->create([
                'order_item_id' => $line['item']->id,
                'product_variant_id' => $line['item']->product_variant_id,
                'quantity' => $line['quantity'],
            ]);
        }

        return $return;
    }
}
