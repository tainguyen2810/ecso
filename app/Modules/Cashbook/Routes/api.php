<?php

use App\Modules\Cashbook\Http\Controllers\CashVoucherController;
use App\Modules\Cashbook\Http\Controllers\CashVoucherTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::apiResource('cash-voucher-types', CashVoucherTypeController::class)->only(['index', 'store', 'update']);

    Route::get('cash-vouchers/staff', [CashVoucherController::class, 'staff']);
    Route::get('cash-vouchers', [CashVoucherController::class, 'index']);
    Route::post('cash-vouchers', [CashVoucherController::class, 'store'])->middleware('idempotent');
    Route::post('cash-vouchers/{cashVoucher}/void', [CashVoucherController::class, 'void']);
    Route::get('cash-vouchers/{source}/{id}', [CashVoucherController::class, 'show']);

    Route::get('customers/{customer}/debt', [CashVoucherController::class, 'customerDebt']);
    Route::post('customers/{customer}/debt-receipts', [CashVoucherController::class, 'collectCustomerDebt'])->middleware('idempotent');
});
