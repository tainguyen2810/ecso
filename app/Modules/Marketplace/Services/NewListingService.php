<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Marketplace\Events\ListingMappingChanged;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceListing;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Products that exist on a store but not in ECSO ("Sản phẩm mới chờ duyệt"): listings none of whose SKUs matches an
 * internal SKU. Approving one creates the product in the catalog, priced at what the buyer pays on the store now
 * (the sale price while a sale runs), and links it; skipping marks its SKUs "ignored".
 */
class NewListingService
{
    public function __construct(
        private readonly ProductService $products,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Collection<int, MarketplaceListing> */
    public function pending(MarketplaceAccount $account, int $limit = 200): Collection
    {
        return $this->query($account)->with(['variants' => fn ($q) => $q->orderBy('id')])->orderByDesc('id')->limit($limit)->get();
    }

    public function count(MarketplaceAccount $account): int
    {
        return $account->syncs('import_new_products') ? $this->query($account)->count() : 0;
    }

    /**
     * @param  array<int, string>|null  $listingUlids  null = all pending
     * @return array{created: int, linked: int}
     */
    public function approve(MarketplaceAccount $account, ?array $listingUlids): array
    {
        $result = ['created' => 0, 'linked' => 0];
        $this->prefix = $account->marketplace === 'woocommerce' ? 'WOO-' : 'MP-';
        $mapped = [];
        $listings = $this->query($account)->when($listingUlids !== null, fn ($q) => $q->whereIn('ulid', $listingUlids))
            ->with('variants')->get();

        foreach ($listings as $listing) {
            DB::transaction(function () use ($listing, &$result, &$mapped) {
                $variants = $listing->variants->where('mapping_status', ListingVariant::UNMAPPED)->values();
                if ($variants->isEmpty()) {
                    return;
                }

                // A SKU created in ECSO in the meantime is linked, not duplicated.
                $existing = ProductVariant::query()->whereIn('sku', $variants->map(fn ($v) => $this->sku($v))->all())->pluck('id', 'sku');
                $toCreate = $variants->reject(fn ($v) => $existing->has($this->sku($v)))->values();

                if ($toCreate->isNotEmpty()) {
                    $product = $this->products->create([
                        'name' => mb_substr($listing->title ?: 'Sản phẩm từ website', 0, 255),
                        'status' => 'active',
                        'variants' => $toCreate->map(fn (ListingVariant $v) => [
                            'sku' => $this->sku($v),
                            'name' => $v->name ?: 'Mặc định',
                            'ref_price' => (string) ($v->price ?? 0),
                            'status' => 'active',
                        ])->all(),
                    ]);
                    $created = $product->variants()->pluck('id', 'sku');
                    $existing = $existing->merge($created);
                    $result['created']++;
                } else {
                    $result['linked']++;
                }

                foreach ($variants as $v) {
                    $v->forceFill([
                        'product_variant_id' => $existing[$this->sku($v)],
                        'mapping_status' => ListingVariant::MAPPED,
                        'mapped_by' => Auth::id(),
                        'mapped_at' => now(),
                    ])->save();
                    $mapped[] = $v->id;
                }
            });
        }

        if ($mapped !== []) {
            $this->audit->record('listing.new_products_approved', $account, $result);
            ListingMappingChanged::dispatch($this->context->id(), $mapped);
        }

        return $result;
    }

    /** @param  array<int, string>  $listingUlids */
    public function skip(MarketplaceAccount $account, array $listingUlids): int
    {
        $ids = MarketplaceListing::query()->where('marketplace_account_id', $account->id)->whereIn('ulid', $listingUlids)->pluck('id');

        return ListingVariant::query()->whereIn('listing_id', $ids)->where('mapping_status', ListingVariant::UNMAPPED)
            ->update(['mapping_status' => ListingVariant::IGNORED]);
    }

    private string $prefix = 'MP-';

    private function sku(ListingVariant $v): string
    {
        // A store product without SKU gets one from its id, so it can be linked and found again.
        return ProductVariant::normalizeSku($v->seller_sku ?: $this->prefix.$v->external_sku_id);
    }

    /** Listings still on the store whose SKUs are all unmapped (never linked, suggested or ignored). */
    private function query(MarketplaceAccount $account)
    {
        return MarketplaceListing::query()->where('marketplace_account_id', $account->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['deleted', 'trash']))
            ->whereHas('variants')
            ->whereDoesntHave('variants', fn ($q) => $q->where('mapping_status', '!=', ListingVariant::UNMAPPED));
    }
}
