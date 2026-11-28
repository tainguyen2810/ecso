<?php

use App\Modules\Sales\Http\Controllers\CashShiftController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\CustomerDebtController;
use App\Modules\Sales\Http\Controllers\CustomerGroupController;
use App\Modules\Sales\Http\Controllers\CustomerHistoryController;
use App\Modules\Sales\Http\Controllers\CustomerMergeController;
use App\Modules\Sales\Http\Controllers\CustomerProfileController;
use App\Modules\Sales\Http\Controllers\CustomerTaskController;
use App\Modules\Sales\Http\Controllers\CustomerTransferController;
use App\Modules\Sales\Http\Controllers\EzChatInboxController;
use App\Modules\Sales\Http\Controllers\EzChatLinkController;
use App\Modules\Sales\Http\Controllers\EzChatStoreController;
use App\Modules\Sales\Http\Controllers\LoyaltyController;
use App\Modules\Sales\Http\Controllers\OrderDraftController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\PromotionController;
use App\Modules\Sales\Http\Controllers\SalesChannelController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Http\Controllers\SubscriptionController;
use App\Modules\Sales\Services\ManualOrderService;
use Illuminate\Support\Facades\Route;

// Đợt 7 "Nối với EzChat": EzChat's server-to-server calls, signed (no session).
Route::post('ezchat-link/exchange', [EzChatLinkController::class, 'exchange'])->middleware('throttle:30,1,sales.ezchat-link.exchange');
Route::prefix('ezchat-store/{link}')->middleware('throttle:600,1,sales.ezchat-store')->group(function () {
    Route::get('products', [EzChatStoreController::class, 'products']);
    Route::get('stock', [EzChatStoreController::class, 'stock']);
    Route::get('orders', [EzChatStoreController::class, 'orders']);
    Route::get('orders/lookup', [EzChatStoreController::class, 'lookup']);
    Route::post('orders', [EzChatStoreController::class, 'createOrder']);
    Route::post('orders/{number}/cancel', [EzChatStoreController::class, 'cancel']);
    Route::post('shipping', [EzChatStoreController::class, 'shipping']);
    Route::post('unlink', [EzChatStoreController::class, 'unlink']);
});

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::get('ezchat-link', [EzChatLinkController::class, 'show']);
    Route::put('ezchat-link', [EzChatLinkController::class, 'update']);
    Route::delete('ezchat-link', [EzChatLinkController::class, 'destroy']);
    Route::post('ezchat-link/grant', [EzChatLinkController::class, 'grant'])->middleware('throttle:20,1,sales.ezchat-link.grant');
    // "Tin nhắn": the EzChat inbox inside this app.
    Route::get('ezchat-inbox', [EzChatInboxController::class, 'show'])->middleware('throttle:120,1,sales.ezchat-inbox');
    Route::post('ezchat-inbox/open', [EzChatInboxController::class, 'open'])->middleware('throttle:30,1,sales.ezchat-inbox.open');
    Route::get('ezchat-inbox/customer', [EzChatInboxController::class, 'customer'])->middleware('throttle:240,1,sales.ezchat-inbox.customer');
    Route::apiResource('sales-channels', SalesChannelController::class)->except('show');

    // Thuê bao, gia hạn.
    Route::get('subscriptions', [SubscriptionController::class, 'index']);
    Route::post('subscriptions/bulk', [SubscriptionController::class, 'bulk'])->middleware('throttle:30,1,sales.subscriptions.bulk');
    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
    Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update']);
    Route::post('subscriptions/{subscription}/renew', [SubscriptionController::class, 'renew'])->middleware('throttle:60,1,sales.subscriptions.renew');
    Route::post('subscriptions/{subscription}/pause', [SubscriptionController::class, 'pause']);
    Route::post('subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume']);
    Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);

    Route::apiResource('customer-groups', CustomerGroupController::class)->except('show');
    Route::post('promotions/preview', [PromotionController::class, 'preview'])->middleware('throttle:240,1,sales.promotions.preview');
    Route::apiResource('promotions', PromotionController::class)->except('show');
    Route::get('loyalty', [LoyaltyController::class, 'show']);
    Route::put('loyalty', [LoyaltyController::class, 'update']);
    Route::get('customers/{customer}/loyalty', [LoyaltyController::class, 'customer']);
    Route::post('customers/{customer}/loyalty/adjust', [LoyaltyController::class, 'adjust'])->middleware('throttle:60,1,sales.customers.loyalty');
    Route::get('customers/tags', [CustomerController::class, 'tags']);
    Route::get('customers/summary', [CustomerController::class, 'overview']);
    Route::get('customers/staff', [CustomerController::class, 'staff']);
    Route::post('customers/bulk', [CustomerController::class, 'bulk']);
    Route::get('customers/export', [CustomerTransferController::class, 'export'])->middleware('throttle:10,1,sales.customers.export');
    Route::get('customer-tasks', [CustomerTaskController::class, 'index']);
    Route::get('customers/duplicates', [CustomerMergeController::class, 'duplicates']);
    Route::post('customers/duplicates/dismiss', [CustomerMergeController::class, 'dismiss']);
    Route::get('customers/import-template', [CustomerTransferController::class, 'template']);
    Route::post('customers/import', [CustomerTransferController::class, 'import'])->middleware('throttle:10,1,sales.customers.import');
    Route::get('customers/imports/latest', [CustomerTransferController::class, 'latestImport']);
    Route::get('customers/imports/{import}', [CustomerTransferController::class, 'showImport']);
    Route::post('customers/imports/{import}/run', [CustomerTransferController::class, 'runImport'])->middleware('throttle:10,1,sales.customers.import-run');
    Route::apiResource('customers', CustomerController::class);
    Route::get('customers/{customer}/ledger', [CustomerController::class, 'ledger']);
    Route::get('customers/{customer}/ledger/export', [CustomerHistoryController::class, 'ledgerExport'])->middleware('throttle:20,1,sales.customers.ledger-export');
    Route::get('customers/{customer}/orders', [CustomerHistoryController::class, 'orders']);
    Route::get('customers/{customer}/payments', [CustomerHistoryController::class, 'payments']);
    Route::get('customers/{customer}/returns', [CustomerHistoryController::class, 'returns']);
    Route::get('customers/{customer}/activity', [CustomerHistoryController::class, 'activity']);
    Route::get('customers/{customer}/tasks', [CustomerTaskController::class, 'forCustomer']);
    Route::post('customers/{customer}/tasks', [CustomerTaskController::class, 'store'])->middleware('throttle:60,1,sales.customers.tasks');
    Route::post('customers/{customer}/tasks/{task}/done', [CustomerTaskController::class, 'complete']);
    Route::delete('customers/{customer}/tasks/{task}', [CustomerTaskController::class, 'destroy']);
    Route::get('customers/{customer}/links', [CustomerMergeController::class, 'links']);
    Route::get('customers/{customer}/merge-preview', [CustomerMergeController::class, 'preview']);
    Route::post('customers/{customer}/merge', [CustomerMergeController::class, 'merge'])->middleware('throttle:30,1,sales.customers.merge');
    Route::post('customers/{customer}/debt-entries', [CustomerDebtController::class, 'store'])->middleware('throttle:60,1,sales.customers.debt-entries');
    Route::post('customers/{customer}/debt-entries/{entry}/void', [CustomerDebtController::class, 'void']);
    Route::post('customers/{customer}/addresses', [CustomerProfileController::class, 'storeAddress']);
    Route::put('customers/{customer}/addresses/{address}', [CustomerProfileController::class, 'updateAddress']);
    Route::delete('customers/{customer}/addresses/{address}', [CustomerProfileController::class, 'destroyAddress']);
    Route::post('customers/{customer}/notes', [CustomerProfileController::class, 'storeNote'])->middleware('throttle:60,1,sales.customers.x.notes');
    Route::delete('customers/{customer}/notes/{note}', [CustomerProfileController::class, 'destroyNote']);

    Route::get('pos/summary', [PosController::class, 'summary']);
    Route::get('pos/catalog', [PosController::class, 'catalog'])->middleware('throttle:30,1,sales.pos.catalog');
    Route::get('pos/shifts/current', [CashShiftController::class, 'current']);
    Route::get('pos/shifts/voucher-types', [CashShiftController::class, 'voucherTypes']);
    Route::get('pos/shifts', [CashShiftController::class, 'index']);
    Route::post('pos/shifts', [CashShiftController::class, 'store'])->middleware('throttle:30,1,sales.pos.shifts');
    Route::get('pos/shifts/{shift}', [CashShiftController::class, 'show']);
    Route::post('pos/shifts/{shift}/movements', [CashShiftController::class, 'move']);
    Route::post('pos/shifts/{shift}/close', [CashShiftController::class, 'close']);
    Route::post('pos/shifts/{shift}/reopen', [CashShiftController::class, 'reopen']);

    Route::apiResource('order-drafts', OrderDraftController::class)->parameters(['order-drafts' => 'draft']);
    Route::post('sales-orders', [SalesOrderController::class, 'store'])->middleware(['throttle:120,1,sales.sales-orders', 'idempotent']);
    Route::put('sales-orders/{order}', [SalesOrderController::class, 'update']);
    Route::post('sales-orders/{order}/void-sale', [SalesOrderController::class, 'voidSale'])->middleware('idempotent');
    Route::post('sales-orders/{order}/actions/{action}', [SalesOrderController::class, 'action'])
        ->whereIn('action', array_keys(ManualOrderService::ACTIONS));
    Route::post('orders/{order}/payments', [SalesOrderController::class, 'pay'])->middleware('idempotent');
    Route::post('payments/{payment}/void', [SalesOrderController::class, 'void']);
});
