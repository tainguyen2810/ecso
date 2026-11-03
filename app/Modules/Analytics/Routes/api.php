<?php

use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use App\Modules\Analytics\Http\Controllers\ReportsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('finance/summary', [AnalyticsController::class, 'finance']);
    Route::get('reports/best-sellers', [AnalyticsController::class, 'bestSellers']);
    Route::get('reports/slow-movers', [AnalyticsController::class, 'slowMovers']);
    Route::get('reports/sales', [AnalyticsController::class, 'sales']);
    Route::get('reports/stock-ledger', [AnalyticsController::class, 'stockLedger']);
    Route::get('reports/cashbook', [AnalyticsController::class, 'cashbook']);

    Route::get('reports/overview', [ReportsController::class, 'overview']);
    Route::get('reports/product-sales', [ReportsController::class, 'productSales']);
    Route::get('reports/returns', [ReportsController::class, 'returns']);
    Route::get('reports/payments', [ReportsController::class, 'payments']);
    Route::get('reports/purchases', [ReportsController::class, 'purchases']);
    Route::get('reports/customers', [ReportsController::class, 'customers']);
    Route::get('reports/stock-on-hand', [ReportsController::class, 'stockOnHand']);
    Route::get('reports/low-stock', [ReportsController::class, 'lowStock']);
    Route::get('reports/over-stock', [ReportsController::class, 'overStock']);
    Route::get('reports/profit-loss', [ReportsController::class, 'profitLoss']);
    Route::get('reports/receivables', [ReportsController::class, 'receivables']);
    Route::get('reports/payables', [ReportsController::class, 'payables']);
});
