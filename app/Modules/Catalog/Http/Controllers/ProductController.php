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
use App\Modules\Catalog\Services\ComboResolver;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /**
     * A combo has no stock rows: show the number of whole combos its components make as on hand and available.
     *
     * @param  iterable<Product|ProductVariant>  $models
     */
    private function withComboStock(iterable $models, StockReader $stockReader, ?int $warehouseId = null): void
    {
        $byVariant = [];
        foreach ($models as $model) {
            $variant = $model instanceof Product ? ($model->isCombo() ? $model->variants->first() : null) : $model;
            if ($variant) {
                $byVariant[$variant->id] = $model;
            }
        }
        $combos = array_intersect_key($stockReader->available(array_keys($byVariant), $warehouseId), app(ComboResolver::class)->componentsOf(array_keys($byVariant)));
        foreach ($combos as $variantId => $available) {
            $byVariant[$variantId]->setAttribute('on_hand', $available)->setAttribute('reserved', 0);
        }
    }

    public function index(Request $request, StockReader $stockReader): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Product::STATUSES)],
            'category' => ['nullable', 'string'],
            'brand' => ['nullable', 'string'],
            'tag' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', Rule::in([Product::TYPE_STANDARD, Product::TYPE_COMBO])],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name', 'available', '-available'])],
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
            ->leftJoinSub($stock, 's', 's.product_id', '=', 'products.id')
            ->select('products.*', 's.on_hand', 's.reserved')
            // After select(): select() replaces the column list, which would drop the count.
            ->withCount('variants')
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $q->where(fn (Builder $w) => $w->where('products.name', 'like', "%{$term}%")
                    ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', "%{$term}%")->orWhere('barcode', $term)));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('products.status', $s), fn (Builder $q) => $q->where('products.status', '!=', Product::STATUS_ARCHIVED))
            ->when($filters['category'] ?? null, fn (Builder $q, string $c) => $q->whereIn('category_id', Category::query()->where('ulid', $c)->select('id')))
            ->when($filters['brand'] ?? null, fn (Builder $q, string $b) => $q->whereIn('brand_id', Brand::query()->where('ulid', $b)->select('id')))
            ->when($filters['tag'] ?? null, fn (Builder $q, string $t) => $q->whereJsonContains('products.tags', $t))
            ->when($filters['type'] ?? null, fn (Builder $q, string $t) => $q->where('products.type', $t))
            ->when($filters['created_from'] ?? null, fn (Builder $q, string $d) => $q->where('products.created_at', '>=', $d))
            ->when($filters['created_to'] ?? null, fn (Builder $q, string $d) => $q->where('products.created_at', '<', now()->parse($d)->addDay()))
            ->tap(fn (Builder $q) => match ($filters['sort'] ?? 'newest') {
                'oldest' => $q->orderBy('products.id'),
                'name' => $q->orderBy('products.name'),
                'available' => $q->orderByRaw('COALESCE(s.on_hand, 0) - COALESCE(s.reserved, 0)')->orderByDesc('products.id'),
                '-available' => $q->orderByRaw('COALESCE(s.on_hand, 0) - COALESCE(s.reserved, 0) DESC')->orderByDesc('products.id'),
                default => $q->orderByDesc('products.id'),
            })
            ->paginate($filters['per_page'] ?? 20);

        $this->withComboStock($products->getCollection(), $stockReader);

        return ProductResource::collection($products);
    }

    public function store(ProductRequest $request, ProductService $service): JsonResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->payload();

        $warehouse = null;
        if (collect($data['variants'])->contains(fn ($v) => (int) ($v['opening_qty'] ?? 0) > 0)) {
            $this->authorize('receive', StockReceipt::class);
            $warehouse = Warehouse::query()->where('ulid', $data['opening_warehouse_id'] ?? '')->first()
                ?? throw ValidationException::withMessages(['opening_warehouse_id' => 'Chọn kho để ghi tồn ban đầu.']);
        }

        $product = $service->create($data, $warehouse);

        return (new ProductResource($product->load(['category', 'brand', 'variants.components.component.product', 'images'])))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load(['category', 'brand', 'variants.components.component.product', 'images']));
    }

    public function update(ProductRequest $request, Product $product, ProductService $service): ProductResource
    {
        $this->authorize('update', $product);
        $service->update($product, $request->payload());

        return new ProductResource($product->refresh()->load(['category', 'brand', 'variants.components.component.product', 'images']));
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

    public function bulkDelete(Request $request, ProductService $service): JsonResponse
    {
        $this->authorize('delete', Product::class);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string'],
        ]);

        return response()->json($service->bulkDelete(Product::query()->whereIn('ulid', $data['ids'])->get()));
    }

    /** Tags in use, for the filter and the form's suggestions. */
    public function tags(): JsonResponse
    {
        $this->authorize('viewAny', Product::class);
        $tags = Product::query()->whereNotNull('tags')->pluck('tags')->flatten()->unique(fn ($t) => mb_strtolower($t))->sort()->values();

        return response()->json(['data' => $tags]);
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
    public function variants(Request $request, StockReader $stockReader): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'warehouse' => ['nullable', 'string'],
            // Exact SKUs (label printing): by variant or by product ULIDs.
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['string'],
            'products' => ['nullable', 'array', 'max:200'],
            'products.*' => ['string'],
            // Stock documents (receipts, counts, transfers, purchases): combos have no stock of their own.
            'stocked' => ['nullable', 'boolean'],
        ]);
        $exact = ! empty($data['ids']) || ! empty($data['products']);

        $query = ProductVariant::query()->with(['product', 'components.component'])
            ->when($data['stocked'] ?? false, fn (Builder $q) => $q->whereHas('product', fn ($p) => $p->where('type', '!=', Product::TYPE_COMBO)))
            ->when($data['ids'] ?? null, fn (Builder $q, array $ids) => $q->whereIn('product_variants.ulid', $ids))
            ->when($data['products'] ?? null, fn (Builder $q, array $ids) => $q->whereIn('product_id', Product::query()->whereIn('ulid', $ids)->select('id')))
            ->unless($exact, fn (Builder $q) => $q->whereHas('product', fn ($p) => $p->where('status', '!=', Product::STATUS_ARCHIVED)))
            ->when($data['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('sku', 'like', "%{$term}%")->orWhere('barcode', $term)->orWhere('product_variants.name', 'like', "%{$term}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"))))
            ->when($exact, fn (Builder $q) => $q->orderBy('product_id')->orderBy('product_variants.id'), fn (Builder $q) => $q->orderBy('sku'))
            ->limit($exact ? 500 : 30);

        $warehouseId = null;
        if ($warehouse = $data['warehouse'] ?? null) {
            $warehouseId = DB::table('warehouses')->where('tenant_id', app(TenantContext::class)->id())->where('ulid', $warehouse)->value('id');
            $query->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_variant_id', '=', 'product_variants.id')->where('b.warehouse_id', $warehouseId))
                ->select('product_variants.*', DB::raw('COALESCE(b.on_hand, 0) as on_hand'), DB::raw('COALESCE(b.reserved, 0) as reserved'));
        }

        $variants = $query->get();
        if ($warehouseId) {
            $this->withComboStock($variants, $stockReader, $warehouseId);
        }

        return VariantResource::collection($variants);
    }
}
