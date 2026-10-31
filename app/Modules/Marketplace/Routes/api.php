<?php

use App\Modules\Marketplace\Http\Controllers\FakeMarketplaceController;
use App\Modules\Marketplace\Http\Controllers\ListingController;
use App\Modules\Marketplace\Http\Controllers\MarketplaceAccountController;
use App\Modules\Marketplace\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Called by marketplaces: no session, no tenant header. Rate limited per IP.
Route::post('webhooks/{marketplace}', WebhookController::class)
    ->where('marketplace', '[a-z]+')
    ->middleware('throttle:600,1')
    ->name('webhooks.receive');

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('marketplaces', [MarketplaceAccountController::class, 'catalog']);
    Route::get('marketplace-accounts', [MarketplaceAccountController::class, 'index']);
    Route::post('marketplace-accounts/fake', [MarketplaceAccountController::class, 'connectFake']);
    Route::patch('marketplace-accounts/{account}', [MarketplaceAccountController::class, 'update']);
    Route::post('marketplace-accounts/{account}/disconnect', [MarketplaceAccountController::class, 'disconnect']);
    Route::post('marketplace-accounts/{account}/sync-products', [MarketplaceAccountController::class, 'syncProducts'])->middleware('throttle:10,1');
    Route::post('marketplace-accounts/{account}/push-stock', [MarketplaceAccountController::class, 'pushStock'])->middleware('throttle:10,1');
    Route::get('marketplace-webhooks', [MarketplaceAccountController::class, 'webhooks']);

    Route::get('marketplace-accounts/{account}/listings', [ListingController::class, 'index']);
    Route::post('marketplace-accounts/{account}/listings/confirm-suggestions', [ListingController::class, 'confirmSuggestions']);
    Route::put('listings/{listing}/mapping', [ListingController::class, 'map']);
    Route::delete('listings/{listing}/mapping', [ListingController::class, 'unmap']);

    Route::prefix('fake-marketplace/{account}')->group(function () {
        Route::get('/', [FakeMarketplaceController::class, 'state']);
        Route::post('orders', [FakeMarketplaceController::class, 'createOrders']);
        Route::post('orders/{orderSn}/status', [FakeMarketplaceController::class, 'setStatus']);
        Route::post('orders/{orderSn}/replay', [FakeMarketplaceController::class, 'replay']);
        Route::post('failure', [FakeMarketplaceController::class, 'failure']);
    });
});
