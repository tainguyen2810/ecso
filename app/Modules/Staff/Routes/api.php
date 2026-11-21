<?php

use App\Modules\Staff\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified', 'tenant'])->prefix('staff')->group(function () {
    Route::get('attendance', [StaffController::class, 'attendance']);
    Route::get('attendance/me', [StaffController::class, 'me']);
    Route::post('attendance/check', [StaffController::class, 'check'])->middleware('throttle:20,1,staff.check');
    Route::put('attendance/{member}/{day}', [StaffController::class, 'setDay']);
    Route::get('members', [StaffController::class, 'members']);
    Route::put('members/{member}/profile', [StaffController::class, 'saveProfile']);
    Route::get('commissions', [StaffController::class, 'commissions']);
    Route::post('commission-rules', [StaffController::class, 'storeRule']);
    Route::put('commission-rules/{rule}', [StaffController::class, 'updateRule']);
    Route::delete('commission-rules/{rule}', [StaffController::class, 'destroyRule']);
    Route::get('payroll', [StaffController::class, 'payroll']);
    Route::put('payroll/{period}/adjust/{member}', [StaffController::class, 'adjust']);
    Route::post('payroll/{period}/approve', [StaffController::class, 'approve']);
    Route::post('payroll/{period}/reopen', [StaffController::class, 'reopen']);
    Route::post('payroll/{period}/pay', [StaffController::class, 'pay'])->middleware('throttle:10,1,staff.pay');
    Route::post('advances', [StaffController::class, 'advance'])->middleware(['throttle:30,1,staff.advance', 'idempotent']);
});
