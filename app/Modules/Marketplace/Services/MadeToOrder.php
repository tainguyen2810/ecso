<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStockSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Hàng đặt": SKUs listed on the website at the supplier's price (a plugin on the website scrapes it and adds the
 * margin) and bought only after a customer orders. Marking a SKU:
 *   - price: the website holds it (Nguồn giá = Website), so ECSO and the marketplace fee table follow the website;
 *   - stock: never pushed to the website (its plugin says whether the supplier has it); marketplaces get "tồn ảo";
 *   - orders: short lines wait for goods ("Chờ nhập hàng"), not a shortage; low-stock alerts leave it out.
 */
class MadeToOrder
{
    public function __construct(private readonly InventorySyncPlanner $planner, private readonly OrderStockSync $orders) {}

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @return int SKUs changed
     */
    public function set(Collection $variants, string $sourcing, ?int $virtualStock = null): int
    {
        $changed = 0;
        foreach ($variants as $variant) {
            $toOrder = $sourcing === ProductVariant::SOURCING_ORDER;
            $attributes = ['sourcing' => $sourcing];
            if ($virtualStock !== null) {
                $attributes['virtual_stock'] = max(0, $virtualStock);
            }
            if ($toOrder) {
                $attributes['price_source'] = 'woo';
            } elseif ($variant->isMadeToOrder() && ($variant->price_source ?? 'auto') === 'woo') {
                // Back to stock: the usual rule (ECSO while in stock, the website when out of stock).
                $attributes['price_source'] = 'auto';
            }
            $variant->forceFill($attributes);
            if (! $variant->isDirty()) {
                continue;
            }
            // Saved one by one so each SKU re-evaluates who holds its price.
            $variant->save();
            $changed++;
        }

        $ids = $variants->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($changed > 0 && $ids !== []) {
            $this->afterChange($ids);
        }

        return $changed;
    }

    /** @param  array<int, int>  $ids */
    private function afterChange(array $ids): void
    {
        // What ECSO last pushed to a website no longer counts for "Hàng đặt" (no "Tồn lệch" from the plugin's stock).
        $websites = ListingVariant::query()->whereIn('product_variant_id', $ids)
            ->whereHas('account', fn ($q) => $q->where('marketplace', WooCommerceConnector::MARKETPLACE))->pluck('id');
        $toOrder = ProductVariant::madeToOrder($ids);
        ListingVariant::query()->whereIn('id', $websites)->whereIn('product_variant_id', array_keys($toOrder))->update(['last_pushed_qty' => null]);

        // Marketplaces get "tồn ảo"; SKUs back to stock push real stock again (websites included).
        $this->planner->plan($ids, force: true);

        // Open orders with these SKUs: "Thiếu hàng" ↔ "Chờ nhập hàng".
        Order::query()->whereIn('status', OrderStatus::OPEN)->where(fn ($q) => $q->where('has_shortage', true)->orWhere('awaiting_purchase', true))
            ->whereHas('items', fn ($q) => $q->whereIn('product_variant_id', $ids))
            ->each(fn (Order $order) => DB::transaction(fn () => $this->orders->refreshFlags($order)));
    }
}
