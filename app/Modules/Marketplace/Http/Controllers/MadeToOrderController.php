<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Services\MadeToOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Hàng đặt" (bought after the customer orders, priced by the website) on or off, per product or in bulk. */
class MadeToOrderController extends Controller
{
    public function product(Request $request, Product $product, MadeToOrder $service): JsonResponse
    {
        $this->authorize('update', $product);
        $data = $request->validate([
            'sourcing' => ['required', Rule::in(ProductVariant::SOURCINGS)],
            'virtual_stock' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'variant_ids' => ['nullable', 'array'],
            'variant_ids.*' => ['string'],
        ]);
        $variants = $product->variants()->when(! empty($data['variant_ids']), fn ($q) => $q->whereIn('ulid', $data['variant_ids']))->get();

        return response()->json(['data' => ['updated' => $service->set($variants, $data['sourcing'], $data['virtual_stock'] ?? null)]]);
    }

    public function bulk(Request $request, MadeToOrder $service): JsonResponse
    {
        $this->authorize('update', Product::class);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string'],
            'sourcing' => ['required', Rule::in(ProductVariant::SOURCINGS)],
        ]);
        $variants = ProductVariant::query()->whereIn('product_id', Product::query()->whereIn('ulid', $data['ids'])->select('id'))->get();

        return response()->json(['updated' => $service->set($variants, $data['sourcing'])]);
    }
}
