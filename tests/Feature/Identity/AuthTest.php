<?php

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

it('registers a user, logs them in and sends a verification email', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Nguyễn An',
        'email' => 'An@Example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ])->assertCreated()->assertJsonPath('data.email', 'an@example.com')->assertJsonPath('data.email_verified', false);

    $user = User::query()->where('email', 'an@example.com')->firstOrFail();
    expect($user->is_super_admin)->toBeFalse();
    $this->assertAuthenticatedAs($user, 'web');
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('rejects weak passwords and duplicate emails', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'X', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
});

it('cannot self-assign super admin through registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Hacker', 'email' => 'h@example.com', 'password' => 'secret123',
        'password_confirmation' => 'secret123', 'is_super_admin' => true, 'status' => 'active',
    ])->assertCreated();

    expect(User::query()->where('email', 'h@example.com')->value('is_super_admin'))->toBeFalsy();
});

it('logs in with valid credentials and records last login', function () {
    $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'secret123']);

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secret123'])
        ->assertOk()->assertJsonPath('data.id', $user->ulid);

    expect($user->fresh()->last_login_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_user_id' => $user->id]);
});

it('rejects wrong passwords and throttles after 5 attempts', function () {
    User::factory()->create(['email' => 'a@example.com', 'password' => 'secret123']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secret123'])->assertStatus(429);
});

it('refuses locked accounts', function () {
    $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'secret123']);
    $user->forceFill(['status' => User::STATUS_LOCKED])->save();

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secret123'])->assertUnprocessable();
    $this->assertGuest('web');
});

it('requires authentication for me and logout', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});

it('logs out', function () {
    $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'secret123']);
    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secret123'])->assertOk();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->assertGuest('web');
});

it('verifies email with the signed link only for the owner of the link', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->ulid, 'hash' => sha1($user->email)]);

    Sanctum::actingAs($other);
    $this->getJson($url)->assertForbidden();

    Sanctum::actingAs($user);
    $this->getJson($url.'x')->assertForbidden(); // tampered signature
    $this->getJson($url)->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('blocks unverified users from tenant features', function () {
    Sanctum::actingAs(User::factory()->unverified()->create());

    $this->getJson('/api/v1/tenants')->assertForbidden();
    $this->postJson('/api/v1/tenants', ['name' => 'Shop'])->assertForbidden();
});

it('sends reset links without revealing whether the email exists, and resets the password', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'a@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(202);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'a@example.com'])->assertStatus(202);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'invalid', 'email' => 'a@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
    ])->assertUnprocessable();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => 'a@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'newpass123'])->assertOk();
});
