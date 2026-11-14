<?php

use App\Modules\Messaging\Http\Controllers\ZaloController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('zalo', [ZaloController::class, 'show']);
    Route::post('zalo/account', [ZaloController::class, 'connect'])->middleware(['real-tenant', 'throttle:10,1,zalo.connect']);
    Route::post('zalo/templates/refresh', [ZaloController::class, 'templates'])->middleware('throttle:10,1,zalo.templates');
    Route::put('zalo/rules', [ZaloController::class, 'rules']);
    Route::delete('zalo/account', [ZaloController::class, 'disconnect']);
});
