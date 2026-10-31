<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Inventory\Events\AvailableStockChanged;
use App\Modules\Inventory\Services\ReservationLedger;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderStockSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * When stock becomes available (receipt, cancel, return), tops up short reservations, oldest order first
 * (INVENTORY-ENGINE §5.3). Terminates: it only fills while available > 0.
 */
class FillOrderShortages
{
    private static bool $running = false;

    public function __construct(private readonly ReservationLedger $ledger, private readonly OrderStockSync $stock) {}

    public function handle(AvailableStockChanged $event): void
    {
        if (self::$running) {
            return; // the fills below fire the same event; one pass handles them
        }

        $tenant = Tenant::query()->find($event->tenantId);
        if (! $tenant) {
            return;
        }

        self::$running = true;
        try {
            TenantContext::run($tenant, function () use ($event) {
                $changed = $this->ledger->fillShortages(Order::STOCK_REFERENCE, $event->variantIds, fn (array $itemIds) => OrderItem::query()
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereIn('order_items.id', $itemIds)
                    ->orderBy('orders.placed_at')->orderBy('order_items.id')
                    ->pluck('order_items.id')->all());

                if ($changed !== []) {
                    $orders = Order::query()->whereIn('id', OrderItem::query()->whereIn('id', $changed)->select('order_id'))->get();
                    foreach ($orders as $order) {
                        $this->stock->refreshFlags($order);
                    }
                }
            });
        } finally {
            self::$running = false;
        }
    }
}
