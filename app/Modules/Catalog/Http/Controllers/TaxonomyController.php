<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Http\Resources\BrandResource;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Categories and brands: small lookup lists managed with products.manage.
 */
class TaxonomyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function categories(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        return CategoryResource::collection(Category::query()->with('parent')->withCount('products')->orderBy('name')->get());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'string'],
        ]);
        $parentId = isset($data['parent_id']) ? Category::query()->where('ulid', $data['parent_id'])->value('id') ?? abort(422, 'Danh mục cha không hợp lệ.') : null;
        $this->assertUnique(Category::query()->where('parent_id', $parentId), $data['name']);

        $category = Category::create(['name' => trim($data['name']), 'parent_id' => $parentId]);
        $this->audit->record('category.created', $category, ['name' => $category->name]);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function updateCategory(Request $request, Category $category): CategoryResource
    {
        $this->authorize('update', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $this->assertUnique(Category::query()->where('parent_id', $category->parent_id)->where('id', '!=', $category->id), $data['name']);
        $category->update(['name' => trim($data['name'])]);

        return new CategoryResource($category);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        $this->authorize('delete', Product::class);
        $category->delete();
        $this->audit->record('category.deleted', $category, ['name' => $category->name]);

        return response()->json(null, 204);
    }

    public function brands(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        return BrandResource::collection(Brand::query()->withCount('products')->orderBy('name')->get());
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:120',
            Rule::unique('brands', 'name')->where('tenant_id', app(TenantContext::class)->id())]]);
        $brand = Brand::create(['name' => trim($data['name'])]);
        $this->audit->record('brand.created', $brand, ['name' => $brand->name]);

        return (new BrandResource($brand))->response()->setStatusCode(201);
    }

    public function updateBrand(Request $request, Brand $brand): BrandResource
    {
        $this->authorize('update', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:120',
            Rule::unique('brands', 'name')->where('tenant_id', app(TenantContext::class)->id())->ignore($brand->id)]]);
        $brand->update(['name' => trim($data['name'])]);

        return new BrandResource($brand);
    }

    public function destroyBrand(Brand $brand): JsonResponse
    {
        $this->authorize('delete', Product::class);
        $brand->delete();
        $this->audit->record('brand.deleted', $brand, ['name' => $brand->name]);

        return response()->json(null, 204);
    }

    private function assertUnique($query, string $name): void
    {
        abort_if($query->where('name', trim($name))->exists(), 422, 'Tên đã tồn tại.');
    }
}
