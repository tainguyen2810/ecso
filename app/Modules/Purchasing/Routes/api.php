<?php

use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\ReorderController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Purchasing\Http\Controllers\SupplierPaymentController;
use App\Modules\Purchasing\Http\Controllers\SupplierReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('suppliers/export', [SupplierController::class, 'export'])->middleware('throttle:10,1,purchasing.suppliers.export');
    Route::apiResource('suppliers', SupplierController::class);
    Route::post('suppliers/{supplier}/payments', [SupplierPaymentController::class, 'store'])->middleware('idempotent');
    Route::post('supplier-payments/{supplierPayment}/void', [SupplierPaymentController::class, 'void']);

    Route::get('purchase-orders/reorder', [ReorderController::class, 'index']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->except('destroy');
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);
    Route::post('purchase-orders/{purchaseOrder}/actions/{action}', [PurchaseOrderController::class, 'action'])
        ->whereIn('action', ['place', 'close', 'cancel']);

    Route::apiResource('supplier-returns', SupplierReturnController::class)->only(['index', 'show', 'store']);
});
