<?php

use App\Modules\Shipping\Http\Controllers\CarrierAccountController;
use App\Modules\Shipping\Http\Controllers\CarrierShipmentController;
use App\Modules\Shipping\Http\Controllers\CarrierWebhookController;
use Illuminate\Support\Facades\Route;

// Called by carriers: no session, no tenant header. The token picks the account.
Route::post('carrier-webhooks/{token}', CarrierWebhookController::class)
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware('throttle:600,1,shipping.carrier-webhooks')
    ->name('carrier-webhooks.receive');

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('carriers', [CarrierAccountController::class, 'catalog']);
    Route::get('carrier-accounts', [CarrierAccountController::class, 'index']);
    Route::post('carrier-accounts', [CarrierAccountController::class, 'store'])->middleware('throttle:10,1,shipping.carrier-accounts.store');
    Route::put('carrier-accounts/{account}', [CarrierAccountController::class, 'update']);
    Route::post('carrier-accounts/{account}/check', [CarrierAccountController::class, 'check'])->middleware('throttle:10,1,shipping.carrier-accounts.check');
    Route::delete('carrier-accounts/{account}', [CarrierAccountController::class, 'destroy']);

    Route::post('shipping/quotes', [CarrierShipmentController::class, 'quote'])->middleware('throttle:120,1,shipping.quotes');
    Route::post('orders/{order}/carrier-shipments', [CarrierShipmentController::class, 'push'])->middleware(['idempotent', 'throttle:60,1,shipping.push']);
    Route::post('carrier-shipments/bulk', [CarrierShipmentController::class, 'bulk'])->middleware(['idempotent', 'throttle:10,1,shipping.bulk']);
    Route::post('shipments/labels', [CarrierShipmentController::class, 'labels'])->middleware('throttle:30,1,shipping.labels');
    Route::post('shipments/{shipment}/carrier-cancel', [CarrierShipmentController::class, 'cancel'])->middleware('throttle:30,1,shipping.cancel');
    Route::post('shipments/{shipment}/carrier-discard', [CarrierShipmentController::class, 'discard']);
    Route::post('shipments/{shipment}/carrier-refresh', [CarrierShipmentController::class, 'refresh'])->middleware('throttle:30,1,shipping.refresh');
});
