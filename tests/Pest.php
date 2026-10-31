<?php

use App\Modules\Access\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Arch');

/**
 * Creates a tenant through the real service (owner membership, free subscription, audit).
 *
 * @return array{0: Tenant, 1: User}
 */
function tenantWithOwner(string $name = 'Shop A'): array
{
    $owner = User::factory()->create();
    $tenant = app(TenantService::class)->create($owner, ['name' => $name]);

    return [$tenant, $owner];
}

function addMember(Tenant $tenant, string $roleKey, ?User $user = null, string $status = TenantUser::STATUS_ACTIVE): User
{
    $user ??= User::factory()->create();

    TenantContext::run($tenant, fn () => TenantUser::create([
        'user_id' => $user->id,
        'role_id' => Role::system($roleKey)->id,
        'status' => $status,
        'joined_at' => now(),
    ]));

    return $user;
}

function membershipOf(Tenant $tenant, User $user): TenantUser
{
    return TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
}

function actingInTenant(User $user, Tenant $tenant): TestCase
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant', $tenant->ulid);
}
