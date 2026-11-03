<?php

use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\SalesChannelController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Services\ManualOrderService;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::apiResource('sales-channels', SalesChannelController::class)->except('show');

    Route::get('customers/groups', [CustomerController::class, 'groups']);
    Route::apiResource('customers', CustomerController::class);

    Route::get('pos/summary', [PosController::class, 'summary']);

    Route::post('sales-orders', [SalesOrderController::class, 'store'])->middleware('throttle:120,1');
    Route::put('sales-orders/{order}', [SalesOrderController::class, 'update']);
    Route::post('sales-orders/{order}/actions/{action}', [SalesOrderController::class, 'action'])
        ->whereIn('action', array_keys(ManualOrderService::ACTIONS));
    Route::post('orders/{order}/payments', [SalesOrderController::class, 'pay']);
    Route::post('payments/{payment}/void', [SalesOrderController::class, 'void']);
});
