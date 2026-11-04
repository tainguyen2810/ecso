<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\UpdatesPrices;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Jobs\PushPricesJob;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Support\Collection;

/**
 * "Bảng giá sàn": for each internal SKU linked to marketplace SKUs, the price on each shop now and the price the
 * fee table gives. Applying a price sends it through the marketplace's price API when there is one; otherwise it
 * waits in the price file (price_push_status = export) until the shop shows the new price.
 *
 * Websites (WooCommerce) are left out: they sell at the base price, kept by the two-way price sync.
 */
class MarketplacePriceBoard
{
    public const TABS = ['pending', 'loss', 'locked', 'all'];

    public function __construct(private readonly MarketplacePricing $pricing, private readonly ConnectorRegistry $connectors) {}

    /**
     * Shops that have a fee table.
     *
     * @return Collection<int, MarketplaceAccount>
     */
    public function accounts(): Collection
    {
        return MarketplaceAccount::query()->where('marketplace', '!=', WooCommerceConnector::MARKETPLACE)
            ->where('status', MarketplaceAccount::CONNECTED)
            ->whereHas('priceRules', fn ($q) => $q->whereNull('category_id'))->orderBy('id')->get();
    }

    /**
     * Every linked SKU with one cell per shop.
     *
     * @return array{accounts: Collection<int, MarketplaceAccount>, rows: list<array<string, mixed>>}
     */
    public function rows(?string $search = null, ?array $listingIds = null): array
    {
        $accounts = $this->accounts()->keyBy('id');
        $rules = $accounts->map(fn (MarketplaceAccount $a) => $this->pricing->rulesOf($a));

        $listings = ListingVariant::query()->with('listing', 'account')
            ->whereIn('marketplace_account_id', $accounts->keys())
            ->where('mapping_status', ListingVariant::MAPPED)->whereNotNull('product_variant_id')
            ->when($listingIds !== null, fn ($q) => $q->whereIn('ulid', $listingIds))
            ->limit(20000)->get();
        $variants = ProductVariant::query()->with('product:id,ulid,name,category_id')
            ->whereIn('id', $listings->pluck('product_variant_id')->unique())
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('sku', 'like', "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->get()->keyBy('id');

        $rows = [];
        foreach ($listings->groupBy('product_variant_id') as $variantId => $cells) {
            $variant = $variants->get($variantId);
            if (! $variant) {
                continue;
            }
            $base = (float) ($variant->getAttribute('ref_price') ?? 0);
            $cost = (float) $variant->getAttribute('cost_price') ?: null;
            $row = [
                'variant' => $variant,
                'base' => $base,
                'cost' => $cost,
                'cells' => [],
            ];
            foreach ($cells as $listing) {
                $fee = $this->pricing->effective($rules->get($listing->marketplace_account_id), $variant->product?->category_id);
                $row['cells'][$listing->marketplace_account_id] = $this->cell($listing, $fee, $base, $cost);
            }
            $row['flags'] = [
                'pending' => collect($row['cells'])->contains(fn ($c) => $c['status'] === 'pending' || $c['status'] === 'loss'),
                'loss' => collect($row['cells'])->contains(fn ($c) => $c['below_margin']),
                'locked' => collect($row['cells'])->contains(fn ($c) => $c['locked']),
            ];
            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => [$b['flags']['loss'], $b['flags']['pending'], $a['variant']->sku] <=> [$a['flags']['loss'], $a['flags']['pending'], $b['variant']->sku]);

        return ['accounts' => $accounts->values(), 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>|null  $fee
     * @return array<string, mixed>
     */
    private function cell(ListingVariant $listing, ?array $fee, float $base, ?float $cost): array
    {
        $current = $listing->getAttribute('price') !== null ? (float) $listing->getAttribute('price') : null;
        $quote = $fee && $base > 0 ? MarketplacePricing::quote($base, $cost, $fee) : null;
        $locked = (bool) $listing->price_locked;
        $below = $fee && $current !== null && MarketplacePricing::belowMargin($current, $cost, $fee);
        $differs = $quote && ($current === null || abs($quote['price'] - $current) >= 1);

        $status = match (true) {
            $locked => 'locked',
            $quote === null => 'no_price',
            $listing->price_push_status === 'export' && $listing->target_price !== null && abs((float) $listing->target_price - $quote['price']) < 1 => 'export',
            $below => 'loss',
            (bool) $differs => 'pending',
            default => 'ok',
        };

        return [
            'listing' => $listing,
            'current' => $current,
            'quote' => $quote,
            'current_outcome' => $fee && $current !== null ? MarketplacePricing::outcome($current, $cost, $fee) : null,
            'fee' => $fee,
            'locked' => $locked,
            'below_margin' => $below,
            'status' => $status,
        ];
    }

    /**
     * Applies the fee-table price to the given shop SKUs (or every pending one). Locked SKUs are skipped.
     *
     * @param  array<int, string>|null  $listingIds  listing variant ulids
     * @return array{applied: int, pushed: int, exported: int}
     */
    public function apply(?array $listingIds = null, ?int $accountId = null): array
    {
        $result = ['applied' => 0, 'pushed' => 0, 'exported' => 0];
        $push = [];
        foreach ($this->rows(null, $listingIds)['rows'] as $row) {
            foreach ($row['cells'] as $cellAccount => $cell) {
                if ($accountId !== null && $cellAccount !== $accountId) {
                    continue;
                }
                if (! in_array($cell['status'], ['pending', 'loss'], true) || ! $cell['quote']) {
                    continue;
                }
                /** @var ListingVariant $listing */
                $listing = $cell['listing'];
                $byApi = $this->connectors->has($listing->account->marketplace ?? '') && $this->connectors->for($listing->account->marketplace) instanceof UpdatesPrices;
                $listing->forceFill([
                    'target_price' => $cell['quote']['price'],
                    'price_push_status' => $byApi ? 'pending' : 'export',
                    'price_push_error' => null,
                ])->save();
                $result['applied']++;
                $byApi ? $push[$listing->marketplace_account_id] = true : $result['exported']++;
                $result['pushed'] += (int) $byApi;
            }
        }
        foreach (array_keys($push) as $id) {
            PushPricesJob::dispatch($id)->afterCommit();
        }

        return $result;
    }

    /** Applies automatically on shops whose fee table says so, for one SKU (after its base price changed). */
    public function variantChanged(ProductVariant $variant): void
    {
        $ids = ListingVariant::query()->where('product_variant_id', $variant->id)->where('mapping_status', ListingVariant::MAPPED)
            ->whereIn('marketplace_account_id', $this->accounts()->filter(fn (MarketplaceAccount $a) => $this->pricing->rulesOf($a)->get('')?->auto_apply)->pluck('id'))
            ->pluck('ulid')->all();
        if ($ids !== []) {
            $this->apply($ids);
        }
    }

    /** @param  array<int, string>  $listingIds */
    public function lock(array $listingIds, bool $locked): int
    {
        return ListingVariant::query()->whereIn('ulid', $listingIds)->update(['price_locked' => $locked]);
    }

    /**
     * Rows for the price file of one shop: SKUs whose new price is waiting to be uploaded.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(MarketplaceAccount $account): array
    {
        $out = [];
        foreach ($this->rows()['rows'] as $row) {
            $cell = $row['cells'][$account->id] ?? null;
            if (! $cell || $cell['status'] !== 'export') {
                continue;
            }
            $listing = $cell['listing'];
            $out[] = [
                'external_product_id' => (string) ($listing->listing->external_product_id ?? ''),
                'external_sku_id' => $listing->external_sku_id,
                'seller_sku' => $listing->seller_sku,
                'name' => trim(($row['variant']->product?->name ?? '').' '.($row['variant']->name ?? '')),
                'current' => $cell['current'],
                'new' => (float) $listing->target_price,
            ];
        }

        return $out;
    }
}
