<?php

use App\Modules\Cashbook\Http\Controllers\CashAccountController;
use App\Modules\Cashbook\Http\Controllers\CashClosingController;
use App\Modules\Cashbook\Http\Controllers\CashVoucherAttachmentController;
use App\Modules\Cashbook\Http\Controllers\CashVoucherController;
use App\Modules\Cashbook\Http\Controllers\CashVoucherTypeController;
use App\Modules\Cashbook\Http\Controllers\CodReconciliationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->group(function () {
    Route::apiResource('cash-voucher-types', CashVoucherTypeController::class)->only(['index', 'store', 'update']);

    Route::get('cash-accounts', [CashAccountController::class, 'index']);
    Route::post('cash-accounts', [CashAccountController::class, 'store']);
    Route::patch('cash-accounts/{cashAccount}', [CashAccountController::class, 'update']);
    Route::get('cash-accounts/{cashAccount}/counts', [CashClosingController::class, 'counts']);
    Route::post('cash-accounts/{cashAccount}/counts', [CashClosingController::class, 'count'])->middleware('idempotent');
    Route::get('cash-closings', [CashClosingController::class, 'index']);
    Route::post('cash-closings', [CashClosingController::class, 'close']);
    Route::post('cash-closings/{cashClosing}/reopen', [CashClosingController::class, 'reopen']);
    Route::post('cash-transfers', [CashAccountController::class, 'transfer'])->middleware('idempotent');
    Route::post('cash-transfers/{cashTransfer}/void', [CashAccountController::class, 'voidTransfer']);

    Route::get('cod-reconciliations', [CodReconciliationController::class, 'index']);
    Route::post('cod-reconciliations/preview', [CodReconciliationController::class, 'preview']);
    Route::post('cod-reconciliations', [CodReconciliationController::class, 'store'])->middleware('idempotent');
    Route::get('cod-reconciliations/{codReconciliation}', [CodReconciliationController::class, 'show']);
    Route::post('cod-reconciliations/{codReconciliation}/void', [CodReconciliationController::class, 'void']);
    Route::get('cash-vouchers/staff', [CashVoucherController::class, 'staff']);
    Route::get('cash-vouchers', [CashVoucherController::class, 'index']);
    Route::post('cash-vouchers', [CashVoucherController::class, 'store'])->middleware('idempotent');
    Route::get('cash-vouchers/{cashVoucher}/attachments', [CashVoucherAttachmentController::class, 'index']);
    Route::post('cash-vouchers/{cashVoucher}/attachments', [CashVoucherAttachmentController::class, 'store'])->middleware('throttle:30,1,cash.attachments');
    Route::get('cash-voucher-attachments/{attachment}', [CashVoucherAttachmentController::class, 'show']);
    Route::delete('cash-voucher-attachments/{attachment}', [CashVoucherAttachmentController::class, 'destroy']);
    Route::post('cash-vouchers/{cashVoucher}/void', [CashVoucherController::class, 'void']);
    Route::post('cash-vouchers/{cashVoucher}/approve', [CashVoucherController::class, 'approve']);
    Route::post('cash-vouchers/{cashVoucher}/reject', [CashVoucherController::class, 'reject']);
    Route::get('cash-vouchers/{source}/{id}', [CashVoucherController::class, 'show']);

    Route::get('customers/{customer}/debt', [CashVoucherController::class, 'customerDebt']);
    Route::post('customers/{customer}/debt-receipts', [CashVoucherController::class, 'collectCustomerDebt'])->middleware('idempotent');
});
