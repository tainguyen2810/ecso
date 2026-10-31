<?php

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;

/**
 * Applies the stock policy of an order's current status (ORDER-LIFECYCLE §3 step [6], §4).
 * Must run inside the transaction that holds the order row lock. Every step is idempotent, so applying the same
 * status twice, or a status reached by skipping steps, gives the same stock result.
 */
class OrderStockSync
{
    public function __construct(
        private readonly ReservationLedger $ledger,
        private readonly ReturnService $returns,
    ) {}

    public function apply(Order $order): void
    {
        $warehouse = $this->warehouse($order);
        $items = $order->items()->get();
        // Fixed order (by SKU) so two orders sharing SKUs lock their stock rows in the same sequence: no deadlock.
        $mapped = $items->whereNotNull('product_variant_id')->sortBy([['product_variant_id', 'asc'], ['id', 'asc']])->values();
        $ids = $mapped->pluck('id')->map(fn ($id) => (int) $id)->all();
        $policy = OrderStatus::policy($order->status);

        if ($warehouse && in_array($policy, [OrderStatus::RESERVE, OrderStatus::CONSUME], true)) {
            $this->holdAll($order, $mapped, $warehouse);
        }

        if ($policy === OrderStatus::CONSUME) {
            $this->consume($order, $ids);
        }

        if ($policy === OrderStatus::RELEASE) {
            $this->cancel($order, $ids);
        }

        if ($order->status === OrderStatus::FAILED_DELIVERY) {
            $this->returns->openAutomatic($order, OrderReturn::TYPE_FAILED_DELIVERY, 'failed-delivery', 'Giao hàng thất bại, hàng đang quay về');
        }

        $this->refreshFlags($order, $items);
    }

    /** Recomputes has_shortage / has_unmapped from the current reservations. */
    public function refreshFlags(Order $order, $items = null): void
    {
        $items ??= $order->items()->get();
        $snapshot = $this->ledger->snapshot(Order::STOCK_REFERENCE, $items->pluck('id')->all());
        $relevant = OrderStatus::policy($order->status) !== OrderStatus::RELEASE;

        $order->forceFill([
            'has_unmapped' => $items->contains(fn (OrderItem $i) => $i->product_variant_id === null),
            'has_shortage' => $relevant && collect($snapshot)->contains(fn ($r) => $r['shortage'] > 0 && $r['status'] !== 'released'),
        ])->save();
    }

    private function holdAll(Order $order, $mapped, Warehouse $warehouse): void
    {
        foreach ($mapped as $item) {
            $this->ledger->hold(Order::STOCK_REFERENCE, $item->id, $warehouse, $item->variant, $item->quantity);
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function consume(Order $order, array $ids): void
    {
        $shipped = $this->ledger->consumeAll(Order::STOCK_REFERENCE, $ids, "Đơn {$order->external_order_id}");

        foreach ($shipped as $itemId => $line) {
            if ($line['unit_cost'] !== null) {
                OrderItem::query()->whereKey($itemId)->whereNull('cost_snapshot')->update(['cost_snapshot' => $line['unit_cost']]);
            }
            if ($line['shortage'] > 0) {
                $order->sync_warning = 'Đơn đã bàn giao nhưng kho không đủ hàng cho một số sản phẩm; tồn kho không bị trừ âm, cần kiểm tra.';
            }
        }
    }

    /**
     * Cancel before shipping releases the reservations. Cancel after shipping does not add stock back: it opens
     * a return that adds stock only when the parcel is received and inspected (ORDER-LIFECYCLE §4).
     *
     * @param  array<int, int>  $ids
     */
    private function cancel(Order $order, array $ids): void
    {
        $snapshot = $this->ledger->snapshot(Order::STOCK_REFERENCE, $ids);

        if (collect($snapshot)->contains(fn ($r) => $r['status'] === 'consumed')) {
            $this->returns->openAutomatic($order, OrderReturn::TYPE_CANCEL_AFTER_SHIP, 'cancel-after-ship', 'Đơn bị hủy sau khi đã bàn giao vận chuyển');
        }

        $this->ledger->releaseAll(Order::STOCK_REFERENCE, $ids, "Hủy đơn {$order->external_order_id}");
    }

    private function warehouse(Order $order): ?Warehouse
    {
        if ($order->warehouse_id === null) {
            $order->warehouse_id = $order->account->warehouse_id
                ?? Warehouse::query()->where('is_default', true)->value('id')
                ?? Warehouse::query()->orderBy('id')->value('id');
            $order->save();
        }

        return $order->warehouse_id ? Warehouse::query()->withTrashed()->find($order->warehouse_id) : null;
    }
}
