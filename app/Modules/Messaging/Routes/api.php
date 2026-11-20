<?php

use App\Modules\Messaging\Http\Controllers\PushController;
use App\Modules\Messaging\Http\Controllers\ZaloController;
use Illuminate\Support\Facades\Route;

// The service worker reads the message a push woke it for (keyed by the secret endpoint, no session).
Route::post('push/last', [PushController::class, 'last'])->middleware('throttle:60,1,messaging.push.last');

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('push', [PushController::class, 'key']);
    Route::post('push/subscriptions', [PushController::class, 'subscribe'])->middleware('throttle:20,1,messaging.push.subscribe');
    Route::patch('push/subscriptions', [PushController::class, 'update'])->middleware('throttle:20,1,messaging.push.update');
    Route::delete('push/subscriptions', [PushController::class, 'unsubscribe']);
    Route::post('push/test', [PushController::class, 'test'])->middleware('throttle:6,1,messaging.push.test');
    Route::get('zalo', [ZaloController::class, 'show']);
    Route::post('zalo/account', [ZaloController::class, 'connect'])->middleware(['real-tenant', 'throttle:10,1,zalo.connect']);
    Route::post('zalo/templates/refresh', [ZaloController::class, 'templates'])->middleware('throttle:10,1,zalo.templates');
    Route::put('zalo/rules', [ZaloController::class, 'rules']);
    Route::delete('zalo/account', [ZaloController::class, 'disconnect']);
});
