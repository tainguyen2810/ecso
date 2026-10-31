<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageController extends Controller
{
    public const MAX_IMAGES = 9;

    public function store(Request $request, Product $product, TenantContext $context): JsonResponse
    {
        $this->authorize('update', $product);
        // mimes checks the real content type (finfo), not the client-supplied extension only.
        $request->validate(['image' => ['required', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp', 'dimensions:max_width=5000,max_height=5000']]);
        abort_if($product->images()->count() >= self::MAX_IMAGES, 422, 'Tối đa '.self::MAX_IMAGES.' ảnh mỗi sản phẩm.');

        $file = $request->file('image');
        $path = $file->storeAs("tenants/{$context->tenant()->ulid}/products", Str::random(40).'.'.$file->extension(), 'public');

        $image = new ProductImage(['path' => $path, 'sort' => (int) $product->images()->max('sort') + 1]);
        $image->product()->associate($product)->save();

        return response()->json(['id' => $image->id, 'url' => $image->url()], 201);
    }

    public function destroy(Product $product, int $image): JsonResponse
    {
        $this->authorize('update', $product);
        $model = $product->images()->whereKey($image)->firstOrFail();
        Storage::disk('public')->delete($model->path);
        $model->delete();

        return response()->json(null, 204);
    }
}
