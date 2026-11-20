<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ComboResolver;
use App\Modules\Inventory\Domain\AllocationCalculator;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Jobs\PushInventoryJob;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\SyncCorrelation;

/**
 * Steps [8] of INVENTORY-ENGINE §7: recompute the published quantity of every mapped listing of the changed SKUs
 * and write it to the outbox (one row per listing, latest value wins). Runs in the tenant context.
 */
class InventorySyncPlanner
{
    public function __construct(
        private readonly StockReader $stock,
        private readonly ComboResolver $combos,
        private readonly AllocationPolicy $allocations,
    ) {}

    /**
     * @param  array<int, int>  $variantIds
     * @return int rows queued
     */
    public function plan(array $variantIds, bool $force = false): int
    {
        // A combo listing follows its components' stock.
        $variantIds = array_values(array_unique([...$variantIds, ...$this->combos->combosContaining($variantIds)]));
        $listings = ListingVariant::query()->whereIn('product_variant_id', $variantIds)
            ->where('mapping_status', ListingVariant::MAPPED)->get()->groupBy('marketplace_account_id');

        $queued = 0;
        $correlation = SyncCorrelation::current();
        foreach ($listings as $accountId => $rows) {
            $account = MarketplaceAccount::query()->find($accountId);
            if (! $account || ! $account->isActive() || ! $account->sync_enabled || ! $account->syncs('sync_stock') || $account->setupPending()) {
                continue;
            }

            $warehouseId = $account->warehouse_id ?? Warehouse::query()->where('is_default', true)->value('id');
            if (! $warehouseId) {
                continue;
            }
            $desiredQty = $this->desired($account, $rows, $warehouseId);
            $soonest = null;

            foreach ($rows as $listing) {
                // Not ours to publish (a "Hàng đặt" SKU on a website): the website decides its stock.
                if (! isset($desiredQty[$listing->id])) {
                    continue;
                }
                $desired = $desiredQty[$listing->id];
                $outbox = InventorySyncOutbox::query()->where('listing_variant_id', $listing->id)->first();
                $upToDate = $listing->push_status === 'ok' && $listing->last_pushed_qty === $desired
                    && $listing->last_pushed_at?->gt(now()->subDay());

                if (! $force && $upToDate && (! $outbox || $outbox->status !== InventorySyncOutbox::PENDING)) {
                    continue;
                }

                // Decreases go out at once (oversell risk); increases wait a few seconds so bursts coalesce.
                $decrease = $listing->last_pushed_qty === null || $desired < $listing->last_pushed_qty;
                $debounce = config('queue.default') === 'sync' ? 0 : config('marketplaces.inventory_push.debounce_seconds');
                $due = $decrease ? now() : now()->addSeconds($debounce);

                // Single-statement upsert: concurrent planners for the same listing cannot collide on insert.
                $nextAttempt = $outbox?->status === InventorySyncOutbox::PENDING && $outbox->next_attempt_at?->lt($due) ? $outbox->next_attempt_at : $due;
                InventorySyncOutbox::query()->upsert([[
                    'tenant_id' => $account->tenant_id,
                    'marketplace_account_id' => $account->id,
                    'listing_variant_id' => $listing->id,
                    'correlation_id' => $correlation,
                    'desired_qty' => $desired,
                    'status' => InventorySyncOutbox::PENDING,
                    'attempts' => 0,
                    'next_attempt_at' => $nextAttempt,
                    'last_error' => null,
                    'error_code' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]], ['listing_variant_id'], ['correlation_id', 'desired_qty', 'status', 'attempts', 'next_attempt_at', 'last_error', 'error_code', 'updated_at']);
                $queued++;
                $soonest = $soonest === null || $due->lt($soonest) ? $due : $soonest;
            }

            if ($soonest !== null) {
                PushInventoryJob::dispatch($account->id)->delay($soonest->isFuture() ? $soonest : null)->afterCommit();
            }
        }

        return $queued;
    }

    /**
     * What each linked listing of a shop should show: sellable stock of the shop's warehouse, after its allocation
     * rule; 0 for a SKU stopped in ECSO (Ngừng bán / Lưu trữ) so the shop stops taking orders for it.
     *
     * "Hàng đặt" SKUs (bought after the customer orders) are left out on websites, whose own supplier scraping says
     * whether they can be ordered, and show their fixed "tồn ảo" on marketplaces (0 = not sold there).
     *
     * @param  iterable<ListingVariant>  $rows  mapped listings of $account
     * @return array<int, int> listing id => quantity (listings ECSO does not publish are left out)
     */
    public function desired(MarketplaceAccount $account, iterable $rows, ?int $warehouseId = null): array
    {
        $rows = collect($rows);
        $warehouseId ??= $account->warehouse_id ?? Warehouse::query()->where('is_default', true)->value('id');
        $ids = $rows->pluck('product_variant_id')->unique()->values()->all();
        $sellable = $warehouseId ? $this->stock->sellable([$warehouseId], $ids) : [];
        $stopped = array_flip(ProductVariant::stoppedIds($ids));
        // How much of it this shop publishes (Phân bổ tồn); no rule = everything, as before.
        $rules = $this->allocations->rules($account->id, $ids);
        $toOrder = ProductVariant::madeToOrder($ids);
        $website = $account->marketplace === WooCommerceConnector::MARKETPLACE;
        if ($website) {
            $rows = $rows->reject(fn (ListingVariant $l) => isset($toOrder[$l->product_variant_id]));
        }

        return $rows->mapWithKeys(fn (ListingVariant $l) => [$l->id => match (true) {
            isset($stopped[$l->product_variant_id]) => 0,
            isset($toOrder[$l->product_variant_id]) => $toOrder[$l->product_variant_id],
            default => AllocationCalculator::publish($sellable[$l->product_variant_id] ?? 0, $rules[$l->product_variant_id] ?? AllocationPolicy::DEFAULT_RULE),
        }])->all();
    }
}
