<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\UpdatesPrices;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PriceChange;
use App\Modules\Marketplace\Services\PriceSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Two-way price sync: website prices waiting for approval, and the price source / channel prices / history of a product. */
class PriceController extends Controller
{
    public function pending(MarketplaceAccount $account): JsonResponse
    {
        $this->authorize('viewAny', MarketplaceAccount::class);
        $changes = PriceChange::query()->with('variant.product')->where('marketplace_account_id', $account->id)
            ->where('status', PriceChange::PENDING)->latest('id')->limit(500)->get();
        $listings = ListingVariant::query()->whereIn('id', $changes->pluck('listing_variant_id')->filter())->get()->keyBy('id');

        return response()->json(['data' => $changes->map(function (PriceChange $c) use ($listings) {
            $listing = $listings->get($c->listing_variant_id);
            $current = $c->variant?->ref_price;

            return [
                'id' => $c->ulid,
                'product_id' => $c->variant?->product?->ulid,
                'product_name' => $c->variant?->product?->name,
                'variant_name' => $c->variant?->name,
                'sku' => $c->variant?->sku,
                'ecso_price' => $current,
                'website_price' => $c->new_price,
                'on_sale' => $listing && $listing->sale_price !== null && (float) $listing->sale_price > 0,
                'diff_percent' => $current !== null && (float) $current > 0 ? round(((float) $c->new_price - (float) $current) / (float) $current * 100, 1) : null,
                'reason' => $c->reason,
                'created_at' => $c->created_at->toIso8601String(),
            ];
        })->values()]);
    }

    public function approve(PriceChange $change, PriceSync $prices): JsonResponse
    {
        $this->authorize('map', MarketplaceAccount::class);

        return response()->json(['data' => ['id' => $change->ulid, 'status' => $prices->approve($change)->status]]);
    }

    public function reject(PriceChange $change, PriceSync $prices): JsonResponse
    {
        $this->authorize('map', MarketplaceAccount::class);

        return response()->json(['data' => ['id' => $change->ulid, 'status' => $prices->reject($change)->status]]);
    }

    public function approveAll(MarketplaceAccount $account, PriceSync $prices): JsonResponse
    {
        $this->authorize('map', MarketplaceAccount::class);

        return response()->json(['data' => ['applied' => $prices->approveAll($account)]]);
    }

    /** Price source, who holds the price now, the price on each linked website, and the price history. */
    public function product(Product $product, PriceSync $prices, StockReader $stock, ConnectorRegistry $connectors): JsonResponse
    {
        $this->authorize('view', $product);
        $variants = $product->variants()->get();
        $ids = $variants->pluck('id')->all();
        $onHand = $stock->onHand($ids);
        $listings = ListingVariant::query()->with('account')->whereIn('product_variant_id', $ids)
            ->where('mapping_status', ListingVariant::MAPPED)->get()->groupBy('product_variant_id');
        $history = PriceChange::query()->with('account', 'actor', 'variant')->whereIn('product_variant_id', $ids)
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => [
            'variants' => $variants->map(fn (ProductVariant $v) => [
                'id' => $v->ulid,
                'sku' => $v->sku,
                'price_source' => $v->price_source ?? 'auto',
                'holder' => $prices->holder($v, $onHand[$v->id] ?? 0),
                'on_hand' => $onHand[$v->id] ?? 0,
                // Only channels whose API can set prices (websites today); marketplaces join once their apps are approved.
                'channels' => ($listings->get($v->id) ?? collect())->filter(fn (ListingVariant $l) => $l->account !== null && $this->setsPrices($l->account, $connectors))->map(fn (ListingVariant $l) => [
                    'account_id' => $l->account?->ulid,
                    'shop_name' => $l->account?->shop_name,
                    'marketplace' => $l->account?->marketplace,
                    'price_sync' => $l->account !== null && $prices->enabledFor($l->account),
                    'price' => $l->price,
                    'regular_price' => $l->regular_price,
                    'sale_price' => $l->sale_price,
                    'push_status' => $l->price_push_status,
                    'push_error' => $l->price_push_error,
                    'pushed_at' => $l->price_pushed_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'history' => $history->map(fn (PriceChange $c) => [
                'id' => $c->ulid,
                'sku' => $c->variant?->sku,
                'old_price' => $c->old_price,
                'new_price' => $c->new_price,
                'source' => $c->source,
                'shop_name' => $c->account?->shop_name,
                'status' => $c->status,
                'reason' => $c->reason,
                'actor' => $c->actor?->name,
                'created_at' => $c->created_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    private function setsPrices(MarketplaceAccount $account, ConnectorRegistry $connectors): bool
    {
        return $connectors->has($account->marketplace) && $connectors->for($account->marketplace) instanceof UpdatesPrices;
    }

    /** Sets the price source of all (or some) SKUs of a product. */
    public function setSource(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);
        $data = $request->validate([
            'price_source' => ['required', Rule::in(ProductVariant::PRICE_SOURCES)],
            'variant_ids' => ['nullable', 'array'],
            'variant_ids.*' => ['string'],
        ]);
        $variants = $product->variants()->when(! empty($data['variant_ids']), fn ($q) => $q->whereIn('ulid', $data['variant_ids']))->get();
        // Saved one by one so each SKU re-evaluates who holds its price (and is pushed if ECSO now does).
        $variants->each(fn (ProductVariant $v) => $v->forceFill(['price_source' => $data['price_source']])->save());

        return response()->json(['data' => ['updated' => $variants->count()]]);
    }
}
