<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\MarketplaceShipping;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Asks the marketplace once how an order of this kiểu giao can be handed over (Pickup, Drop off) and keeps the answer
 * on its shop, so the order list can show "Pickup" / "Drop off · Pickup" before "Chuẩn bị hàng" (like Shopee's own
 * list). One call per shop and kiểu giao, refreshed after a few days (MarketplaceShipping::methodsFor).
 */
class LearnShipMethodsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function handle(MarketplaceShipping $shipping): void
    {
        $order = Order::query()->withoutGlobalScopes()->find($this->orderId);
        $tenant = $order ? Tenant::query()->find($order->tenant_id) : null;
        if (! $order || ! $tenant || $order->status !== OrderStatus::READY_TO_PACK || $order->underMarketplaceReview()) {
            return;
        }
        TenantContext::run($tenant, function () use ($shipping) {
            $order = Order::query()->with('account')->find($this->orderId);
            $connector = $order ? $shipping->connector($order) : null;
            if (! $order || ! $connector) {
                return;
            }
            try {
                $shipping->rememberMethods($order, $connector->shipOptions($order->account, $order->external_order_id));
            } catch (ConnectorException) {
                // Often already prepared on the marketplace: nothing to learn from this order.
            }
        });
    }
}
