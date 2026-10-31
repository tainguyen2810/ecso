<?php

use App\Logging\RedactSecrets;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('sends baseline security headers, and a CSP + HSTS on production', function () {
    $this->get('/login')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('Content-Security-Policy');
    $this->getJson('/api/v1/marketplaces')->assertHeader('X-Content-Type-Options', 'nosniff');

    app()->detectEnvironment(fn () => 'production');
    $response = $this->get('https://localhost/up');
    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")->toContain("script-src 'self'")
        ->and($response->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

it('redacts credentials and personal data in log context at any depth', function () {
    $out = RedactSecrets::redact([
        'shop' => 'A', 'access_token' => 'tok', 'Authorization' => 'Bearer x', 'email' => 'nguyen@example.com',
        'payload' => ['refresh_token' => 'r', 'buyer' => ['phone' => '0901234567', 'shipping_address' => '1 Lê Lợi']], 'ok' => 1,
    ]);

    expect($out['shop'])->toBe('A')->and($out['access_token'])->toBe('[REDACTED]')->and($out['Authorization'])->toBe('[REDACTED]')
        ->and($out['email'])->toBe('n***@example.com')->and($out['payload']['refresh_token'])->toBe('[REDACTED]')
        ->and($out['payload']['buyer']['phone'])->toBe('[REDACTED]')->and($out['payload']['buyer']['shipping_address'])->toBe('[REDACTED]');
});

it('applies redaction to the configured log channels', function () {
    $path = storage_path('logs/redaction-test.log');
    @unlink($path);
    config(['logging.channels.redaction_test' => ['driver' => 'single', 'path' => $path, 'tap' => [RedactSecrets::class]]]);

    Log::channel('redaction_test')->warning('push failed', ['access_token' => 'SECRET-TOKEN-123', 'shop' => 'S1']);

    $line = file_get_contents($path);
    @unlink($path);
    expect($line)->toContain('[REDACTED]')->toContain('S1')->not->toContain('SECRET-TOKEN-123');
    // stack delegates to other channels; emergency is Laravel's last-resort fallback.
    expect(collect(config('logging.channels'))->except(['stack', 'emergency', 'redaction_test'])->every(fn ($c) => in_array(RedactSecrets::class, $c['tap'] ?? [], true)))->toBeTrue();
});

it('shows super admins queue, failed job and scheduler health without tenant data, and alerts on problems', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'webhooks',
        'payload' => json_encode(['displayName' => 'App\\Modules\\Orders\\Jobs\\ProcessWebhook']),
        'exception' => "RuntimeException: buyer Nguyễn Văn A 0901234567 not found\n#0 trace", 'failed_at' => now(),
    ]);
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();
    Sanctum::actingAs($admin);

    $data = $this->getJson('/api/v1/admin/monitoring')->assertOk()->json('data');
    expect($data['failed_jobs_24h'])->toBe(1)
        ->and($data['failed_jobs_recent'][0])->toMatchArray(['queue' => 'webhooks', 'job' => 'ProcessWebhook', 'exception' => 'RuntimeException'])
        ->and(json_encode($data))->not->toContain('0901234567')
        ->and($data['scheduler_ok'])->toBeFalse()
        ->and($data['problems'])->toContain('Scheduler không chạy (không có heartbeat)');

    Log::spy();
    $this->artisan('ecso:monitor')->assertSuccessful();
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'Scheduler'))->once();
});
