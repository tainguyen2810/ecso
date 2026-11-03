<?php

use App\Modules\Sales\Http\Controllers\CashShiftController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\CustomerGroupController;
use App\Modules\Sales\Http\Controllers\CustomerProfileController;
use App\Modules\Sales\Http\Controllers\CustomerTransferController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\SalesChannelController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Services\ManualOrderService;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::apiResource('sales-channels', SalesChannelController::class)->except('show');

    Route::apiResource('customer-groups', CustomerGroupController::class)->except('show');
    Route::get('customers/tags', [CustomerController::class, 'tags']);
    Route::get('customers/staff', [CustomerController::class, 'staff']);
    Route::post('customers/bulk', [CustomerController::class, 'bulk']);
    Route::get('customers/export', [CustomerTransferController::class, 'export'])->middleware('throttle:10,1');
    Route::get('customers/import-template', [CustomerTransferController::class, 'template']);
    Route::post('customers/import', [CustomerTransferController::class, 'import'])->middleware('throttle:10,1');
    Route::apiResource('customers', CustomerController::class);
    Route::get('customers/{customer}/ledger', [CustomerController::class, 'ledger']);
    Route::post('customers/{customer}/addresses', [CustomerProfileController::class, 'storeAddress']);
    Route::put('customers/{customer}/addresses/{address}', [CustomerProfileController::class, 'updateAddress']);
    Route::delete('customers/{customer}/addresses/{address}', [CustomerProfileController::class, 'destroyAddress']);
    Route::post('customers/{customer}/notes', [CustomerProfileController::class, 'storeNote'])->middleware('throttle:60,1');
    Route::delete('customers/{customer}/notes/{note}', [CustomerProfileController::class, 'destroyNote']);

    Route::get('pos/summary', [PosController::class, 'summary']);
    Route::get('pos/shifts/current', [CashShiftController::class, 'current']);
    Route::get('pos/shifts', [CashShiftController::class, 'index']);
    Route::post('pos/shifts', [CashShiftController::class, 'store'])->middleware('throttle:30,1');
    Route::get('pos/shifts/{shift}', [CashShiftController::class, 'show']);
    Route::post('pos/shifts/{shift}/movements', [CashShiftController::class, 'move']);
    Route::post('pos/shifts/{shift}/close', [CashShiftController::class, 'close']);

    Route::post('sales-orders', [SalesOrderController::class, 'store'])->middleware('throttle:120,1');
    Route::put('sales-orders/{order}', [SalesOrderController::class, 'update']);
    Route::post('sales-orders/{order}/actions/{action}', [SalesOrderController::class, 'action'])
        ->whereIn('action', array_keys(ManualOrderService::ACTIONS));
    Route::post('orders/{order}/payments', [SalesOrderController::class, 'pay']);
    Route::post('payments/{payment}/void', [SalesOrderController::class, 'void']);
});
