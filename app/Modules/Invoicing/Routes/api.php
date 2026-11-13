<?php

use App\Modules\Invoicing\Http\Controllers\EInvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('einvoice/settings', [EInvoiceController::class, 'settings']);
    Route::post('einvoice/account', [EInvoiceController::class, 'connect'])->middleware('throttle:10,1,einvoice.connect');
    Route::put('einvoice/account', [EInvoiceController::class, 'update']);
    Route::delete('einvoice/account', [EInvoiceController::class, 'disconnect']);
    Route::get('orders/{order}/einvoice', [EInvoiceController::class, 'forOrder']);
    Route::post('orders/{order}/einvoice', [EInvoiceController::class, 'issue'])->middleware('throttle:60,1,einvoice.issue');
    Route::post('einvoices/{einvoice}/refresh', [EInvoiceController::class, 'refresh'])->middleware('throttle:60,1,einvoice.refresh');
});
