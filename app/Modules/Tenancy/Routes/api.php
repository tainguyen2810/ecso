<?php

use App\Modules\Tenancy\Http\Controllers\DemoStoreController;
use App\Modules\Tenancy\Http\Controllers\InvitationController;
use App\Modules\Tenancy\Http\Controllers\MemberController;
use App\Modules\Tenancy\Http\Controllers\SettingsController;
use App\Modules\Tenancy\Http\Controllers\SetupController;
use App\Modules\Tenancy\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1,tenancy.invitations.x');

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantController::class, 'store'])->middleware('throttle:10,1,tenancy.tenants');
    Route::post('demo-store', [DemoStoreController::class, 'store'])->middleware('throttle:5,1,tenancy.demo-store');
    Route::delete('demo-store/{tenant}', [DemoStoreController::class, 'destroy']);
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1,tenancy.invitations.x.accept');

    Route::middleware('tenant')->group(function () {
        Route::get('tenant', [TenantController::class, 'current']);
        Route::patch('tenant', [TenantController::class, 'update']);
        Route::put('tenant/setup', [SetupController::class, 'update']);
        Route::get('tenant/settings', [SettingsController::class, 'show']);
        Route::put('tenant/settings/{section}', [SettingsController::class, 'update'])->whereIn('section', ['store', 'tax', 'pricing', 'payments', 'sales', 'reasons', 'print']);
        Route::post('tenant/logo', [SettingsController::class, 'uploadLogo'])->middleware('throttle:10,1,tenancy.logo');
        Route::delete('tenant/logo', [SettingsController::class, 'deleteLogo']);

        Route::get('roles', [MemberController::class, 'roles']);
        Route::get('members', [MemberController::class, 'index']);
        Route::get('members/invitations', [InvitationController::class, 'index']);
        Route::post('members/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1,tenancy.members.invitations');
        Route::delete('members/invitations/{invitation}', [InvitationController::class, 'destroy']);
        Route::patch('members/{member}', [MemberController::class, 'update']);
        Route::delete('members/{member}', [MemberController::class, 'destroy']);
    });
});
