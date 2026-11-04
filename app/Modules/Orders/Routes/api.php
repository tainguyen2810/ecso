<?php

use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\ReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/summary', [OrderController::class, 'summary']);
    Route::get('orders/pipeline', [OrderController::class, 'pipeline']);
    Route::get('orders/export', [OrderController::class, 'export'])->middleware('throttle:10,1,orders.orders.export');
    Route::get('orders/assignees', [OrderController::class, 'assignees']);
    Route::get('orders/tags', [OrderController::class, 'tags']);
    Route::post('orders/bulk', [OrderController::class, 'bulk'])->middleware('throttle:30,1,orders.orders.bulk');
    Route::post('orders/packing-slips', [OrderController::class, 'packingSlips']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/actions/{action}', [OrderController::class, 'action'])->whereIn('action', ['confirm', 'pack', 'handover', 'cancel']);
    Route::put('orders/{order}/assignee', [OrderController::class, 'assign']);
    Route::put('orders/{order}/meta', [OrderController::class, 'meta']);
    Route::post('orders/{order}/notes', [OrderController::class, 'note']);
    Route::post('orders/{order}/sync', [OrderController::class, 'sync'])->middleware('throttle:30,1,orders.orders.x.sync');
    Route::post('orders/{order}/returns', [ReturnController::class, 'store'])->middleware('idempotent');

    Route::get('returns', [ReturnController::class, 'index']);
    Route::get('returns/{return}', [ReturnController::class, 'show']);
    Route::post('returns/{return}/status', [ReturnController::class, 'move']);
    Route::post('returns/{return}/inspect', [ReturnController::class, 'inspect']);
});
