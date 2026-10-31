<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Inventory\Domain\AllocationCalculator;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Marketplace\Jobs\PushInventoryJob;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;

/**
 * Steps [8] of INVENTORY-ENGINE §7: recompute the published quantity of every mapped listing of the changed SKUs
 * and write it to the outbox (one row per listing, latest value wins). Runs in the tenant context.
 */
class InventorySyncPlanner
{
    public function __construct(private readonly StockReader $stock) {}

    /**
     * @param  array<int, int>  $variantIds
     * @return int rows queued
     */
    public function plan(array $variantIds, bool $force = false): int
    {
        $listings = ListingVariant::query()->whereIn('product_variant_id', $variantIds)
            ->where('mapping_status', ListingVariant::MAPPED)->get()->groupBy('marketplace_account_id');

        $queued = 0;
        foreach ($listings as $accountId => $rows) {
            $account = MarketplaceAccount::query()->find($accountId);
            if (! $account || ! $account->isActive() || ! $account->sync_enabled) {
                continue;
            }

            $warehouseId = $account->warehouse_id ?? Warehouse::query()->where('is_default', true)->value('id');
            if (! $warehouseId) {
                continue;
            }
            $sellable = $this->stock->sellable([$warehouseId], $rows->pluck('product_variant_id')->unique()->values()->all());
            $soonest = null;

            foreach ($rows as $listing) {
                $desired = AllocationCalculator::published($sellable[$listing->product_variant_id] ?? 0);
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
                    'desired_qty' => $desired,
                    'status' => InventorySyncOutbox::PENDING,
                    'attempts' => 0,
                    'next_attempt_at' => $nextAttempt,
                    'last_error' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]], ['listing_variant_id'], ['desired_qty', 'status', 'attempts', 'next_attempt_at', 'last_error', 'updated_at']);
                $queued++;
                $soonest = $soonest === null || $due->lt($soonest) ? $due : $soonest;
            }

            if ($soonest !== null) {
                PushInventoryJob::dispatch($account->id)->delay($soonest->isFuture() ? $soonest : null)->afterCommit();
            }
        }

        return $queued;
    }
}
