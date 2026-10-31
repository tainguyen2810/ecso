<?php

use App\Modules\Inventory\Http\Controllers\AdjustmentController;
use App\Modules\Inventory\Http\Controllers\ReceiptController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\TransferController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::apiResource('warehouses', WarehouseController::class)->except('show');

    Route::prefix('inventory')->group(function () {
        Route::get('stock', [StockController::class, 'index']);
        Route::get('summary', [StockController::class, 'summary']);
        Route::get('variants/{variant}/card', [StockController::class, 'card']);
        Route::put('variants/{variant}/safety-stock', [StockController::class, 'safetyStock']);

        Route::get('receipts', [ReceiptController::class, 'index']);
        Route::post('receipts', [ReceiptController::class, 'store']);
        Route::get('receipts/{receipt}', [ReceiptController::class, 'show']);

        Route::get('adjustments', [AdjustmentController::class, 'index']);
        Route::post('adjustments', [AdjustmentController::class, 'store']);
        Route::get('adjustments/{adjustment}', [AdjustmentController::class, 'show']);
        Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve']);
        Route::post('adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject']);
        Route::post('adjustments/{adjustment}/cancel', [AdjustmentController::class, 'cancel']);

        Route::get('transfers', [TransferController::class, 'index']);
        Route::post('transfers', [TransferController::class, 'store']);
        Route::get('transfers/{transfer}', [TransferController::class, 'show']);
        Route::post('transfers/{transfer}/dispatch', [TransferController::class, 'dispatchTransfer']);
        Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive']);
        Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel']);
    });
});
