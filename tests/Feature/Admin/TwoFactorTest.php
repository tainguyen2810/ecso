<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Totp;

beforeEach(function () {
    config(['ecso.admin_two_factor' => true]);
    $this->admin = User::factory()->create(['email' => 'root@ecso.test', 'password' => 'password1']);
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

/** Logs in through the real SPA endpoint so the session (and its 2FA flag) is used like in the browser. */
function spaLogin(string $email): void
{
    test()->withHeader('Referer', 'http://localhost')->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password1'])->assertOk();
}

it('matches the RFC 6238 test vectors', function () {
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
    expect(Totp::code($secret, 59))->toBe('287082')
        ->and(Totp::code($secret, 1111111109))->toBe('081804')
        ->and(Totp::verify($secret, '287 082', 59))->toBe(1)
        ->and(Totp::verify($secret, '000000', 59))->toBeNull();
});

it('blocks the admin console until a super admin sets up and confirms 2FA', function () {
    spaLogin('root@ecso.test');
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'TWO_FACTOR_SETUP_REQUIRED');

    $setup = $this->postJson('/api/v1/admin/2fa/setup')->assertOk()->json('data');
    expect($setup['uri'])->toStartWith('otpauth://totp/')
        ->and($this->admin->fresh()->getRawOriginal('two_factor_secret'))->not->toContain($setup['secret']); // stored encrypted

    $this->postJson('/api/v1/admin/2fa/confirm', ['code' => '000000'])->assertStatus(422);
    $codes = $this->postJson('/api/v1/admin/2fa/confirm', ['code' => Totp::code($setup['secret'], time())])->assertOk()->json('data.recovery_codes');
    expect($codes)->toHaveCount(8);

    $this->getJson('/api/v1/admin/dashboard')->assertOk();
    $this->getJson('/api/v1/admin/2fa')->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_left', 8);
});

it('asks for a code on every new session, refuses replayed codes, and accepts each recovery code once', function () {
    $secret = Totp::generateSecret();
    $this->admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => [hash('sha256', 'AAAAA-BBBBB')]])->save();

    spaLogin('root@ecso.test');
    $this->getJson('/api/v1/admin/tenants')->assertForbidden()->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
    $this->postJson('/api/v1/admin/2fa/setup')->assertStatus(422); // cannot silently replace an enabled secret

    $code = Totp::code($secret, time());
    $this->postJson('/api/v1/admin/2fa/verify', ['code' => $code])->assertOk();
    $this->getJson('/api/v1/admin/tenants')->assertOk();

    // New session: the same code cannot be replayed.
    $this->postJson('/api/v1/auth/logout')->assertSuccessful();
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    spaLogin('root@ecso.test');
    $this->getJson('/api/v1/admin/tenants')->assertForbidden();
    $this->postJson('/api/v1/admin/2fa/verify', ['code' => $code])->assertStatus(422);

    $this->postJson('/api/v1/admin/2fa/verify', ['recovery_code' => 'aaaaa-bbbbb'])->assertOk();
    $this->getJson('/api/v1/admin/tenants')->assertOk();
    expect($this->admin->fresh()->two_factor_recovery_codes)->toBe([]);
});

it('keeps 2FA endpoints for super admins only', function () {
    [, $owner] = tenantWithOwner();
    $owner->forceFill(['password' => 'password1'])->save();

    spaLogin($owner->email);
    $this->postJson('/api/v1/admin/2fa/setup')->assertForbidden();
});
