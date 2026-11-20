<?php

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Support\TenantContext;

/** Đợt 17 "Đăng nhập bằng EzChat" and "Gói tặng kèm". */
beforeEach(function () {
    config(['marketplaces.ezchat.url' => 'https://app.ezchat.test', 'marketplaces.ezchat.secret' => 'sso-secret', 'marketplaces.ezchat.sso' => 'on']);
});

function ssoTicket(array $over = []): string
{
    $data = array_replace_recursive([
        'v' => 1, 'ts' => time(), 'nonce' => bin2hex(random_bytes(8)),
        'user' => ['id' => 'usr_1', 'email' => 'chu.shop@example.test', 'name' => 'Chủ Shop', 'emailVerified' => true],
        'workspace' => ['id' => 'ten_1', 'name' => 'Shop Hoa Mai'],
        'role' => 'OWNER',
        'plan' => ['slug' => 'pro', 'name' => 'Pro', 'expiresAt' => now()->addDays(40)->toIso8601String(), 'active' => true],
    ], $over);
    $body = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $body.'.'.hash_hmac('sha256', 'sso.'.$body, 'sso-secret');
}

function ssoTenantSub(Tenant $tenant): Subscription
{
    return TenantContext::run($tenant, fn () => Subscription::query()->with('plan')->sole());
}

it('opens the store from EzChat: makes the user, the business and the gift plan, once per ticket', function () {
    $this->get('/sso/ezchat/start?redirect=/orders')->assertRedirect('https://app.ezchat.test/banhang/open?return=%2Forders');

    $ticket = ssoTicket();
    $res = $this->get('/sso/ezchat?return=/orders&ticket='.urlencode($ticket));
    $tenant = Tenant::query()->where('ezchat_workspace_id', 'ten_1')->sole();
    $res->assertRedirect("/orders?tenant={$tenant->ulid}&welcome=ezchat");
    $this->assertAuthenticated();

    $user = User::query()->where('ezchat_user_id', 'usr_1')->sole();
    expect($tenant->name)->toBe('Shop Hoa Mai')->and($tenant->owner_user_id)->toBe($user->id)->and($user->email_verified_at)->not->toBeNull();
    $sub = ssoTenantSub($tenant);
    expect($sub->plan->key)->toBe('pro')->and($sub->gift_source)->toBe('ezchat')->and($sub->gift_label)->toBe('EzChat Pro')
        ->and($sub->current_period_end->isFuture())->toBeTrue();

    // The same ticket cannot be used twice.
    auth()->guard('web')->logout();
    $this->get('/sso/ezchat?ticket='.urlencode($ticket))->assertRedirectContains('/login?sso_error=');
    $this->assertGuest();
    // A forged one neither.
    $this->get('/sso/ezchat?ticket='.urlencode(substr($ticket, 0, -2).'00'))->assertRedirectContains('sso_error=');
});

it('lets members join with a role from EzChat, after the owner opened the store', function () {
    $agent = ['user' => ['id' => 'usr_2', 'email' => 'nv@example.test', 'name' => 'Nhân viên'], 'role' => 'AGENT'];
    $this->get('/sso/ezchat?ticket='.urlencode(ssoTicket($agent)))->assertRedirectContains('sso_error=');
    expect(Tenant::query()->where('ezchat_workspace_id', 'ten_1')->exists())->toBeFalse();

    $this->get('/sso/ezchat?ticket='.urlencode(ssoTicket()))->assertRedirectContains('tenant=');
    auth()->guard('web')->logout();
    $this->get('/sso/ezchat?ticket='.urlencode(ssoTicket($agent)))->assertRedirectContains('tenant=');
    $tenant = Tenant::query()->where('ezchat_workspace_id', 'ten_1')->sole();
    $member = TenantContext::run($tenant, fn () => TenantUser::query()->with('role')->whereHas('user', fn ($q) => $q->where('email', 'nv@example.test'))->sole());
    expect($member->role->key)->toBe(Role::ORDER_STAFF);
});

it('keeps a plan the store paid for, follows the EzChat plan and ends the gift with it', function () {
    $this->get('/sso/ezchat?ticket='.urlencode(ssoTicket(['plan' => ['slug' => 'basic', 'name' => 'Basic']])));
    $tenant = Tenant::query()->where('ezchat_workspace_id', 'ten_1')->sole();
    expect(ssoTenantSub($tenant)->plan->key)->toBe('starter');

    // Upgraded on EzChat: the gift follows at the next login.
    $this->get('/sso/ezchat?ticket='.urlencode(ssoTicket(['plan' => ['slug' => 'business', 'name' => 'Business']])));
    expect(ssoTenantSub($tenant)->plan->key)->toBe('business');

    // The EzChat plan ran out: the gift ends at once.
    $this->get('/sso/ezchat?ticket='.urlencode(ssoTicket(['plan' => ['slug' => 'business', 'expiresAt' => now()->subDay()->toIso8601String()]])));
    $sub = ssoTenantSub($tenant);
    expect($sub->plan->key)->toBe(Plan::FREE)->and($sub->gift_source)->toBeNull();

    // A plan the store pays for itself is never replaced by a gift.
    TenantContext::run($tenant, fn () => Subscription::query()->sole()->forceFill(['plan_id' => Plan::query()->where('key', 'pro')->value('id'), 'current_period_end' => now()->addMonths(6)])->save());
    $this->get('/sso/ezchat?ticket='.urlencode(ssoTicket(['plan' => ['slug' => 'basic']])));
    expect(ssoTenantSub($tenant)->plan->key)->toBe('pro')->and(ssoTenantSub($tenant)->gift_source)->toBeNull();

    // Billing sweep: a gift past its end goes to Free without an invoice.
    TenantContext::run($tenant, fn () => Subscription::query()->sole()->forceFill(['gift_source' => 'ezchat', 'current_period_end' => now()->subHour()])->save());
    $this->artisan('ecso:billing:sweep')->assertSuccessful();
    expect(ssoTenantSub($tenant)->plan->key)->toBe(Plan::FREE)
        ->and(TenantContext::run($tenant, fn () => Invoice::query()->count()))->toBe(0);
});

it('is off when the server says so, and the login page shows the button only when on', function () {
    $this->withoutVite()->get('/login')->assertSee('name="ezchat-sso"', false);
    config(['marketplaces.ezchat.sso' => 'off']);
    $this->get('/sso/ezchat/start')->assertNotFound();
    $this->get('/login')->assertDontSee('name="ezchat-sso"', false);
});
