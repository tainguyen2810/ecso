<?php

use App\Modules\Identity\Models\User;

it('passes the environment check on a correct server', function () {
    $this->artisan('ecso:check-env')->assertSuccessful();
});

it('fails the production checks when debug is on', function () {
    config(['app.debug' => true]);

    $this->artisan('ecso:check-env', ['--production' => true])->assertFailed();
});

it('installs and creates exactly one super admin with a strong password', function () {
    $this->artisan('ecso:install', [
        '--skip-env-check' => true, '--admin-name' => 'Root', '--admin-email' => 'root@example.com', '--admin-password' => 'short',
    ])->assertFailed();
    expect(User::query()->where('is_super_admin', true)->count())->toBe(0);

    $this->artisan('ecso:install', [
        '--skip-env-check' => true, '--admin-name' => 'Root', '--admin-email' => 'root@example.com', '--admin-password' => 'a-very-strong-pass-1',
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'root@example.com')->firstOrFail();
    expect($admin->is_super_admin)->toBeTrue()->and($admin->hasVerifiedEmail())->toBeTrue();

    $this->artisan('ecso:install', ['--skip-env-check' => true])->expectsOutputToContain('Đã có super admin')->assertSuccessful();
});

it('exposes a public liveness check and a token-protected readiness check', function () {
    $this->getJson('/health')->assertOk()->assertJson(['status' => 'ok']);

    $this->getJson('/health/ready')->assertNotFound();
    config(['ecso.health_token' => 'tok']);
    $this->getJson('/health/ready', ['X-Health-Token' => 'wrong'])->assertNotFound();
    $this->getJson('/health/ready', ['X-Health-Token' => 'tok'])->assertOk()->assertJsonPath('checks.database', 'ok');
});
