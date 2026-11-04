<?php

use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\ProductImageController;
use App\Modules\Catalog\Http\Controllers\ProductTransferController;
use App\Modules\Catalog\Http\Controllers\TaxonomyController;
use Illuminate\Support\Facades\Route;

// idempotent: a repeated POST with the same Idempotency-Key replays the first answer (no second document).
Route::middleware(['auth:sanctum', 'verified', 'tenant', 'idempotent'])->group(function () {
    Route::get('categories', [TaxonomyController::class, 'categories']);
    Route::post('categories', [TaxonomyController::class, 'storeCategory']);
    Route::patch('categories/{category}', [TaxonomyController::class, 'updateCategory']);
    Route::delete('categories/{category}', [TaxonomyController::class, 'destroyCategory']);
    Route::get('brands', [TaxonomyController::class, 'brands']);
    Route::post('brands', [TaxonomyController::class, 'storeBrand']);
    Route::patch('brands/{brand}', [TaxonomyController::class, 'updateBrand']);
    Route::delete('brands/{brand}', [TaxonomyController::class, 'destroyBrand']);

    Route::get('products/sku-check', [ProductController::class, 'checkSku']);
    Route::get('products/export', [ProductTransferController::class, 'export'])->middleware('throttle:10,1,catalog.products.export');
    Route::get('products/import-template', [ProductTransferController::class, 'template']);
    Route::post('products/import', [ProductTransferController::class, 'import'])->middleware('throttle:10,1,catalog.products.import');
    Route::post('products/bulk-status', [ProductController::class, 'bulkStatus']);
    Route::post('products/bulk-delete', [ProductController::class, 'bulkDelete']);
    Route::get('products/tags', [ProductController::class, 'tags']);
    Route::get('variants', [ProductController::class, 'variants']);
    Route::apiResource('products', ProductController::class);
    Route::post('products/{product}/images', [ProductImageController::class, 'store'])->middleware('throttle:60,1,catalog.products.x.images');
    Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy']);
});
