<?php

use App\Modules\Access\Models\Role;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Support\TenantContext;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('gives a new small shop the basic menu and the full menu from a second warehouse', function () {
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.tenant.advanced_menu', false);

    TenantContext::run($this->tenant, fn () => Warehouse::create(['code' => 'K2', 'name' => 'Kho 2']));
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/tenant')->assertJsonPath('data.tenant.advanced_menu', true);
});

it('turns on the full menu from 6 members or a large team answer', function () {
    foreach (range(1, 5) as $_) {
        addMember($this->tenant, Role::ORDER_STAFF);
    }
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/tenant')->assertJsonPath('data.tenant.advanced_menu', true);

    [$other, $owner] = tenantWithOwner('Shop B');
    $other->settings = ['setup' => ['team_size' => '6-20']];
    $other->save();
    actingInTenant($owner, $other)->getJson('/api/v1/tenant')->assertJsonPath('data.tenant.advanced_menu', true);
});

it('lets the owner choose either way and keeps the choice over the automatic rule', function () {
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/interface', ['advanced_menu' => true])
        ->assertOk()->assertJsonPath('data.interface.advanced_menu', true);
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/tenant')->assertJsonPath('data.tenant.advanced_menu', true);

    TenantContext::run($this->tenant, fn () => Warehouse::create(['code' => 'K2', 'name' => 'Kho 2']));
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/interface', ['advanced_menu' => false])->assertOk();
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/tenant')->assertJsonPath('data.tenant.advanced_menu', false);

    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    actingInTenant($staff, $this->tenant)->putJson('/api/v1/tenant/settings/interface', ['advanced_menu' => true])->assertForbidden();
});
