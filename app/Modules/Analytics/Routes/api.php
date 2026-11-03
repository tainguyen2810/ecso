<?php

use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('finance/summary', [AnalyticsController::class, 'finance']);
    Route::get('reports/best-sellers', [AnalyticsController::class, 'bestSellers']);
    Route::get('reports/slow-movers', [AnalyticsController::class, 'slowMovers']);
    Route::get('reports/sales', [AnalyticsController::class, 'sales']);
    Route::get('reports/stock-ledger', [AnalyticsController::class, 'stockLedger']);
    Route::get('reports/cashbook', [AnalyticsController::class, 'cashbook']);
});
