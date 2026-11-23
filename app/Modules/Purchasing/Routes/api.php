<?php

use App\Modules\Purchasing\Http\Controllers\PurchaseInvoiceController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\ReorderController;
use App\Modules\Purchasing\Http\Controllers\SapoPurchaseImportController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Purchasing\Http\Controllers\SupplierPaymentController;
use App\Modules\Purchasing\Http\Controllers\SupplierReturnController;
use Illuminate\Support\Facades\Route;

// idempotent: a repeated POST with the same Idempotency-Key replays the first answer (no second document).
Route::middleware(['auth:sanctum', 'verified', 'tenant', 'idempotent'])->group(function () {
    Route::get('suppliers/export', [SupplierController::class, 'export'])->middleware('throttle:10,1,purchasing.suppliers.export');
    Route::apiResource('suppliers', SupplierController::class);
    Route::post('suppliers/{supplier}/payments', [SupplierPaymentController::class, 'store'])->middleware('idempotent');
    Route::post('suppliers/{supplier}/refunds', [SupplierPaymentController::class, 'refund'])->middleware('idempotent');
    Route::post('supplier-payments/{supplierPayment}/void', [SupplierPaymentController::class, 'void']);

    Route::get('purchase-invoices/export', [PurchaseInvoiceController::class, 'export'])->middleware('throttle:10,1,purchasing.invoices.export');
    Route::post('purchase-invoices/import', [PurchaseInvoiceController::class, 'import'])->middleware('throttle:20,1,purchasing.invoices.import');
    Route::get('purchase-invoices', [PurchaseInvoiceController::class, 'index']);
    Route::get('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'show']);
    Route::post('purchase-invoices/{purchaseInvoice}/ignore', [PurchaseInvoiceController::class, 'ignore']);
    Route::post('purchase-invoices/{purchaseInvoice}/restore', [PurchaseInvoiceController::class, 'restore']);
    Route::delete('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'destroy']);

    Route::post('purchase-orders/import/sapo', [SapoPurchaseImportController::class, 'import'])->middleware('throttle:30,1,purchasing.orders.import-sapo');
    Route::get('purchase-orders/reorder', [ReorderController::class, 'index']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->except('destroy');
    Route::patch('purchase-orders/{purchaseOrder}/due-date', [PurchaseOrderController::class, 'dueDate']);
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);
    Route::post('purchase-orders/{purchaseOrder}/actions/{action}', [PurchaseOrderController::class, 'action'])
        ->whereIn('action', ['place', 'close', 'cancel']);

    Route::apiResource('supplier-returns', SupplierReturnController::class)->only(['index', 'show', 'store']);
});
