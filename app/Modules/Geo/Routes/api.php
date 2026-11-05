<?php

use App\Modules\Geo\Http\Controllers\GeoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->prefix('geo')->group(function () {
    Route::get('provinces', [GeoController::class, 'provinces']);
    Route::get('provinces/{province}/wards', [GeoController::class, 'wards'])->where('province', '[0-9]{2,3}');
    Route::get('suggest', [GeoController::class, 'suggest'])->middleware('throttle:120,1,geo.suggest');
});
