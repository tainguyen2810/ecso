<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Http\Resources\ListingVariantResource;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Marketplace\Services\MarketplaceImages;
use App\Modules\Marketplace\Services\NewListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ListingController extends Controller
{
    public function index(Request $request, MarketplaceAccount $account, NewListingService $newListings): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $filters = $request->validate([
            // todo = "Cần xử lý": not linked and not ignored (no internal SKU, or a suggestion to confirm).
            'status' => ['nullable', Rule::in([ListingVariant::UNMAPPED, ListingVariant::SUGGESTED, ListingVariant::MAPPED, ListingVariant::IGNORED, 'push_error', 'todo'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        // linked_count: SKUs of the same product already linked; a product with none can be created from the store.
        $query = $account->listingVariants()->with(['listing' => fn ($l) => $l->withCount(['variants as linked_count' => fn ($v) => $v->where('mapping_status', '!=', ListingVariant::UNMAPPED)]), 'variant.product'])
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'push_error' => $q->whereIn('push_status', ['rejected', 'failed', 'retrying', 'unsupported']),
                'todo' => $q->whereIn('mapping_status', [ListingVariant::UNMAPPED, ListingVariant::SUGGESTED]),
                default => $q->where('mapping_status', $status),
            })
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('seller_sku', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")->orWhere('external_sku_id', $term)
                ->orWhereHas('listing', fn ($l) => $l->where('title', 'like', "%{$term}%"))))
            ->orderByRaw("FIELD(mapping_status, 'suggested', 'unmapped', 'mapped', 'ignored')")->orderBy('id');

        return ListingVariantResource::collection($query->paginate(50))->additional(['summary' => $this->summary($account, $newListings)]);
    }

    /**
     * The four numbers on top of Liên kết sản phẩm.
     *
     * @return array<string, int>
     */
    private function summary(MarketplaceAccount $account, NewListingService $newListings): array
    {
        $counts = $account->listingVariants()->selectRaw('mapping_status, linked_by_sku, COUNT(*) as n')->groupBy('mapping_status', 'linked_by_sku')->get();
        $by = fn (string $status) => (int) $counts->where('mapping_status', $status)->sum('n');

        return [
            'variants' => (int) $counts->sum('n'),
            'products' => $account->listingVariants()->distinct()->count('listing_id'),
            'mapped' => $by(ListingVariant::MAPPED),
            'linked_by_sku' => (int) $counts->where('mapping_status', ListingVariant::MAPPED)->where('linked_by_sku', true)->sum('n'),
            'unmapped' => $by(ListingVariant::UNMAPPED),
            'suggested' => $by(ListingVariant::SUGGESTED),
            'ignored' => $by(ListingVariant::IGNORED),
            'push_errors' => $account->listingVariants()->whereIn('push_status', ['rejected', 'failed', 'retrying', 'unsupported'])->count(),
            ...collect($newListings->creatable($account))->mapWithKeys(fn ($n, $k) => ["creatable_{$k}" => $n])->all(),
        ];
    }

    /** "Ảnh từ sàn" on the product page: the pictures each linked shop has for this product. */
    public function productImages(Product $product, MarketplaceImages $images): JsonResponse
    {
        $this->authorize('view', $product);

        return response()->json(['data' => $images->forProduct($product)]);
    }

    /** Copies the chosen marketplace pictures into the product (now: a handful of pictures). */
    public function copyProductImages(Request $request, Product $product, MarketplaceImages $images): JsonResponse
    {
        $this->authorize('update', $product);
        $data = $request->validate([
            'urls' => ['array', 'max:9'],
            'urls.*' => ['string', 'max:1000'],
            'variant_images' => ['array', 'max:30'],
            'variant_images.*.url' => ['required', 'string', 'max:1000'],
            'variant_images.*.variant_ids' => ['required', 'array', 'max:100'],
            'variant_images.*.variant_ids.*' => ['string'],
        ]);
        $result = $images->copy($product, array_values($data['urls'] ?? []), array_values($data['variant_images'] ?? []));
        abort_if($result['added'] === 0 && $result['failed'] > 0, 422, 'Không tải được ảnh từ sàn. Thử lại sau.');

        return response()->json(['data' => $result + ['images' => $product->images()->get()->map(fn ($i) => ['id' => $i->id, 'url' => $i->url()])]]);
    }

    /** How many products have no picture but a linked shop has one (the banner on the product list). */
    public function missingImages(MarketplaceImages $images): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(['data' => ['count' => $images->missingCount()]]);
    }

    /** "Lấy ảnh từ sàn" for products without a picture: downloaded in the background. */
    public function queueMissingImages(Request $request, MarketplaceImages $images): JsonResponse
    {
        $this->authorize('update', Product::class);
        $data = $request->validate(['product_ids' => ['nullable', 'array', 'max:500'], 'product_ids.*' => ['string']]);

        return response()->json(['data' => $images->queueMissing($data['product_ids'] ?? null)]);
    }

    /** Where one product is sold: its linked listings on every connected shop, for "Trên các kênh" on the product page. */
    public function forProduct(Product $product): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $rows = ListingVariant::query()->with(['account', 'variant'])
            ->where('mapping_status', ListingVariant::MAPPED)
            ->whereIn('product_variant_id', $product->variants()->withTrashed()->pluck('id'))
            ->get();

        return response()->json(['data' => $rows->groupBy('marketplace_account_id')->map(function ($group) {
            $account = $group->first()->account;
            $errors = $group->whereIn('push_status', ['rejected', 'failed', 'retrying', 'unsupported']);

            return [
                'account' => $account ? ['id' => $account->ulid, 'shop_name' => $account->shop_name, 'marketplace' => $account->marketplace, 'status' => $account->status] : null,
                'linked' => $group->count(),
                'variants' => $group->pluck('variant.ulid')->filter()->unique()->values(),
                'last_pushed_at' => $group->max('last_pushed_at')?->toIso8601String(),
                'push_error' => $errors->first()?->push_error,
                'push_errors' => $errors->count(),
            ];
        })->values()]);
    }

    public function map(Request $request, ListingVariant $listing, ListingService $listings): ListingVariantResource
    {
        $this->authorize('map', MarketplaceAccount::class);
        $data = $request->validate(['variant_id' => ['required', 'string']]);
        $variant = ProductVariant::query()->where('ulid', $data['variant_id'])->firstOrFail();

        return new ListingVariantResource($listings->map($listing, $variant)->load(['listing', 'variant.product']));
    }

    public function unmap(Request $request, ListingVariant $listing, ListingService $listings): ListingVariantResource
    {
        $this->authorize('map', MarketplaceAccount::class);

        return new ListingVariantResource($listings->unmap($listing, $request->boolean('ignore'))->load(['listing', 'variant.product']));
    }

    public function confirmSuggestions(MarketplaceAccount $account, ListingService $listings): JsonResponse
    {
        $this->authorize('map', MarketplaceAccount::class);

        return response()->json(['data' => ['confirmed' => $listings->confirmSuggestions($account)]]);
    }
}
