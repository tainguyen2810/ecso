<?php

use App\Modules\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

it('forbids non super admins, including tenant owners', function () {
    [, $owner] = tenantWithOwner();
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    $this->getJson('/api/v1/admin/tenants')->assertForbidden();
});

it('gives super admins platform aggregates and tenant directory without business data', function () {
    tenantWithOwner('A');
    tenantWithOwner('B');
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.tenants_total', 2)
        ->assertJsonPath('data.subscriptions_by_plan.free', 2);

    $row = $this->getJson('/api/v1/admin/tenants')->assertOk()->assertJsonPath('meta.total', 2)->json('data.0');
    expect(array_keys($row))->toEqualCanonicalizing(['id', 'name', 'status', 'owner', 'plan', 'members_count', 'created_at']);
});
