<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Marketplace\Events\ListingMappingChanged;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderStockSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * When a listing gets linked to an internal SKU, recent orders that contain it without a SKU are linked too and
 * their stock policy is applied (an open order then holds stock; a shipped one is issued from stock).
 */
class RemapOrderItems
{
    private const LOOKBACK_DAYS = 30;

    public function __construct(private readonly OrderStockSync $stock) {}

    public function handle(ListingMappingChanged $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);
        if (! $tenant) {
            return;
        }

        TenantContext::run($tenant, function () use ($event) {
            $listings = ListingVariant::query()->whereIn('id', $event->listingVariantIds)
                ->where('mapping_status', ListingVariant::MAPPED)->pluck('product_variant_id', 'id');

            $orderIds = OrderItem::query()->whereIn('listing_variant_id', $listings->keys())->whereNull('product_variant_id')
                ->whereIn('order_id', Order::query()->where('status', '!=', OrderStatus::CANCELLED)
                    ->where('placed_at', '>=', now()->subDays(self::LOOKBACK_DAYS))->select('id'))
                ->distinct()->pluck('order_id');

            foreach ($orderIds as $orderId) {
                DB::transaction(function () use ($orderId, $listings) {
                    $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
                    foreach ($order->items()->whereNull('product_variant_id')->whereIn('listing_variant_id', $listings->keys())->get() as $item) {
                        $item->forceFill(['product_variant_id' => $listings[$item->listing_variant_id], 'mapping_status' => ListingVariant::MAPPED])->save();
                    }
                    $this->stock->apply($order);
                }, 3);
            }
        });
    }
}
