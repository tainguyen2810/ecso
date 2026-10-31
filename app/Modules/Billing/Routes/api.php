<?php

use App\Modules\Billing\Http\Controllers\PaymentNotificationController;
use App\Modules\Billing\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('billing/notifications/{gateway}', PaymentNotificationController::class)
    ->where('gateway', '[a-z_]+')->middleware('throttle:120,1');

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::post('subscription/quote', [SubscriptionController::class, 'quote']);
    Route::post('subscription/change', [SubscriptionController::class, 'change'])->middleware('throttle:10,1');
    Route::post('subscription/trial', [SubscriptionController::class, 'trial'])->middleware('throttle:5,1');
    Route::get('invoices', [SubscriptionController::class, 'invoices']);
    Route::get('invoices/{invoice}', [SubscriptionController::class, 'showInvoice']);
    Route::post('invoices/{invoice}/cancel', [SubscriptionController::class, 'cancelInvoice']);
});
