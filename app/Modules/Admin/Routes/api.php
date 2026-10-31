<?php

use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminTenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'super-admin'])->group(function () {
    Route::get('dashboard', AdminDashboardController::class);
    Route::get('tenants', [AdminTenantController::class, 'index']);
});
