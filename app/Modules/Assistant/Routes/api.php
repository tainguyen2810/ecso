<?php

use App\Modules\Assistant\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->prefix('assistant')->group(function () {
    Route::get('status', [AssistantController::class, 'status']);
    Route::post('invoice', [AssistantController::class, 'invoice'])->middleware('throttle:20,1,assistant.invoice');
    Route::post('products', [AssistantController::class, 'products'])->middleware('throttle:20,1,assistant.products');
});
