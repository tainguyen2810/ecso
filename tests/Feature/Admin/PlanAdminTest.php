<?php

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Support\TenantContext;
use Laravel\Sanctum\Sanctum;

/** A1: plans are set up by the platform admin after install; the website reads the public ones. */
function planAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();
    Sanctum::actingAs($admin);

    return $admin;
}

it('lets only super admins manage plans', function () {
    [, $owner] = tenantWithOwner();
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/admin/plans')->assertForbidden();
    $this->putJson('/api/v1/admin/plans/pro', ['price_monthly' => 1])->assertForbidden();
});

it('changes prices, limits and display text, and the new limit applies to the businesses on that plan', function () {
    [$tenant] = tenantWithOwner();
    planAdmin();

    $list = $this->getJson('/api/v1/admin/plans')->assertOk();
    expect(collect($list->json('data'))->pluck('key')->all())->toBe(['free', 'starter', 'pro', 'business'])
        ->and(collect($list->json('data'))->firstWhere('key', 'free'))->toMatchArray(['tenants' => 1, 'deletable' => false, 'is_default' => true])
        ->and(collect($list->json('features'))->pluck('key'))->toContain('limit.shops', 'reports.advanced');

    $this->putJson('/api/v1/admin/plans/free', [
        'name' => 'Miễn phí', 'description' => 'Shop mới bắt đầu', 'highlights' => ['Hỗ trợ qua Zalo', ' '],
        'features' => ['limit.shops' => ['enabled' => true, 'limit' => 2], 'limit.users' => ['enabled' => true, 'limit' => null], 'reports.advanced' => ['enabled' => true]],
    ])->assertOk()->assertJsonPath('data.name', 'Miễn phí')->assertJsonPath('data.highlights', ['Hỗ trợ qua Zalo']);
    $features = $this->getJson('/api/v1/admin/plans')->json('data.0.features');
    expect($features['limit.shops']['limit'])->toBe(2)
        ->and($features['limit.users']['limit'])->toBeNull()
        ->and($features['limit.warehouses']['limit'])->toBe(1); // left out: unchanged

    $entitlements = TenantContext::run($tenant, fn () => app(TenantContext::class)->entitlements());
    expect($entitlements->limit('limit.shops'))->toBe(2)
        ->and($entitlements->limit('limit.users'))->toBeNull()
        ->and($entitlements->allows('reports.advanced'))->toBeTrue();

    $this->putJson('/api/v1/admin/plans/pro', ['price_monthly' => -1, 'trial_days' => 999])->assertUnprocessable()->assertJsonValidationErrors(['price_monthly', 'trial_days']);
});

it('adds a hidden plan, reorders, and deletes only plans nobody uses', function () {
    tenantWithOwner();
    planAdmin();

    $this->postJson('/api/v1/admin/plans', ['key' => 'Đối tác', 'name' => 'x', 'price_monthly' => 0, 'price_yearly' => 0, 'trial_days' => 0, 'is_public' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('key');
    $made = $this->postJson('/api/v1/admin/plans', [
        'key' => 'partner-abc', 'name' => 'Đối tác ABC', 'price_monthly' => 0, 'price_yearly' => 0, 'trial_days' => 0, 'is_public' => false,
        'features' => ['limit.users' => ['enabled' => true, 'limit' => 10]],
    ])->assertCreated()->json('data.features');
    expect($made['limit.users']['limit'])->toBe(10)
        ->and($made['limit.shops']['enabled'])->toBeFalse(); // not given: off, fails closed

    $this->postJson('/api/v1/admin/plans/reorder', ['keys' => ['partner-abc', 'free', 'starter', 'pro', 'business']])->assertOk();
    expect(Plan::query()->orderBy('sort')->pluck('key')->first())->toBe('partner-abc');
    $this->postJson('/api/v1/admin/plans/reorder', ['keys' => ['free']])->assertUnprocessable();

    $this->deleteJson('/api/v1/admin/plans/free')->assertUnprocessable();
    $this->deleteJson('/api/v1/admin/plans/business')->assertOk();
    $this->deleteJson('/api/v1/admin/plans/partner-abc')->assertOk();
    expect(Plan::query()->whereIn('key', ['business', 'partner-abc'])->exists())->toBeFalse();
});

it('keeps a plan in use from being deleted', function () {
    [$tenant] = tenantWithOwner();
    planAdmin();
    Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]);

    $this->deleteJson('/api/v1/admin/plans/pro')->assertUnprocessable()->assertJsonValidationErrors('plan');
    $this->putJson('/api/v1/admin/plans/pro', ['is_public' => false])->assertOk()->assertJsonPath('data.is_public', false);
});

it('gives the website the public plans without a login', function () {
    Plan::query()->where('key', 'business')->update(['is_public' => false]);
    Plan::query()->where('key', 'pro')->update(['badge' => 'Phổ biến']);

    $res = $this->getJson('/api/v1/public/plans')->assertOk()->assertJsonPath('currency', 'VND');
    expect(collect($res->json('data'))->pluck('key')->all())->toBe(['free', 'starter', 'pro'])
        ->and($res->json('data.2.badge'))->toBe('Phổ biến')
        ->and($res->json('data.0'))->not->toHaveKey('is_public')
        ->and($res->json('signup_url'))->toEndWith('/register');
});
