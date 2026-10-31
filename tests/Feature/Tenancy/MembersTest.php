<?php

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Feature;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Notifications\TenantInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    [$this->tenant, $this->owner] = tenantWithOwner();
    // Lift the free plan user limit for RBAC tests; the limit has its own test below.
    DB::table('tenant_feature_overrides')->insert([
        'tenant_id' => $this->tenant->id,
        'feature_id' => Feature::query()->where('key', 'limit.users')->value('id'),
        'enabled' => true, 'limit_value' => 50,
    ]);
});

function inviteToken(string $email): string
{
    $token = null;
    Notification::assertSentOnDemand(TenantInvitationNotification::class, function ($n, $channels, $notifiable) use ($email, &$token) {
        if ($notifiable->routes['mail'] === $email) {
            $token = $n->token;
        }

        return true;
    });

    return $token;
}

it('lets the owner invite a member who accepts and joins with the invited role', function () {
    actingInTenant($this->owner, $this->tenant)
        ->postJson('/api/v1/members/invitations', ['email' => 'Staff@Example.com', 'role' => Role::WAREHOUSE_STAFF])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.token_hash');

    $token = inviteToken('staff@example.com');
    expect(TenantInvitation::withoutGlobalScopes()->first()->token_hash)->toBe(hash('sha256', $token));

    $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.tenant', $this->tenant->name);

    $staff = User::factory()->create(['email' => 'staff@example.com']);
    Sanctum::actingAs($staff);
    $this->postJson("/api/v1/invitations/{$token}/accept")->assertOk()->assertJsonPath('data.id', $this->tenant->ulid);

    expect(membershipOf($this->tenant, $staff)->role->key)->toBe(Role::WAREHOUSE_STAFF);
    $this->postJson("/api/v1/invitations/{$token}/accept")->assertUnprocessable(); // single use
});

it('refuses invitation acceptance by a different email', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/members/invitations', ['email' => 'a@example.com', 'role' => Role::VIEWER]);
    $token = inviteToken('a@example.com');

    Sanctum::actingAs(User::factory()->create(['email' => 'b@example.com']));
    $this->postJson("/api/v1/invitations/{$token}/accept")->assertForbidden();
});

it('refuses expired and revoked invitations', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->postJson('/api/v1/members/invitations', ['email' => 'a@example.com', 'role' => Role::VIEWER]);
    $ulid = $api->postJson('/api/v1/members/invitations', ['email' => 'b@example.com', 'role' => Role::VIEWER])->json('data.id');
    $expired = inviteToken('a@example.com');
    $revoked = inviteToken('b@example.com');

    TenantInvitation::withoutGlobalScopes()->where('email', 'a@example.com')->update(['expires_at' => now()->subMinute()]);
    $api->deleteJson("/api/v1/members/invitations/{$ulid}")->assertNoContent();

    Sanctum::actingAs(User::factory()->create(['email' => 'a@example.com']));
    $this->postJson("/api/v1/invitations/{$expired}/accept")->assertUnprocessable();
    Sanctum::actingAs(User::factory()->create(['email' => 'b@example.com']));
    $this->postJson("/api/v1/invitations/{$revoked}/accept")->assertUnprocessable();
    $this->getJson("/api/v1/invitations/{$revoked}")->assertNotFound();
});

it('rejects duplicate invitations and inviting existing members', function () {
    $staff = addMember($this->tenant, Role::VIEWER);
    $api = actingInTenant($this->owner, $this->tenant);

    $api->postJson('/api/v1/members/invitations', ['email' => $staff->email, 'role' => Role::VIEWER])->assertUnprocessable();
    $api->postJson('/api/v1/members/invitations', ['email' => 'n@example.com', 'role' => Role::VIEWER])->assertCreated();
    $api->postJson('/api/v1/members/invitations', ['email' => 'n@example.com', 'role' => Role::VIEWER])->assertUnprocessable();
});

it('applies the members permission matrix', function (string $role, int $list, int $invite) {
    $user = addMember($this->tenant, $role);
    $api = actingInTenant($user, $this->tenant);

    $api->getJson('/api/v1/members')->assertStatus($list);
    $api->postJson('/api/v1/members/invitations', ['email' => 'x@example.com', 'role' => Role::VIEWER])->assertStatus($invite);
})->with([
    'admin' => [Role::ADMIN, 200, 201],
    'manager' => [Role::MANAGER, 200, 403],
    'warehouse' => [Role::WAREHOUSE_STAFF, 403, 403],
    'order staff' => [Role::ORDER_STAFF, 403, 403],
    'accountant' => [Role::ACCOUNTANT, 403, 403],
    'viewer' => [Role::VIEWER, 403, 403],
]);

it('never lets anyone assign the owner role', function () {
    $admin = addMember($this->tenant, Role::ADMIN);
    $viewer = membershipOf($this->tenant, addMember($this->tenant, Role::VIEWER));

    actingInTenant($admin, $this->tenant)->postJson('/api/v1/members/invitations', ['email' => 'x@example.com', 'role' => Role::OWNER])->assertForbidden();
    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/members/{$viewer->ulid}", ['role' => Role::OWNER])->assertForbidden();
});

it('stops admins from modifying the owner or themselves', function () {
    $admin = addMember($this->tenant, Role::ADMIN);
    $owner = membershipOf($this->tenant, $this->owner);
    $self = membershipOf($this->tenant, $admin);
    $api = actingInTenant($admin, $this->tenant);

    $api->patchJson("/api/v1/members/{$owner->ulid}", ['role' => Role::VIEWER])->assertForbidden();
    $api->deleteJson("/api/v1/members/{$owner->ulid}")->assertForbidden();
    $api->patchJson("/api/v1/members/{$self->ulid}", ['role' => Role::VIEWER])->assertForbidden();
    $api->patchJson("/api/v1/members/{$self->ulid}", ['status' => 'disabled'])->assertForbidden();

    expect($owner->fresh()->role->key)->toBe(Role::OWNER);
});

it('stops escalation to a role with permissions the actor lacks', function () {
    $admin = addMember($this->tenant, Role::ADMIN);
    $viewer = membershipOf($this->tenant, addMember($this->tenant, Role::VIEWER));

    $billing = new Role(['key' => 'BILLING_CLERK', 'name' => 'Billing']);
    $billing->tenant_id = $this->tenant->id;
    $billing->save();
    $billing->permissions()->attach(Permission::query()->where('key', 'billing.manage')->value('id'));

    actingInTenant($admin, $this->tenant)->patchJson("/api/v1/members/{$viewer->ulid}", ['role' => 'BILLING_CLERK'])->assertForbidden();
    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/members/{$viewer->ulid}", ['role' => 'BILLING_CLERK'])->assertOk();
});

it('lets admins change roles and disable or remove members, with audit entries', function () {
    $admin = addMember($this->tenant, Role::ADMIN);
    $staff = membershipOf($this->tenant, addMember($this->tenant, Role::VIEWER));
    $api = actingInTenant($admin, $this->tenant);

    $api->patchJson("/api/v1/members/{$staff->ulid}", ['role' => Role::ORDER_STAFF])->assertOk()->assertJsonPath('data.role.key', Role::ORDER_STAFF);
    $api->patchJson("/api/v1/members/{$staff->ulid}", ['status' => 'disabled'])->assertOk()->assertJsonPath('data.status', 'disabled');
    $api->deleteJson("/api/v1/members/{$staff->ulid}")->assertNoContent();

    foreach (['member.role_changed', 'member.status_changed', 'member.removed'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => $action, 'actor_user_id' => $admin->id]);
    }
});

it('marks which roles the current user can assign', function () {
    $admin = addMember($this->tenant, Role::ADMIN);

    $assignable = actingInTenant($admin, $this->tenant)->getJson('/api/v1/roles')->assertOk()->json('assignable');

    expect($assignable)->not->toContain(Role::OWNER)->toContain(Role::ADMIN, Role::VIEWER);
});

it('enforces the plan user limit including pending invitations', function () {
    DB::table('tenant_feature_overrides')->where('tenant_id', $this->tenant->id)->delete(); // back to free: 2 users
    $api = actingInTenant($this->owner, $this->tenant);

    $api->postJson('/api/v1/members/invitations', ['email' => 'one@example.com', 'role' => Role::VIEWER])->assertCreated();
    $api->postJson('/api/v1/members/invitations', ['email' => 'two@example.com', 'role' => Role::VIEWER])
        ->assertForbidden()->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED')->assertJsonPath('limit', 2);
});
