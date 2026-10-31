<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Events\ListingMappingChanged;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceListing;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Listings imported from a shop and their mapping to internal SKUs. Names are never used for matching:
 * an exact (case-insensitive) seller SKU = internal SKU match is only a suggestion until a person confirms it.
 */
class ListingService
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{listings: int, variants: int, suggested: int} */
    public function sync(MarketplaceAccount $account): array
    {
        $connector = $this->connectors->for($account->marketplace);
        $counts = ['listings' => 0, 'variants' => 0, 'suggested' => 0];
        $cursor = null;

        do {
            $page = $connector->listProducts($account, $cursor);
            foreach ($page->products as $product) {
                DB::transaction(function () use ($account, $product, &$counts) {
                    $listing = MarketplaceListing::query()->updateOrCreate(
                        ['marketplace_account_id' => $account->id, 'external_product_id' => $product->externalProductId],
                        ['title' => $product->title, 'status' => $product->status, 'image_url' => $product->imageUrl, 'synced_at' => now()],
                    );
                    $counts['listings']++;

                    foreach ($product->variants as $v) {
                        $variant = ListingVariant::query()->firstOrNew(['marketplace_account_id' => $account->id, 'external_sku_id' => $v['external_sku_id']]);
                        $variant->fill([
                            'listing_id' => $listing->id,
                            'seller_sku' => $v['seller_sku'],
                            'name' => $v['name'],
                            'price' => $v['price'],
                            'marketplace_stock' => $v['stock'],
                        ])->save();
                        $counts['variants']++;
                        $counts['suggested'] += (int) $this->suggest($variant);
                    }
                });
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        $account->forceFill(['last_synced_at' => now()])->save();

        return $counts;
    }

    /** Suggests the internal SKU whose code equals the seller SKU exactly. Never maps by itself. */
    public function suggest(ListingVariant $listing): bool
    {
        if (! in_array($listing->mapping_status, [ListingVariant::UNMAPPED, ListingVariant::SUGGESTED], true) || blank($listing->seller_sku)) {
            return false;
        }

        $match = ProductVariant::query()->where('sku', ProductVariant::normalizeSku($listing->seller_sku))->value('id');
        $listing->forceFill([
            'product_variant_id' => $match,
            'mapping_status' => $match ? ListingVariant::SUGGESTED : ListingVariant::UNMAPPED,
        ])->save();

        return $match !== null;
    }

    public function map(ListingVariant $listing, ProductVariant $variant): ListingVariant
    {
        $before = $listing->product_variant_id;
        $listing->forceFill([
            'product_variant_id' => $variant->id,
            'mapping_status' => ListingVariant::MAPPED,
            'mapped_by' => Auth::id(),
            'mapped_at' => now(),
        ])->save();

        $this->audit->record('listing.mapped', $listing, ['external_sku_id' => $listing->external_sku_id, 'sku' => $variant->sku, 'previous_variant_id' => $before]);
        ListingMappingChanged::dispatch($this->context->id(), [$listing->id]);

        return $listing;
    }

    /**
     * Confirms every pending suggestion of a shop.
     */
    public function confirmSuggestions(MarketplaceAccount $account): int
    {
        $ids = ListingVariant::query()->where('marketplace_account_id', $account->id)
            ->where('mapping_status', ListingVariant::SUGGESTED)->whereNotNull('product_variant_id')->pluck('id')->all();

        if ($ids === []) {
            return 0;
        }

        ListingVariant::query()->whereIn('id', $ids)->update([
            'mapping_status' => ListingVariant::MAPPED, 'mapped_by' => Auth::id(), 'mapped_at' => now(),
        ]);
        $this->audit->record('listing.suggestions_confirmed', $account, ['count' => count($ids)]);
        ListingMappingChanged::dispatch($this->context->id(), $ids);

        return count($ids);
    }

    public function unmap(ListingVariant $listing, bool $ignore = false): ListingVariant
    {
        $listing->forceFill([
            'product_variant_id' => null,
            'mapping_status' => $ignore ? ListingVariant::IGNORED : ListingVariant::UNMAPPED,
            'mapped_by' => Auth::id(),
            'mapped_at' => now(),
            'push_status' => null,
        ])->save();
        $this->audit->record($ignore ? 'listing.ignored' : 'listing.unmapped', $listing, ['external_sku_id' => $listing->external_sku_id]);

        return $listing;
    }
}
