<?php

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\DemoStoreService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/** A2: setup steps after sign-up, and the user's own sample store. */
it('sends a business made in the app through the setup steps and saves the answers', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $made = $this->postJson('/api/v1/tenants', ['name' => 'Thời Trang An Nhiên'])->assertCreated()
        ->assertJsonPath('data.setup_completed', false)->assertJsonPath('data.is_demo', false);
    $tenant = Tenant::query()->where('ulid', $made->json('data.id'))->sole();
    $api = $this->withHeader('X-Tenant', $tenant->ulid);

    $api->putJson('/api/v1/tenant/settings/store', ['phone' => '0909123456', 'address' => '12 Lê Lợi', 'province' => 'TP. Hồ Chí Minh'])->assertOk();
    $api->putJson('/api/v1/tenant/setup', ['industry' => 'fashion'])->assertOk()->assertJsonPath('data.setup_completed', false);
    $api->putJson('/api/v1/tenant/setup', ['channels' => ['shopee', 'pos', 'shopee'], 'team_size' => '2-5'])->assertOk()
        ->assertJsonPath('data.channels', ['shopee', 'pos'])->assertJsonPath('data.team_size', '2-5');
    $api->putJson('/api/v1/tenant/setup', ['channels' => ['facebook']])->assertUnprocessable()->assertJsonValidationErrors('channels.0');
    $api->putJson('/api/v1/tenant/setup', ['complete' => true])->assertOk()->assertJsonPath('data.setup_completed', true);

    expect($tenant->refresh()->settings['setup'])->toEqual(['industry' => 'fashion', 'channels' => ['shopee', 'pos'], 'team_size' => '2-5'])
        ->and(defaultWarehouse($tenant)->address)->toBe('12 Lê Lợi, TP. Hồ Chí Minh');
    $api->getJson('/api/v1/tenant')->assertJsonPath('data.tenant.setup_completed', true);

    // Staff without settings.manage cannot answer for the owner.
    $staff = addMember($tenant, 'ORDER_STAFF');
    actingInTenant($staff, $tenant)->putJson('/api/v1/tenant/setup', ['complete' => true])->assertForbidden();
});

it('counts businesses made by seeders and commands as set up', function () {
    [$tenant] = tenantWithOwner();
    expect($tenant->setup_completed_at)->not->toBeNull();
});

it('makes one sample store per user, filled with fake data, and deletes it without touching the real store', function () {
    config(['ecso.demo_store_days' => 7, 'marketplaces.fake.enabled' => false]);
    [$real, $owner] = tenantWithOwner('Cửa hàng thật');
    $realProduct = makeVariant($real, 'THAT-01');
    Sanctum::actingAs($owner);

    $made = $this->postJson('/api/v1/demo-store')->assertCreated()
        ->assertJsonPath('data.is_demo', true)->assertJsonPath('data.demo_status', 'ready')->assertJsonPath('data.setup_completed', true);
    $this->postJson('/api/v1/demo-store')->assertOk()->assertJsonPath('data.id', $made->json('data.id')); // still one

    $demo = Tenant::query()->where('ulid', $made->json('data.id'))->sole();
    expect($demo->name)->toBe(DemoStoreService::NAME)
        ->and($demo->demo_expires_at->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and(Order::withoutGlobalScopes()->where('tenant_id', $demo->id)->count())->toBeGreaterThan(7)
        ->and(app(StockVerifier::class)->verify($demo->id))->toBe([])
        ->and($demo->subscription->plan->key)->toBe('pro')->and($demo->subscription->current_period_end)->toBeNull()
        // The owner is the only member: no extra login was made.
        ->and($demo->memberships()->count())->toBe(1);
    $this->getJson('/api/v1/tenants')->assertJsonCount(2, 'data');

    // A sample store cannot connect real accounts.
    $this->withHeader('X-Tenant', $demo->ulid)->postJson('/api/v1/marketplace-accounts/tiki/connect', ['client_id' => 'x', 'client_secret' => 'y'])
        ->assertForbidden()->assertJsonPath('code', 'DEMO_STORE');
    $this->withHeader('X-Tenant', $demo->ulid)->postJson('/api/v1/zalo/account', [])->assertForbidden();

    // Someone else cannot delete it; the real store cannot be deleted this way.
    Sanctum::actingAs(User::factory()->create());
    $this->deleteJson("/api/v1/demo-store/{$demo->ulid}")->assertNotFound();
    Sanctum::actingAs($owner);
    $this->deleteJson("/api/v1/demo-store/{$real->ulid}")->assertNotFound();
    expect(fn () => app(DemoStoreService::class)->purge($real))->toThrow(RuntimeException::class);

    $this->deleteJson("/api/v1/demo-store/{$demo->ulid}")->assertOk();

    // Nothing of the sample store is left in any table; the real store is untouched.
    $left = collect(DB::select("SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id'"))
        ->filter(fn ($row) => DB::table($row->t)->where('tenant_id', $demo->id)->exists())->pluck('t')->all();
    expect($left)->toBe([])
        ->and(Tenant::query()->whereKey($demo->id)->exists())->toBeFalse()
        ->and(DB::table('stock_receipt_lines')->whereNotIn('stock_receipt_id', DB::table('stock_receipts')->select('id'))->exists())->toBeFalse()
        ->and($real->refresh()->exists)->toBeTrue()
        ->and(DB::table('product_variants')->where('id', $realProduct->id)->exists())->toBeTrue();
    $this->getJson('/api/v1/tenants')->assertJsonCount(1, 'data');
});

it('deletes sample stores past their 30 days every night', function () {
    config(['ecso.demo_store_days' => 7, 'marketplaces.fake.enabled' => false]);
    $user = User::factory()->create();
    $demo = app(DemoStoreService::class)->createFor($user);
    [$real] = tenantWithOwner();

    $this->artisan('ecso:demo-stores:prune')->assertSuccessful()->expectsOutputToContain('Đã xóa 0');
    $demo->forceFill(['demo_expires_at' => now()->subMinute()])->save();
    $this->artisan('ecso:demo-stores:prune')->assertSuccessful()->expectsOutputToContain('Đã xóa 1');

    expect(Tenant::query()->whereKey($demo->id)->exists())->toBeFalse()
        ->and(Tenant::query()->whereKey($real->id)->exists())->toBeTrue();
});
