<?php

use App\Modules\Admin\Http\Controllers\AdminBillingController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminTenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:sanctum', 'super-admin'])->group(function () {
    Route::get('dashboard', AdminDashboardController::class);
    Route::get('tenants', [AdminTenantController::class, 'index']);
    Route::get('billing/summary', [AdminBillingController::class, 'summary']);
    Route::get('invoices', [AdminBillingController::class, 'invoices']);
    Route::post('invoices/{invoice}/payments', [AdminBillingController::class, 'confirmPayment'])->middleware('throttle:30,1');
    Route::post('invoices/{invoice}/cancel', [AdminBillingController::class, 'cancelInvoice']);
});
