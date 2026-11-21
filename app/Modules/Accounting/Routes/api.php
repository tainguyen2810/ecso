<?php

use App\Modules\Accounting\Http\Controllers\AccountingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->prefix('accounting')->group(function () {
    Route::get('overview', [AccountingController::class, 'overview']);
    Route::get('settings', [AccountingController::class, 'settings']);
    Route::put('settings', [AccountingController::class, 'saveSettings']);
    Route::get('declaration', [AccountingController::class, 'declaration'])->middleware('throttle:30,1,accounting.declaration');
    Route::get('books/{book}', [AccountingController::class, 'book'])->middleware('throttle:30,1,accounting.books');
});
