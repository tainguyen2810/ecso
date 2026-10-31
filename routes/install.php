<?php

use App\Modules\Platform\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// Registered without the `web` group on purpose: see InstallController.
Route::get('/install', [InstallController::class, 'page']);
Route::prefix('install/api')->group(function () {
    Route::get('status', [InstallController::class, 'status']);
    Route::post('requirements', [InstallController::class, 'requirements']);
    Route::post('database', [InstallController::class, 'database']);
    Route::post('redis', [InstallController::class, 'redis']);
    Route::post('run', [InstallController::class, 'install']);
});
