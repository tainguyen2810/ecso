<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Http\Resources\ListingVariantResource;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ListingController extends Controller
{
    public function index(Request $request, MarketplaceAccount $account): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([ListingVariant::UNMAPPED, ListingVariant::SUGGESTED, ListingVariant::MAPPED, ListingVariant::IGNORED, 'push_error'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = $account->listingVariants()->with(['listing', 'variant.product'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'push_error'
                ? $q->whereIn('push_status', ['rejected', 'failed', 'retrying', 'unsupported'])
                : $q->where('mapping_status', $status))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('seller_sku', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")->orWhere('external_sku_id', $term)
                ->orWhereHas('listing', fn ($l) => $l->where('title', 'like', "%{$term}%"))))
            ->orderByRaw("FIELD(mapping_status, 'suggested', 'unmapped', 'mapped', 'ignored')")->orderBy('id');

        return ListingVariantResource::collection($query->paginate(50));
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
