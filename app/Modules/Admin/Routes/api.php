<?php

use App\Modules\Admin\Http\Controllers\AdminBillingController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminMonitoringController;
use App\Modules\Admin\Http\Controllers\AdminTenantController;
use App\Modules\Admin\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Two-factor setup/verification: reachable before 2FA has been passed.
Route::prefix('admin/2fa')->middleware(['auth:sanctum', 'super-admin:setup'])->group(function () {
    Route::get('/', [TwoFactorController::class, 'status']);
    Route::post('setup', [TwoFactorController::class, 'setup'])->middleware('throttle:5,1');
    Route::post('confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:5,1');
    Route::post('verify', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1');
});

Route::prefix('admin')->middleware(['auth:sanctum', 'super-admin'])->group(function () {
    Route::get('dashboard', AdminDashboardController::class);
    Route::get('tenants', [AdminTenantController::class, 'index']);
    Route::get('monitoring', AdminMonitoringController::class);
    Route::get('billing/summary', [AdminBillingController::class, 'summary']);
    Route::get('invoices', [AdminBillingController::class, 'invoices']);
    Route::post('invoices/{invoice}/payments', [AdminBillingController::class, 'confirmPayment'])->middleware('throttle:30,1');
    Route::post('invoices/{invoice}/cancel', [AdminBillingController::class, 'cancelInvoice']);
});
