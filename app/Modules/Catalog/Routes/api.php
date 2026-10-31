<?php

use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\ProductImageController;
use App\Modules\Catalog\Http\Controllers\ProductTransferController;
use App\Modules\Catalog\Http\Controllers\TaxonomyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('categories', [TaxonomyController::class, 'categories']);
    Route::post('categories', [TaxonomyController::class, 'storeCategory']);
    Route::patch('categories/{category}', [TaxonomyController::class, 'updateCategory']);
    Route::delete('categories/{category}', [TaxonomyController::class, 'destroyCategory']);
    Route::get('brands', [TaxonomyController::class, 'brands']);
    Route::post('brands', [TaxonomyController::class, 'storeBrand']);
    Route::patch('brands/{brand}', [TaxonomyController::class, 'updateBrand']);
    Route::delete('brands/{brand}', [TaxonomyController::class, 'destroyBrand']);

    Route::get('products/sku-check', [ProductController::class, 'checkSku']);
    Route::get('products/export', [ProductTransferController::class, 'export'])->middleware('throttle:10,1');
    Route::get('products/import-template', [ProductTransferController::class, 'template']);
    Route::post('products/import', [ProductTransferController::class, 'import'])->middleware('throttle:10,1');
    Route::post('products/bulk-status', [ProductController::class, 'bulkStatus']);
    Route::get('variants', [ProductController::class, 'variants']);
    Route::apiResource('products', ProductController::class);
    Route::post('products/{product}/images', [ProductImageController::class, 'store'])->middleware('throttle:60,1');
    Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy']);
});
