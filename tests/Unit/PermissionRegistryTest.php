<?php

use App\Modules\Access\Models\Role;
use App\Modules\Access\Support\PermissionRegistry;
use App\Modules\Billing\Support\PlanCatalog;

it('defines all eight roles from the spec except super admin, which is platform-level', function () {
    expect(array_keys(PermissionRegistry::systemRoles()))->toBe([
        Role::OWNER, Role::ADMIN, Role::MANAGER, Role::WAREHOUSE_STAFF, Role::ORDER_STAFF, Role::ACCOUNTANT, Role::VIEWER,
    ]);
});

it('only references permissions that exist', function () {
    $all = PermissionRegistry::allKeys();

    foreach (PermissionRegistry::systemRoles() as $key => $role) {
        expect(array_diff($role['permissions'], $all))->toBe([], "{$key} references unknown permissions");
    }
});

it('gives the owner everything and keeps billing.manage owner-only', function () {
    $roles = PermissionRegistry::systemRoles();

    expect($roles[Role::OWNER]['permissions'])->toEqualCanonicalizing(PermissionRegistry::allKeys());

    foreach ($roles as $key => $role) {
        if ($key !== Role::OWNER) {
            expect($role['permissions'])->not->toContain('billing.manage');
        }
    }
});

it('keeps viewer read-only', function () {
    foreach (PermissionRegistry::systemRoles()[Role::VIEWER]['permissions'] as $permission) {
        expect($permission)->toEndWith('.view');
    }
});

it('gives every plan a value for every feature', function () {
    foreach (PlanCatalog::PLANS as $key => $plan) {
        expect(array_keys($plan['features']))->toEqualCanonicalizing(array_keys(PlanCatalog::FEATURES), "plan {$key}");
    }
});
