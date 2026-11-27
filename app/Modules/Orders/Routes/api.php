<?php

use App\Modules\Orders\Http\Controllers\OrderCodeController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\PackingController;
use App\Modules\Orders\Http\Controllers\ReturnController;
use App\Modules\Orders\Http\Controllers\SapoOrderImportController;
use App\Modules\Orders\Http\Controllers\ShipmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    // Cấu hình › Mã đơn hàng
    Route::get('order-codes', [OrderCodeController::class, 'show']);
    Route::put('order-codes', [OrderCodeController::class, 'update']);
    Route::post('order-codes/start', [OrderCodeController::class, 'start'])->middleware('throttle:5,1,orders.codes.start');
    Route::get('shipments', [ShipmentController::class, 'index']);
    Route::get('shipments/summary', [ShipmentController::class, 'summary']);
    Route::get('shipments/export', [ShipmentController::class, 'export'])->middleware('throttle:10,1,orders.shipments.export');
    Route::post('shipments/import/preview', [ShipmentController::class, 'importPreview'])->middleware('throttle:20,1,orders.shipments.import-preview');
    Route::post('shipments/import', [ShipmentController::class, 'import'])->middleware(['idempotent', 'throttle:10,1,orders.shipments.import']);
    Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);
    Route::put('shipments/{shipment}', [ShipmentController::class, 'update']);
    Route::post('shipments/{shipment}/actions/{action}', [ShipmentController::class, 'action'])->whereIn('action', ['pick_up', 'deliver', 'fail', 'redeliver', 'return', 'cancel']);
    Route::post('orders/{order}/shipments', [ShipmentController::class, 'store'])->middleware('idempotent');
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/summary', [OrderController::class, 'summary']);
    Route::get('orders/pipeline', [OrderController::class, 'pipeline']);
    Route::get('orders/to-arrange', [OrderController::class, 'toArrange']);
    Route::post('orders/import/sapo', [SapoOrderImportController::class, 'import'])->middleware('throttle:60,1,orders.orders.import-sapo');
    Route::get('orders/export', [OrderController::class, 'export'])->middleware('throttle:10,1,orders.orders.export');
    Route::get('orders/assignees', [OrderController::class, 'assignees']);
    Route::get('orders/tags', [OrderController::class, 'tags']);
    Route::post('orders/bulk', [OrderController::class, 'bulk'])->middleware('throttle:30,1,orders.orders.bulk');
    Route::post('orders/packing-slips', [OrderController::class, 'packingSlips']);
    Route::post('orders/ship-options', [OrderController::class, 'shipOptions'])->middleware('throttle:60,1,orders.orders.ship-options');
    Route::post('orders/shipping-labels/plan', [OrderController::class, 'shippingLabelPlan']);
    // The browser asks one order per call (a few at a time), so a batch of 50 labels is 50 calls.
    Route::post('orders/shipping-labels', [OrderController::class, 'shippingLabels'])->middleware('throttle:200,1,orders.orders.shipping-labels');
    Route::post('orders/pick-list', [PackingController::class, 'pickList']);
    Route::get('orders/packing/lookup', [PackingController::class, 'lookup'])->middleware('throttle:240,1,orders.packing.lookup');
    Route::get('orders/packing/stats', [PackingController::class, 'stats']);
    Route::post('orders/{order}/packing/errors', [PackingController::class, 'error'])->middleware('throttle:240,1,orders.packing.errors');
    Route::post('orders/{order}/packing/finish', [PackingController::class, 'finish']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::get('orders/{order}/items/{item}/lots', [OrderController::class, 'itemLots']);
    Route::put('orders/{order}/items/{item}/lots', [OrderController::class, 'updateItemLots']);
    Route::post('orders/{order}/actions/{action}', [OrderController::class, 'action'])->whereIn('action', ['confirm', 'pack', 'handover', 'cancel']);
    Route::put('orders/{order}/assignee', [OrderController::class, 'assign']);
    Route::put('orders/{order}/meta', [OrderController::class, 'meta']);
    Route::post('orders/{order}/notes', [OrderController::class, 'note']);
    Route::post('orders/{order}/payout', [OrderController::class, 'refreshPayout'])->middleware('throttle:30,1,orders.payout');
    Route::post('orders/{order}/sync', [OrderController::class, 'sync'])->middleware('throttle:30,1,orders.orders.x.sync');
    Route::post('orders/{order}/returns', [ReturnController::class, 'store'])->middleware('idempotent');

    Route::get('returns', [ReturnController::class, 'index']);
    Route::post('returns/scan', [ReturnController::class, 'scan'])->middleware('throttle:120,1,returns.scan');
    Route::post('returns/sync', [ReturnController::class, 'syncMarketplace'])->middleware('throttle:6,1,returns.sync');
    Route::get('returns/{return}', [ReturnController::class, 'show']);
    Route::post('returns/{return}/status', [ReturnController::class, 'move']);
    Route::post('returns/{return}/inspect', [ReturnController::class, 'inspect']);
    Route::post('returns/{return}/refund', [ReturnController::class, 'refund'])->middleware('idempotent');
});
