<?php

use App\Modules\Admin\Http\Controllers\AdminBillingController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminMonitoringController;
use App\Modules\Admin\Http\Controllers\AdminPlanController;
use App\Modules\Admin\Http\Controllers\AdminTenantController;
use App\Modules\Admin\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Two-factor setup/verification: reachable before 2FA has been passed.
Route::prefix('admin/2fa')->middleware(['auth:sanctum', 'super-admin:setup'])->group(function () {
    Route::get('/', [TwoFactorController::class, 'status']);
    Route::post('setup', [TwoFactorController::class, 'setup'])->middleware('throttle:5,1,admin.setup');
    Route::post('confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:5,1,admin.confirm');
    Route::post('verify', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1,admin.verify');
});

Route::prefix('admin')->middleware(['auth:sanctum', 'super-admin'])->group(function () {
    Route::get('dashboard', AdminDashboardController::class);
    Route::get('tenants', [AdminTenantController::class, 'index']);
    Route::get('monitoring', AdminMonitoringController::class);
    Route::get('billing/summary', [AdminBillingController::class, 'summary']);
    Route::get('invoices', [AdminBillingController::class, 'invoices']);
    Route::post('invoices/{invoice}/payments', [AdminBillingController::class, 'confirmPayment'])->middleware('throttle:30,1,admin.invoices.x.payments');
    Route::post('invoices/{invoice}/cancel', [AdminBillingController::class, 'cancelInvoice']);
    Route::get('plans', [AdminPlanController::class, 'index']);
    Route::post('plans', [AdminPlanController::class, 'store']);
    Route::post('plans/reorder', [AdminPlanController::class, 'reorder']);
    Route::put('plans/{plan}', [AdminPlanController::class, 'update']);
    Route::delete('plans/{plan}', [AdminPlanController::class, 'destroy']);
});
