<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\StockCheck;
use Throwable;

/**
 * Đợt 16 "Đối soát tồn với sàn": reads every listing of a shop again, compares the stock the marketplace shows with
 * what ECSO publishes for it (sellable stock after the allocation rule), lists the SKUs that differ and pushes ECSO's
 * figure to them. ECSO stays the source of truth: nothing in ECSO's own stock changes.
 *
 * SKUs with a push still waiting are skipped (they are about to change anyway).
 */
class StockReconciler
{
    public function __construct(private readonly ListingService $listings, private readonly InventorySyncPlanner $planner) {}

    /** Can this shop be checked (connected, stock sync on)? */
    public static function eligible(MarketplaceAccount $account): bool
    {
        return $account->isActive() && $account->sync_enabled && $account->syncs('sync_stock') && ! $account->setupPending();
    }

    public function run(MarketplaceAccount $account, string $trigger = 'nightly'): StockCheck
    {
        $check = StockCheck::query()->create(['marketplace_account_id' => $account->id, 'trigger' => $trigger, 'status' => StockCheck::RUNNING, 'started_at' => now()]);
        try {
            $this->listings->sync($account);
            $this->compare($account, $check);
        } catch (Throwable $e) {
            $check->forceFill([
                'status' => StockCheck::FAILED,
                'error' => mb_substr($e instanceof ConnectorException ? $e->getMessage() : 'Không đọc được tồn trên sàn, sẽ thử lại đêm sau.', 0, 255),
                'finished_at' => now(),
            ])->save();
            if (! $e instanceof ConnectorException) {
                report($e);
            }
        }

        return $check;
    }

    private function compare(MarketplaceAccount $account, StockCheck $check): void
    {
        $rows = ListingVariant::query()->with('listing:id,title', 'variant:id,sku,name,product_id', 'variant.product:id,name')
            ->where('marketplace_account_id', $account->id)->where('mapping_status', ListingVariant::MAPPED)
            ->whereNotNull('product_variant_id')->whereNotNull('marketplace_stock')
            ->whereNotIn('id', InventorySyncOutbox::query()->where('marketplace_account_id', $account->id)->where('status', InventorySyncOutbox::PENDING)->select('listing_variant_id'))
            ->get();
        $desired = $this->planner->desired($account, $rows);
        // Listings ECSO does not publish ("Hàng đặt" on a website) are not compared.
        $rows = $rows->filter(fn (ListingVariant $l) => isset($desired[$l->id]))->values();

        $differ = $rows->filter(fn (ListingVariant $l) => (int) $l->marketplace_stock !== ($desired[$l->id] ?? 0));
        $items = $differ->sortByDesc(fn (ListingVariant $l) => abs((int) $l->marketplace_stock - $desired[$l->id]))->take(StockCheck::MAX_ITEMS)
            ->map(fn (ListingVariant $l) => [
                'sku' => $l->variant->sku ?? $l->seller_sku,
                'name' => trim(($l->variant->product->name ?? $l->listing->title ?? '').($l->variant?->name ? ' - '.$l->variant->name : '')),
                'marketplace_qty' => (int) $l->marketplace_stock,
                'ecso_qty' => $desired[$l->id],
            ])->values()->all();

        $fixed = $differ->isEmpty() ? 0 : $this->planner->plan($differ->pluck('product_variant_id')->unique()->values()->all(), force: true);

        $check->forceFill([
            'status' => StockCheck::DONE,
            'checked' => $rows->count(),
            'mismatched' => $differ->count(),
            'fixed' => min($fixed, $differ->count()),
            'items' => $items,
            'finished_at' => now(),
        ])->save();
        // Older runs are not needed beyond a month.
        StockCheck::query()->where('marketplace_account_id', $account->id)->where('created_at', '<', now()->subDays(30))->delete();
    }
}
