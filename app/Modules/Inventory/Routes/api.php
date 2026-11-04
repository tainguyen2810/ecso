<?php

use App\Modules\Inventory\Http\Controllers\AdjustmentController;
use App\Modules\Inventory\Http\Controllers\CostAdjustmentController;
use App\Modules\Inventory\Http\Controllers\ReceiptController;
use App\Modules\Inventory\Http\Controllers\ReconciliationController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\TransferController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

// idempotent: a repeated POST with the same Idempotency-Key replays the first answer (no second document).
Route::middleware(['auth:sanctum', 'verified', 'tenant', 'idempotent'])->group(function () {
    Route::apiResource('warehouses', WarehouseController::class)->except('show');

    Route::prefix('inventory')->group(function () {
        Route::get('stock', [StockController::class, 'index']);
        Route::get('summary', [StockController::class, 'summary']);
        Route::get('low-stock', [StockController::class, 'lowStock']);
        Route::get('reconciliation', ReconciliationController::class)->middleware('throttle:20,1,inventory.reconciliation');
        Route::get('variants/{variant}/card', [StockController::class, 'card']);
        Route::get('products/{product}/stock', [StockController::class, 'productStock']);
        Route::get('products/{product}/movements', [StockController::class, 'productMovements']);
        Route::put('variants/{variant}/location', [StockController::class, 'location']);
        Route::put('variants/{variant}/safety-stock', [StockController::class, 'safetyStock']);

        Route::get('receipts', [ReceiptController::class, 'index']);
        Route::post('receipts', [ReceiptController::class, 'store']);
        Route::get('receipts/{receipt}', [ReceiptController::class, 'show']);

        Route::get('adjustments', [AdjustmentController::class, 'index']);
        Route::post('adjustments', [AdjustmentController::class, 'store']);
        Route::get('adjustments/{adjustment}', [AdjustmentController::class, 'show']);
        Route::put('adjustments/{adjustment}', [AdjustmentController::class, 'update']);
        Route::post('adjustments/{adjustment}/balance', [AdjustmentController::class, 'balance']);
        Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve']);
        Route::post('adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject']);
        Route::post('adjustments/{adjustment}/cancel', [AdjustmentController::class, 'cancel']);

        Route::get('cost-adjustments', [CostAdjustmentController::class, 'index']);
        Route::post('cost-adjustments', [CostAdjustmentController::class, 'store']);
        Route::get('cost-adjustments/{costAdjustment}', [CostAdjustmentController::class, 'show']);

        Route::get('transfers', [TransferController::class, 'index']);
        Route::post('transfers', [TransferController::class, 'store']);
        Route::get('transfers/{transfer}', [TransferController::class, 'show']);
        Route::post('transfers/{transfer}/dispatch', [TransferController::class, 'dispatchTransfer']);
        Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive']);
        Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel']);
    });
});
