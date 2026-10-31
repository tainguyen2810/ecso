<?php

use App\Modules\Tenancy\Http\Controllers\InvitationController;
use App\Modules\Tenancy\Http\Controllers\MemberController;
use App\Modules\Tenancy\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1');

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantController::class, 'store'])->middleware('throttle:10,1');
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');

    Route::middleware('tenant')->group(function () {
        Route::get('tenant', [TenantController::class, 'current']);
        Route::patch('tenant', [TenantController::class, 'update']);

        Route::get('roles', [MemberController::class, 'roles']);
        Route::get('members', [MemberController::class, 'index']);
        Route::get('members/invitations', [InvitationController::class, 'index']);
        Route::post('members/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('members/invitations/{invitation}', [InvitationController::class, 'destroy']);
        Route::patch('members/{member}', [MemberController::class, 'update']);
        Route::delete('members/{member}', [MemberController::class, 'destroy']);
    });
});
