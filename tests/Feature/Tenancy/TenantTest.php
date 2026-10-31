<?php

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Laravel\Sanctum\Sanctum;

it('creates a tenant with the creator as owner on the free plan', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/tenants', ['name' => 'Cửa hàng Hoa'])->assertCreated();

    $tenant = Tenant::query()->where('ulid', $response->json('data.id'))->firstOrFail();
    expect($tenant->owner_user_id)->toBe($user->id)
        ->and($tenant->slug)->toBe('cua-hang-hoa')
        ->and(membershipOf($tenant, $user)->role->key)->toBe(Role::OWNER)
        ->and(Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()->plan->key)->toBe('free');
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'tenant.created']);
});

it('lists only tenants the user belongs to, with the role in each', function () {
    [$a, $owner] = tenantWithOwner('A');
    [$b] = tenantWithOwner('B');
    addMember($b, Role::VIEWER, $owner);
    tenantWithOwner('C');

    Sanctum::actingAs($owner);
    $data = $this->getJson('/api/v1/tenants')->assertOk()->json('data');

    expect(collect($data)->pluck('role', 'name')->all())->toBe(['A' => Role::OWNER, 'B' => Role::VIEWER]);
});

it('returns the current tenant context with permissions and entitlements', function () {
    [$tenant, $owner] = tenantWithOwner();
    $viewer = addMember($tenant, Role::VIEWER);

    $response = actingInTenant($viewer, $tenant)->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.tenant.id', $tenant->ulid)
        ->assertJsonPath('data.role.key', Role::VIEWER)
        ->assertJsonMissingPath('data.tenant.owner_user_id');

    expect($response->json('data.entitlements')['limit.users'])->toBe(['enabled' => true, 'limit' => 2])
        ->and($response->json('data.permissions'))->toContain('orders.view')->not->toContain('members.manage');
});

it('switches between tenants by header and applies the role of each tenant', function () {
    [$a, $user] = tenantWithOwner('A');
    [$b] = tenantWithOwner('B');
    addMember($b, Role::VIEWER, $user);

    actingInTenant($user, $a)->getJson('/api/v1/tenant')->assertJsonPath('data.role.key', Role::OWNER);
    actingInTenant($user, $b)->getJson('/api/v1/tenant')->assertJsonPath('data.role.key', Role::VIEWER);
    actingInTenant($user, $b)->patchJson('/api/v1/tenant', ['name' => 'Hack'])->assertForbidden();
});

it('updates settings only with settings.manage and ignores protected fields', function () {
    [$tenant, $owner] = tenantWithOwner();
    $other = User::factory()->create();
    $viewer = addMember($tenant, Role::VIEWER);
    $admin = addMember($tenant, Role::ADMIN);

    actingInTenant($viewer, $tenant)->patchJson('/api/v1/tenant', ['name' => 'X'])->assertForbidden();

    actingInTenant($admin, $tenant)->patchJson('/api/v1/tenant', [
        'name' => 'Tên mới', 'timezone' => 'Asia/Bangkok', 'owner_user_id' => $other->id, 'status' => 'suspended', 'id' => 999,
    ])->assertOk()->assertJsonPath('data.name', 'Tên mới');

    $tenant->refresh();
    expect($tenant->owner_user_id)->toBe($owner->id)->and($tenant->status)->toBe('active')->and($tenant->timezone)->toBe('Asia/Bangkok');
});
