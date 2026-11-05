<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Domain\AllocationCalculator;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Phân bổ tồn" of the Sync Center: lists the rules, previews what a change would publish, saves it and re-plans
 * the stock pushes of the listings it touches. Runs in the tenant context. Internal stock is only read.
 */
class AllocationBoard
{
    public const PREVIEW_ROWS = 10;

    public function __construct(
        private readonly StockReader $stock,
        private readonly AllocationPolicy $policy,
        private readonly InventorySyncPlanner $planner,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function overview(?string $search = null): array
    {
        $accounts = MarketplaceAccount::query()->where('status', '!=', MarketplaceAccount::REVOKED)->with('warehouse:id,name')->orderBy('shop_name')->get();
        $rules = MarketplaceAllocation::query()->whereIn('marketplace_account_id', $accounts->pluck('id'))->get();
        $mapped = ListingVariant::query()->whereIn('marketplace_account_id', $accounts->pluck('id'))->where('mapping_status', ListingVariant::MAPPED)
            ->selectRaw('marketplace_account_id, COUNT(DISTINCT product_variant_id) as n')->groupBy('marketplace_account_id')->pluck('n', 'marketplace_account_id');
        $defaultWarehouse = Warehouse::query()->where('is_default', true)->first(['id', 'name']);

        $shops = $accounts->map(fn (MarketplaceAccount $a) => [
            'id' => $a->ulid,
            'name' => $a->shop_name,
            'marketplace' => $a->marketplace,
            'status' => $a->status,
            'syncs_stock' => $a->sync_enabled && $a->syncs('sync_stock'),
            'warehouse' => $a->warehouse?->name ?? $defaultWarehouse?->name,
            'rule' => $rules->first(fn ($r) => $r->marketplace_account_id === $a->id && $r->product_variant_id === null)?->rule() ?? AllocationPolicy::DEFAULT_RULE,
            'is_default' => ! $rules->contains(fn ($r) => $r->marketplace_account_id === $a->id && $r->product_variant_id === null),
            'mapped_skus' => (int) ($mapped[$a->id] ?? 0),
            'overrides' => $rules->where('marketplace_account_id', $a->id)->whereNotNull('product_variant_id')->count(),
        ])->values();

        // SKUs with an override on at least one shop, with what every shop publishes for them.
        $variantIds = $rules->whereNotNull('product_variant_id')->pluck('product_variant_id')->unique()->values()->all();
        $variants = ProductVariant::query()->with('product:id,name')->whereIn('id', $variantIds)
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('sku', 'like', "%{$s}%")->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->orderBy('sku')->limit(200)->get();
        $published = [];
        $sellable = [];
        foreach ($accounts as $a) {
            $warehouseId = $this->warehouseId($a);
            $ids = $variants->pluck('id')->all();
            $stock = $warehouseId ? $this->stock->sellable([$warehouseId], $ids) : [];
            $shopRules = $this->policy->rules($a->id, $ids);
            $stopped = array_flip(ProductVariant::stoppedIds($ids));
            foreach ($ids as $id) {
                $sellable[$id][$a->ulid] = $stock[$id] ?? 0;
                $published[$id][$a->ulid] = isset($stopped[$id]) ? 0 : AllocationCalculator::publish($stock[$id] ?? 0, $shopRules[$id]);
            }
        }

        return [
            'shops' => $shops,
            'overrides' => $variants->map(fn (ProductVariant $v) => [
                'variant' => ['id' => $v->ulid, 'sku' => $v->sku, 'name' => $v->displayName()],
                'sellable' => $sellable[$v->id] ?? [],
                'cells' => $accounts->mapWithKeys(fn (MarketplaceAccount $a) => [$a->ulid => [
                    'rule' => $rules->first(fn ($r) => $r->marketplace_account_id === $a->id && $r->product_variant_id === $v->id)?->rule(),
                    'published' => $published[$v->id][$a->ulid] ?? 0,
                ]]),
            ])->values(),
        ];
    }

    /**
     * What saving $rule would publish on the shop's listings it governs (the shop's SKUs without their own rule, or
     * one SKU), against what is on the shop now.
     *
     * @param  array{mode: string, value: int|null, buffer_qty: int, max_qty: int|null}|null  $rule  null = remove the override / reset to "all"
     * @return array{total: int, changed: int, to_zero: int, rows: array<int, array<string, mixed>>}
     */
    public function preview(MarketplaceAccount $account, ?ProductVariant $variant, ?array $rule): array
    {
        $listings = $this->governed($account, $variant);
        $ids = $listings->pluck('product_variant_id')->unique()->values()->all();
        $warehouseId = $this->warehouseId($account);
        $stock = $warehouseId ? $this->stock->sellable([$warehouseId], $ids) : [];
        $stopped = array_flip(ProductVariant::stoppedIds($ids));
        // Removing a SKU override falls back to the shop default.
        $fallback = $variant ? ($this->shopRule($account) ?? AllocationPolicy::DEFAULT_RULE) : AllocationPolicy::DEFAULT_RULE;
        $rule ??= $fallback;
        $variants = ProductVariant::query()->with('product:id,name')->whereIn('id', $ids)->get()->keyBy('id');

        $rows = [];
        $changed = 0;
        $toZero = 0;
        foreach ($listings as $listing) {
            $sellable = $stock[$listing->product_variant_id] ?? 0;
            $next = isset($stopped[$listing->product_variant_id]) ? 0 : AllocationCalculator::publish($sellable, $rule);
            $current = $listing->last_pushed_qty;
            if ($current === $next) {
                continue;
            }
            $changed++;
            $toZero += $next === 0 ? 1 : 0;
            $v = $variants->get($listing->product_variant_id);
            $rows[] = ['sku' => $v?->sku ?? $listing->seller_sku, 'name' => $v?->displayName(), 'sellable' => $sellable, 'current' => $current, 'next' => $next];
        }
        usort($rows, fn ($a, $b) => (($b['current'] ?? 0) - $b['next']) <=> (($a['current'] ?? 0) - $a['next']));

        return ['total' => $listings->count(), 'changed' => $changed, 'to_zero' => $toZero, 'rows' => array_slice($rows, 0, self::PREVIEW_ROWS)];
    }

    /**
     * Saves (or with $rule null removes) a shop default or SKU override, then re-plans the governed listings so the
     * shop gets the new numbers. Returns the number of listings queued for a push.
     *
     * @param  array{mode: string, value: int|null, buffer_qty: int, max_qty: int|null}|null  $rule
     */
    public function save(MarketplaceAccount $account, ?ProductVariant $variant, ?array $rule): int
    {
        $scope = MarketplaceAllocation::scopeKey($variant?->id);
        $before = MarketplaceAllocation::query()->where('marketplace_account_id', $account->id)->where('scope', $scope)->first()?->rule();
        $variantIds = $this->governed($account, $variant)->pluck('product_variant_id')->unique()->values()->all();

        DB::transaction(function () use ($account, $variant, $rule, $scope) {
            if ($rule === null) {
                MarketplaceAllocation::query()->where('marketplace_account_id', $account->id)->where('scope', $scope)->delete();

                return;
            }
            MarketplaceAllocation::query()->updateOrCreate(
                ['marketplace_account_id' => $account->id, 'scope' => $scope],
                ['product_variant_id' => $variant?->id, ...$rule],
            );
        });

        $this->audit->record('sync.allocation_changed', $account, [
            'sku' => $variant?->sku,
            'before' => $before,
            'after' => $rule,
        ]);

        return $variantIds ? $this->planner->plan($variantIds) : 0;
    }

    /** @return array{mode: string, value: int|null, buffer_qty: int, max_qty: int|null}|null */
    private function shopRule(MarketplaceAccount $account): ?array
    {
        return MarketplaceAllocation::query()->where('marketplace_account_id', $account->id)->where('scope', MarketplaceAllocation::SHOP)->first()?->rule();
    }

    /**
     * Mapped listings of the shop that a rule at this level governs: one SKU's listings, or for the shop default
     * every listing whose SKU has no override of its own.
     *
     * @return Collection<int, ListingVariant>
     */
    private function governed(MarketplaceAccount $account, ?ProductVariant $variant): Collection
    {
        $query = ListingVariant::query()->where('marketplace_account_id', $account->id)->where('mapping_status', ListingVariant::MAPPED);
        if ($variant) {
            return $query->where('product_variant_id', $variant->id)->get();
        }
        $overridden = MarketplaceAllocation::query()->where('marketplace_account_id', $account->id)->whereNotNull('product_variant_id')->pluck('product_variant_id');

        return $query->whereNotIn('product_variant_id', $overridden)->get();
    }

    private function warehouseId(MarketplaceAccount $account): ?int
    {
        return $account->warehouse_id ?? Warehouse::query()->where('is_default', true)->value('id');
    }
}
