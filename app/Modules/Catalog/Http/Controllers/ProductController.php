<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Http\Resources\VariantResource;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Product::STATUSES)],
            'category' => ['nullable', 'string'],
            'brand' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $stock = DB::table('stock_balances')
            ->join('product_variants', 'product_variants.id', '=', 'stock_balances.product_variant_id')
            ->where('stock_balances.tenant_id', app(TenantContext::class)->id())
            ->whereNull('product_variants.deleted_at')
            ->selectRaw('product_variants.product_id, SUM(stock_balances.on_hand) as on_hand, SUM(stock_balances.reserved) as reserved')
            ->groupBy('product_variants.product_id');

        $products = Product::query()
            ->with(['category', 'brand', 'variants'])
            ->withCount('variants')
            ->leftJoinSub($stock, 's', 's.product_id', '=', 'products.id')
            ->select('products.*', 's.on_hand', 's.reserved')
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $q->where(fn (Builder $w) => $w->where('products.name', 'like', "%{$term}%")
                    ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', "%{$term}%")->orWhere('barcode', $term)));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('products.status', $s), fn (Builder $q) => $q->where('products.status', '!=', Product::STATUS_ARCHIVED))
            ->when($filters['category'] ?? null, fn (Builder $q, string $c) => $q->whereIn('category_id', Category::query()->where('ulid', $c)->select('id')))
            ->when($filters['brand'] ?? null, fn (Builder $q, string $b) => $q->whereIn('brand_id', Brand::query()->where('ulid', $b)->select('id')))
            ->orderByDesc('products.id')
            ->paginate($filters['per_page'] ?? 20);

        return ProductResource::collection($products);
    }

    public function store(ProductRequest $request, ProductService $service): JsonResponse
    {
        $this->authorize('create', Product::class);
        $product = $service->create($request->payload());

        return (new ProductResource($product->load(['category', 'brand', 'variants', 'images'])))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load(['category', 'brand', 'variants', 'images']));
    }

    public function update(ProductRequest $request, Product $product, ProductService $service): ProductResource
    {
        $this->authorize('update', $product);
        $service->update($product, $request->payload());

        return new ProductResource($product->refresh()->load(['category', 'brand', 'variants', 'images']));
    }

    public function destroy(Product $product, ProductService $service): JsonResponse
    {
        $this->authorize('delete', $product);
        $service->delete($product);

        return response()->json(null, 204);
    }

    public function bulkStatus(Request $request, ProductService $service): JsonResponse
    {
        $this->authorize('update', Product::class);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string'],
            'status' => ['required', Rule::in(Product::STATUSES)],
        ]);

        $ids = Product::query()->whereIn('ulid', $data['ids'])->pluck('id')->all();

        return response()->json(['updated' => $service->bulkStatus($ids, $data['status'])]);
    }

    /**
     * Live SKU check for the product form.
     */
    public function checkSku(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);
        $data = $request->validate(['sku' => ['required', 'string', 'max:64'], 'ignore' => ['nullable', 'string']]);

        $variant = ProductVariant::query()->withTrashed()->with('product')
            ->where('sku', trim($data['sku']))
            ->when($data['ignore'] ?? null, fn ($q, $ulid) => $q->where('ulid', '!=', $ulid))
            ->first();

        return response()->json([
            'available' => $variant === null,
            'product' => $variant?->product ? ['id' => $variant->product->ulid, 'name' => $variant->product->name] : null,
        ]);
    }

    /**
     * SKU picker for inventory documents. With ?warehouse= includes that warehouse's balance.
     */
    public function variants(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'warehouse' => ['nullable', 'string']]);

        $query = ProductVariant::query()->with('product')
            ->whereHas('product', fn ($q) => $q->where('status', '!=', Product::STATUS_ARCHIVED))
            ->when($data['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('sku', 'like', "%{$term}%")->orWhere('barcode', $term)->orWhere('product_variants.name', 'like', "%{$term}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"))))
            ->orderBy('sku')->limit(30);

        if ($warehouse = $data['warehouse'] ?? null) {
            $warehouseId = DB::table('warehouses')->where('tenant_id', app(TenantContext::class)->id())->where('ulid', $warehouse)->value('id');
            $query->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_variant_id', '=', 'product_variants.id')->where('b.warehouse_id', $warehouseId))
                ->select('product_variants.*', DB::raw('COALESCE(b.on_hand, 0) as on_hand'), DB::raw('COALESCE(b.reserved, 0) as reserved'));
        }

        return VariantResource::collection($query->get());
    }
}
