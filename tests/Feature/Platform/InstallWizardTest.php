<?php

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Installer\Installer;
use Dotenv\Dotenv;

/** Simulates a fresh upload: no APP_KEY, no lock file, `.env` would be written to a temp folder. */
function freshUpload(): string
{
    config(['app.key' => '']);
    $envDir = sys_get_temp_dir().'/ecso-env-'.bin2hex(random_bytes(4));
    mkdir($envDir, 0o700, true);
    app()->useEnvironmentPath($envDir);

    return $envDir;
}

function installToken(): string
{
    return trim((string) file_get_contents(config('ecso.installer.token_path')));
}

function testDatabaseInput(): array
{
    $c = config('database.connections.mysql');

    return ['host' => $c['host'], 'port' => (int) $c['port'], 'database' => $c['database'], 'username' => $c['username'], 'password' => (string) $c['password']];
}

function wizardPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'database' => testDatabaseInput(),
        'redis' => ['enabled' => false],
        'app' => ['name' => 'Shop Việt', 'url' => 'https://ban.example.vn'],
        'bank' => ['name' => 'Vietcombank', 'account_number' => '0123456789', 'account_name' => 'CONG TY ABC', 'branch' => ''],
        'admin' => ['name' => 'Chủ hệ thống', 'email' => 'Root@Example.vn', 'password' => 'Mat-khau "dai" $123', 'password_confirmation' => 'Mat-khau "dai" $123'],
    ], $overrides);
}

function passRequirements(): void
{
    test()->partialMock(Installer::class, fn ($mock) => $mock->shouldReceive('requirements')->andReturn(['ok' => true, 'items' => []]));
}

it('keeps the wizard closed on an installed app', function () {
    $this->getJson('/install/api/status')->assertOk()->assertJsonPath('data.installed', true);
    $this->postJson('/install/api/requirements', [], ['X-Install-Token' => 'x'])->assertNotFound();
    $this->postJson('/install/api/run', wizardPayload(), ['X-Install-Token' => 'x'])->assertNotFound();
    expect(file_exists(config('ecso.installer.token_path')))->toBeFalse();
});

it('sends every page of a fresh upload to the wizard', function () {
    freshUpload();

    $this->get('/')->assertRedirect('/install');
    $this->get('/admin')->assertRedirect('/install');
    $this->getJson('/api/v1/auth/me')->assertStatus(503)->assertJsonPath('code', 'NOT_INSTALLED');
    $this->getJson('/health')->assertStatus(503);
});

it('requires the server-side token and never reveals it', function () {
    freshUpload();

    $status = $this->getJson('/install/api/status')->assertOk()->assertJsonPath('data.installed', false);
    $token = installToken();
    expect($token)->toHaveLength(32)
        ->and($status->getContent())->not->toContain($token)
        ->and($status->json('data.token_file'))->toEndWith('install-token.txt');

    // The token is stable across visits so the user can copy it at their own pace.
    $this->getJson('/install/api/status');
    expect(installToken())->toBe($token);

    $this->postJson('/install/api/requirements')->assertForbidden();
    $this->postJson('/install/api/requirements', [], ['X-Install-Token' => str_repeat('0', 32)])->assertForbidden();
    $this->postJson('/install/api/database', ['database' => testDatabaseInput()], ['X-Install-Token' => 'nope'])->assertForbidden();

    $this->postJson('/install/api/requirements', [], ['X-Install-Token' => $token])
        ->assertOk()
        ->assertJsonStructure(['data' => ['ok', 'items' => [['key', 'label', 'ok', 'required', 'detail']], 'base_path']]);
});

it('tests database and redis connections', function () {
    freshUpload();
    $this->getJson('/install/api/status');
    $headers = ['X-Install-Token' => installToken()];

    $this->postJson('/install/api/database', ['database' => [...testDatabaseInput(), 'password' => 'wrong-password']], $headers)
        ->assertOk()->assertJsonPath('data.ok', false);

    $this->postJson('/install/api/database', ['database' => testDatabaseInput()], $headers)
        ->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.has_tables', true);

    // An existing super admin is detected too, but the probe uses its own connection and cannot see this
    // test's uncommitted transaction; the install refusal test below covers that path.

    $this->postJson('/install/api/redis', ['redis' => ['host' => config('database.redis.default.host'), 'port' => (int) config('database.redis.default.port')]], $headers)
        ->assertOk()->assertJsonPath('data.ok', true);
    $this->postJson('/install/api/redis', ['redis' => ['host' => '127.0.0.1', 'port' => 1]], $headers)
        ->assertOk()->assertJsonPath('data.ok', false);
})->skip(fn () => ! extension_loaded('redis'), 'ext-redis missing');

it('installs, writes a production .env and locks itself', function () {
    $envDir = freshUpload();
    $this->getJson('/install/api/status');
    $headers = ['X-Install-Token' => installToken()];
    passRequirements();

    $this->postJson('/install/api/run', wizardPayload(['admin' => ['password' => 'short', 'password_confirmation' => 'short']]), $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('admin.password');

    $this->postJson('/install/api/run', wizardPayload(), $headers)
        ->assertOk()
        ->assertJsonPath('data.queue', 'database')
        ->assertJsonPath('data.admin_email', 'root@example.vn');

    $admin = User::query()->where('email', 'root@example.vn')->firstOrFail();
    expect($admin->is_super_admin)->toBeTrue()
        ->and(password_verify('Mat-khau "dai" $123', $admin->password))->toBeTrue();

    $envFile = "{$envDir}/.env";
    expect(fileperms($envFile) & 0o777)->toBe(0o600);
    $env = Dotenv::parse((string) file_get_contents($envFile));
    expect($env['APP_KEY'])->toStartWith('base64:')
        ->and($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['APP_NAME'])->toBe('Shop Việt')
        ->and($env['APP_URL'])->toBe('https://ban.example.vn')
        ->and($env['DB_DATABASE'])->toBe(testDatabaseInput()['database'])
        ->and($env['DB_PASSWORD'])->toBe(testDatabaseInput()['password'])
        ->and($env['QUEUE_CONNECTION'])->toBe('database')
        ->and($env['CACHE_STORE'])->toBe('database')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($env['SESSION_ENCRYPT'])->toBe('true')
        ->and($env['FAKE_MARKETPLACE_ENABLED'])->toBe('false')
        ->and($env['ECSO_ADMIN_2FA'])->toBe('true')
        ->and($env['BILLING_BANK_ACCOUNT_NAME'])->toBe('CONG TY ABC')
        ->and($env['SANCTUM_STATEFUL_DOMAINS'])->toBe('ban.example.vn')
        ->and(strlen($env['HEALTH_TOKEN']))->toBe(40);

    // Locked: the token is gone and the wizard no longer exists, even though APP_KEY is still empty in this process.
    expect(file_exists(config('ecso.installer.lock_path')))->toBeTrue()
        ->and(file_exists(config('ecso.installer.token_path')))->toBeFalse();
    $this->getJson('/install/api/status')->assertJsonPath('data.installed', true);
    $this->postJson('/install/api/run', wizardPayload(), $headers)->assertNotFound();
});

it('refuses to install over a database that already has an admin', function () {
    freshUpload();
    $this->getJson('/install/api/status');
    passRequirements();
    User::factory()->create(['is_super_admin' => true]);

    $this->postJson('/install/api/run', wizardPayload(), ['X-Install-Token' => installToken()])
        ->assertUnprocessable()->assertJsonPath('code', 'INSTALL_FAILED');
    expect(file_exists(config('ecso.installer.lock_path')))->toBeFalse();
});

it('round-trips awkward values through the .env format', function () {
    $installer = app(Installer::class);
    $values = ['A' => 'plain', 'B' => 'has space', 'C' => 'q"uote', 'D' => 'dollar$HOME', 'E' => 'back\\slash', 'F' => '#hash', 'G' => ''];
    $parsed = Dotenv::parse($installer->renderEnv($values));

    foreach ($values as $name => $value) {
        expect($parsed[$name])->toBe($value);
    }
});

it('marks a CLI install as installed too', function () {
    $this->artisan('ecso:install', [
        '--skip-env-check' => true, '--admin-name' => 'Root', '--admin-email' => 'cli@example.com', '--admin-password' => 'a-very-strong-pass-1',
    ])->assertSuccessful();

    expect(file_exists(config('ecso.installer.lock_path')))->toBeTrue();
});
