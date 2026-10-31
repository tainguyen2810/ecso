<?php

use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\ReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/summary', [OrderController::class, 'summary']);
    Route::get('orders/assignees', [OrderController::class, 'assignees']);
    Route::post('orders/bulk', [OrderController::class, 'bulk'])->middleware('throttle:30,1');
    Route::post('orders/packing-slips', [OrderController::class, 'packingSlips']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/actions/{action}', [OrderController::class, 'action'])->whereIn('action', ['confirm', 'pack', 'handover', 'cancel']);
    Route::put('orders/{order}/assignee', [OrderController::class, 'assign']);
    Route::post('orders/{order}/notes', [OrderController::class, 'note']);
    Route::post('orders/{order}/sync', [OrderController::class, 'sync'])->middleware('throttle:30,1');
    Route::post('orders/{order}/returns', [ReturnController::class, 'store']);

    Route::get('returns', [ReturnController::class, 'index']);
    Route::get('returns/{return}', [ReturnController::class, 'show']);
    Route::post('returns/{return}/status', [ReturnController::class, 'move']);
    Route::post('returns/{return}/inspect', [ReturnController::class, 'inspect']);
});
