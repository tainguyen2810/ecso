<?php

use App\Modules\Marketplace\Http\Controllers\FakeMarketplaceController;
use App\Modules\Marketplace\Http\Controllers\ListingController;
use App\Modules\Marketplace\Http\Controllers\MarketplaceAccountController;
use App\Modules\Marketplace\Http\Controllers\WebhookController;
use App\Modules\Marketplace\Http\Controllers\WooCommerceController;
use Illuminate\Support\Facades\Route;

// Called by marketplaces: no session, no tenant header. Rate limited per IP.
Route::post('webhooks/{marketplace}', WebhookController::class)
    ->where('marketplace', '[a-z]+')
    ->middleware('throttle:600,1,marketplace.webhooks')
    ->name('webhooks.receive');

// Called by a WooCommerce store's server after the owner approves ECSO; the one-time state is the only binding.
Route::post('woocommerce/auth-callback', [WooCommerceController::class, 'receiveKeys'])
    ->middleware('throttle:30,1,marketplace.woocommerce.auth-callback')
    ->name('woocommerce.auth-callback');

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::post('marketplace-accounts/woocommerce/authorize', [WooCommerceController::class, 'authorizeStore'])->middleware('throttle:10,1,marketplace.woocommerce.authorize');
    Route::post('marketplace-accounts/woocommerce/complete', [WooCommerceController::class, 'complete'])->middleware('throttle:10,1,marketplace.woocommerce.complete');
    Route::post('marketplace-accounts/woocommerce/keys', [WooCommerceController::class, 'connectWithKeys'])->middleware('throttle:10,1,marketplace.woocommerce.keys');
    Route::get('marketplaces', [MarketplaceAccountController::class, 'catalog']);
    Route::get('marketplace-accounts', [MarketplaceAccountController::class, 'index']);
    Route::post('marketplace-accounts/authorize/{marketplace}', [MarketplaceAccountController::class, 'authorizeShop'])->where('marketplace', '[a-z]+')->middleware('throttle:10,1,marketplace.marketplace-accounts.authorize.x');
    Route::post('marketplace-accounts/callback/{marketplace}', [MarketplaceAccountController::class, 'completeAuthorization'])->where('marketplace', '[a-z]+')->middleware('throttle:10,1,marketplace.marketplace-accounts.callback.x');
    Route::post('marketplace-accounts/fake', [MarketplaceAccountController::class, 'connectFake']);
    Route::get('marketplace-accounts/{account}', [MarketplaceAccountController::class, 'show']);
    Route::get('marketplace-accounts/{account}/new-products', [MarketplaceAccountController::class, 'newProducts']);
    Route::post('marketplace-accounts/{account}/new-products/approve', [MarketplaceAccountController::class, 'approveNewProducts']);
    Route::post('marketplace-accounts/{account}/new-products/skip', [MarketplaceAccountController::class, 'skipNewProducts']);
    Route::patch('marketplace-accounts/{account}', [MarketplaceAccountController::class, 'update']);
    Route::post('marketplace-accounts/{account}/disconnect', [MarketplaceAccountController::class, 'disconnect']);
    Route::post('marketplace-accounts/{account}/sync-products', [MarketplaceAccountController::class, 'syncProducts'])->middleware('throttle:10,1,marketplace.marketplace-accounts.x.sync-products');
    Route::post('marketplace-accounts/{account}/push-stock', [MarketplaceAccountController::class, 'pushStock'])->middleware('throttle:10,1,marketplace.marketplace-accounts.x.push-stock');
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
