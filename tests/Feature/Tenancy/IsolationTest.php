<?php

use App\Modules\Access\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Exceptions\NoTenantContext;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    [$this->a, $this->ownerA] = tenantWithOwner('A');
    [$this->b, $this->ownerB] = tenantWithOwner('B');
    $this->memberB = addMember($this->b, Role::VIEWER);
});

$tenantEndpoints = [
    ['GET', '/api/v1/tenant'],
    ['GET', '/api/v1/members'],
    ['GET', '/api/v1/roles'],
    ['GET', '/api/v1/members/invitations'],
    ['GET', '/api/v1/subscription'],
    ['GET', '/api/v1/audit-logs'],
    ['PATCH', '/api/v1/tenant'],
];

it('rejects requests without a tenant header', function (string $method, string $uri) {
    Sanctum::actingAs($this->ownerA);
    $this->json($method, $uri)->assertNotFound()->assertJsonPath('code', 'TENANT_NOT_FOUND');
})->with($tenantEndpoints);

it('returns 404 when selecting a tenant the user does not belong to', function (string $method, string $uri) {
    actingInTenant($this->ownerA, $this->b)->json($method, $uri)->assertNotFound()->assertJsonPath('code', 'TENANT_NOT_FOUND');
})->with($tenantEndpoints);

it('returns the same 404 for an unknown tenant id', function () {
    Sanctum::actingAs($this->ownerA);
    $this->withHeader('X-Tenant', '01JXXXXXXXXXXXXXXXXXXXXXXX')->getJson('/api/v1/tenant')->assertNotFound();
});

it('cannot reach another tenant member by id (IDOR) even with its own valid tenant header', function () {
    $foreign = membershipOf($this->b, $this->memberB);

    actingInTenant($this->ownerA, $this->a)->patchJson("/api/v1/members/{$foreign->ulid}", ['role' => Role::ADMIN])->assertNotFound();
    actingInTenant($this->ownerA, $this->a)->deleteJson("/api/v1/members/{$foreign->ulid}")->assertNotFound();

    expect($foreign->fresh()->role->key)->toBe(Role::VIEWER);
});

it('cannot revoke another tenant invitation by id', function () {
    $invitation = TenantContext::run($this->b, fn () => TenantInvitation::create([
        'email' => 'x@example.com',
        'role_id' => Role::system(Role::VIEWER)->id,
        'token_hash' => TenantInvitation::hashToken('t'),
        'expires_at' => now()->addDay(),
    ]));

    actingInTenant($this->ownerA, $this->a)->deleteJson("/api/v1/members/invitations/{$invitation->ulid}")->assertNotFound();
    expect(TenantInvitation::withoutGlobalScopes()->find($invitation->id)->revoked_at)->toBeNull();
});

it('lists only members of the selected tenant', function () {
    $emails = collect(actingInTenant($this->ownerA, $this->a)->getJson('/api/v1/members')->assertOk()->json('data'))->pluck('email');

    expect($emails->all())->toBe([$this->ownerA->email]);
});

it('denies access through a disabled membership', function () {
    $user = addMember($this->a, Role::ADMIN, status: TenantUser::STATUS_DISABLED);

    actingInTenant($user, $this->a)->getJson('/api/v1/tenant')->assertNotFound();
});

it('blocks suspended tenants', function () {
    $this->a->forceFill(['status' => 'suspended'])->save();

    actingInTenant($this->ownerA, $this->a)->getJson('/api/v1/tenant')->assertForbidden()->assertJsonPath('code', 'TENANT_SUSPENDED');
});

it('gives super admins no implicit access to tenant data', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    actingInTenant($admin, $this->a)->getJson('/api/v1/members')->assertNotFound();
});

it('fails closed when tenant-scoped models are queried without a context', function () {
    TenantUser::query()->count();
})->throws(NoTenantContext::class);

it('overwrites any client supplied tenant_id with the context tenant', function () {
    $member = TenantContext::run($this->a, fn () => TenantUser::create([
        'tenant_id' => $this->b->id,
        'user_id' => User::factory()->create()->id,
        'role_id' => Role::system(Role::VIEWER)->id,
    ]));

    expect($member->tenant_id)->toBe($this->a->id);
});
