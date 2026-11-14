<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Marketplace\Jobs\SyncListingsJob;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A5: the steps after a shop is connected for the first time, using what exists (suggestions by seller SKU, "Sản phẩm
 * mới chờ duyệt", opening stock receipts):
 *  1. products are pulled from the shop (the listing sync),
 *  2. SKUs are linked by the seller SKU and products the warehouse lacks are created,
 *  3. the opening stock is chosen once: the shop's stock, EzSell's stock, or no stock sync yet,
 *  4. sync is switched on. Until then no stock is pushed (MarketplaceAccount::setupPending()).
 */
class ShopSetupService
{
    public function __construct(
        private readonly ListingService $listings,
        private readonly NewListingService $newListings,
        private readonly InventoryDocumentService $documents,
        private readonly InventorySyncPlanner $planner,
        private readonly AccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function overview(MarketplaceAccount $account): array
    {
        $variants = ListingVariant::query()->where('marketplace_account_id', $account->id);
        $newListingIds = $this->newListingQuery($account)->pluck('id');
        $inNew = ListingVariant::query()->whereIn('listing_id', $newListingIds);

        $matched = (clone $variants)->where(fn ($q) => $q->where('mapping_status', ListingVariant::MAPPED)
            ->orWhere(fn ($q) => $q->where('mapping_status', ListingVariant::SUGGESTED)->whereNotNull('product_variant_id')))->count();
        $createWithSku = (clone $inNew)->whereNotNull('seller_sku')->where('seller_sku', '!=', '')->count();
        $createWithoutSku = (clone $inNew)->where(fn ($q) => $q->whereNull('seller_sku')->orWhere('seller_sku', ''))->count();
        $total = (clone $variants)->count();

        $preview = (clone $variants)->with(['listing:id,title', 'variant:id,sku,name,product_id', 'variant.product:id,name'])
            ->orderByRaw("FIELD(mapping_status, 'suggested', 'mapped', 'unmapped', 'ignored')")->orderBy('id')->limit(8)->get()
            ->map(fn (ListingVariant $v) => [
                'title' => trim(($v->listing->title ?? '').($v->name ? ' · '.$v->name : '')),
                'seller_sku' => $v->seller_sku,
                'internal' => $v->variant ? trim(($v->variant->product->name ?? '').' · '.($v->variant->name ?? $v->variant->sku)) : null,
                'action' => match (true) {
                    $v->product_variant_id !== null && in_array($v->mapping_status, [ListingVariant::MAPPED, ListingVariant::SUGGESTED], true) => 'link',
                    $newListingIds->contains($v->listing_id) => blank($v->seller_sku) ? 'create_generated' : 'create',
                    default => 'later',
                },
            ])->all();

        return [
            'pending' => $account->setupPending(),
            'listings_synced' => $account->last_synced_at !== null,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'counts' => [
                'total' => $total,
                'matched' => $matched,
                'create' => $createWithSku,
                'create_without_sku' => $createWithoutSku,
                'later' => max(0, $total - $matched - $createWithSku - $createWithoutSku),
                'unlinked_left' => (clone $variants)->whereIn('mapping_status', [ListingVariant::UNMAPPED, ListingVariant::SUGGESTED])->count(),
            ],
            'preview' => $preview,
            'stock' => $this->stockPreview($account),
        ];
    }

    /** Step 1 again (the products had not been pulled, or the shop changed meanwhile). */
    public function pull(MarketplaceAccount $account): void
    {
        SyncListingsJob::dispatch($account->id);
    }

    /**
     * Step 2: confirms every SKU suggested by its seller SKU and creates the products the warehouse lacks.
     *
     * @return array{linked: int, created: int}
     */
    public function link(MarketplaceAccount $account, bool $withoutSku): array
    {
        $linked = $this->listings->confirmSuggestions($account);
        $ulids = $this->newListingQuery($account)
            ->when(! $withoutSku, fn ($q) => $q->whereDoesntHave('variants', fn ($v) => $v->whereNull('seller_sku')->orWhere('seller_sku', '')))
            ->pluck('ulid')->all();
        $made = $ulids === [] ? ['created' => 0, 'linked' => 0] : $this->newListings->approve($account, $ulids);
        $this->audit->record('marketplace.setup_linked', $account, ['linked' => $linked, 'created' => $made['created']]);

        return ['linked' => $linked + $made['linked'], 'created' => $made['created']];
    }

    /**
     * Step 3. "marketplace": the shop's stock becomes the opening stock of SKUs that have none in the shop's warehouse
     * (one opening receipt; SKUs with stock are left alone so nothing is counted twice). "ezsell": EzSell's stock is
     * pushed when sync goes on. "none": stock sync stays off.
     *
     * @return array{receipt: string|null, lines: int, quantity: int}
     */
    public function openingStock(MarketplaceAccount $account, string $source, ?int $costPercent): array
    {
        if (! in_array($source, ['marketplace', 'ezsell', 'none'], true)) {
            throw ValidationException::withMessages(['source' => 'Nguồn tồn không hợp lệ.']);
        }
        $settings = (array) $account->settings;
        $settings['setup_stock'] = $source;
        $settings['sync_stock'] = $source !== 'none';
        $account->forceFill(['settings' => $settings])->save();

        if ($source !== 'marketplace') {
            return ['receipt' => null, 'lines' => 0, 'quantity' => 0];
        }

        $warehouse = $this->warehouse($account);
        $lines = [];
        foreach ($this->stockRows($account) as $row) {
            if ($row['marketplace'] > 0 && $row['on_hand'] === 0) {
                $cost = $costPercent !== null ? number_format(round((float) $row['ref_price'] * $costPercent / 100, 2), 2, '.', '') : '0';
                $lines[] = ['variant_id' => $row['variant_id'], 'quantity' => $row['marketplace'], 'unit_cost' => $cost];
            }
        }
        if ($lines === []) {
            return ['receipt' => null, 'lines' => 0, 'quantity' => 0];
        }
        $receipt = $this->documents->receive($warehouse, $lines, "Tồn đầu kỳ lấy từ {$account->shop_name}", StockReceipt::SOURCE_OPENING);
        $this->audit->record('marketplace.setup_opening_stock', $account, ['receipt' => $receipt->number, 'lines' => count($lines)]);

        return ['receipt' => $receipt->number, 'lines' => count($lines), 'quantity' => array_sum(array_column($lines, 'quantity'))];
    }

    /**
     * Step 4: saves the sync switches, ends the setup and publishes the stock of every linked SKU when stock sync is on.
     *
     * @param  array<string, bool>  $switches
     */
    public function finish(MarketplaceAccount $account, array $switches): MarketplaceAccount
    {
        $account = $this->accounts->update($account, ['settings' => $switches]);
        $settings = (array) $account->settings;
        unset($settings['setup']);
        $account->forceFill(['settings' => $settings])->save();
        $this->audit->record('marketplace.setup_finished', $account, ['switches' => $switches]);

        if ($account->isActive() && $account->sync_enabled && $account->syncs('sync_stock')) {
            $ids = $account->listingVariants()->where('mapping_status', ListingVariant::MAPPED)->pluck('product_variant_id')->unique()->values()->all();
            if ($ids !== []) {
                $this->planner->plan($ids, force: true);
            }
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function stockPreview(MarketplaceAccount $account): array
    {
        $rows = $this->stockRows($account);
        $fromShop = array_filter($rows, fn ($r) => $r['marketplace'] > 0 && $r['on_hand'] === 0);
        $ezsellHasStock = array_sum(array_column($rows, 'on_hand')) > 0;
        // Another shop of this business already pushes stock: its SKUs keep EzSell's stock (no double counting).
        $otherShop = MarketplaceAccount::query()->whereKeyNot($account->id)->where('status', MarketplaceAccount::CONNECTED)
            ->where('sync_enabled', true)->get()->contains(fn (MarketplaceAccount $a) => $a->syncs('sync_stock') && ! $a->setupPending());

        return [
            'suggested' => $ezsellHasStock || $otherShop ? 'ezsell' : 'marketplace',
            'linked' => count($rows),
            'from_shop_lines' => count($fromShop),
            'from_shop_quantity' => array_sum(array_column($fromShop, 'marketplace')),
            'ezsell_quantity' => array_sum(array_column($rows, 'on_hand')),
            'rows' => array_slice(array_map(fn ($r) => ['sku' => $r['sku'], 'marketplace' => $r['marketplace'], 'on_hand' => $r['on_hand']], $rows), 0, 8),
        ];
    }

    /**
     * Linked SKUs of the shop (each once; the highest shop stock when two listings share a SKU) with their stock in the
     * shop's warehouse.
     *
     * @return list<array{variant_id: int, sku: string, ref_price: string, marketplace: int, on_hand: int}>
     */
    private function stockRows(MarketplaceAccount $account): array
    {
        $byVariant = ListingVariant::query()->where('marketplace_account_id', $account->id)->where('mapping_status', ListingVariant::MAPPED)
            ->whereNotNull('product_variant_id')->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, MAX(COALESCE(marketplace_stock, 0)) as stock')->pluck('stock', 'product_variant_id');
        if ($byVariant->isEmpty()) {
            return [];
        }
        $warehouse = $this->warehouse($account);
        $onHand = DB::table('stock_balances')->where('tenant_id', $account->tenant_id)->where('warehouse_id', $warehouse->id)
            ->whereIn('product_variant_id', $byVariant->keys())->pluck('on_hand', 'product_variant_id');
        $variants = ProductVariant::query()->whereIn('id', $byVariant->keys())->orderBy('sku')->get(['id', 'sku', 'ref_price']);

        return $variants->map(fn (ProductVariant $v) => [
            'variant_id' => (int) $v->id,
            'sku' => (string) $v->sku,
            'ref_price' => (string) ($v->ref_price ?? '0'),
            'marketplace' => max(0, (int) $byVariant[$v->id]),
            'on_hand' => (int) ($onHand[$v->id] ?? 0),
        ])->values()->all();
    }

    private function warehouse(MarketplaceAccount $account): Warehouse
    {
        return Warehouse::query()->find($account->warehouse_id) ?? Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    /** Listings still on the shop whose SKUs are all unlinked ("Sản phẩm mới chờ duyệt"). */
    private function newListingQuery(MarketplaceAccount $account)
    {
        return MarketplaceListing::query()->where('marketplace_account_id', $account->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['deleted', 'trash']))
            ->whereHas('variants')
            ->whereDoesntHave('variants', fn ($q) => $q->where('mapping_status', '!=', ListingVariant::UNMAPPED));
    }
}
