<?php

use App\Modules\Invoicing\Http\Controllers\EInvoiceController;
use App\Modules\Invoicing\Http\Controllers\EInvoiceSalesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('einvoice/settings', [EInvoiceController::class, 'settings']);
    Route::post('einvoice/account', [EInvoiceController::class, 'connect'])->middleware(['real-tenant', 'throttle:10,1,einvoice.connect']);
    Route::put('einvoice/account', [EInvoiceController::class, 'update']);
    Route::delete('einvoice/account', [EInvoiceController::class, 'disconnect']);
    Route::get('orders/{order}/einvoice', [EInvoiceController::class, 'forOrder']);
    Route::post('orders/{order}/einvoice', [EInvoiceController::class, 'issue'])->middleware('throttle:60,1,einvoice.issue');
    Route::post('einvoices/{einvoice}/refresh', [EInvoiceController::class, 'refresh'])->middleware('throttle:60,1,einvoice.refresh');
    Route::get('einvoice/packages', [EInvoiceSalesController::class, 'index']);
    Route::post('einvoice/orders', [EInvoiceSalesController::class, 'store'])->middleware(['real-tenant', 'throttle:10,1,einvoice.order']);
    Route::post('einvoice/orders/{einvoiceOrder}/cancel', [EInvoiceSalesController::class, 'cancel']);
    Route::post('einvoice/provider-requests', [EInvoiceSalesController::class, 'requestProvider'])->middleware('throttle:10,1,einvoice.request');
    Route::get('einvoices/{einvoice}/pdf', [EInvoiceController::class, 'pdf'])->middleware('throttle:60,1,einvoice.pdf');
});
