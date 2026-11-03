<?php

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('creates a demo login with a fully stocked business and a sales history, without any super admin', function () {
    config(['marketplaces.fake.enabled' => false]); // as on a server: no simulated marketplace orders
    $this->artisan('ecso:demo-tenant', ['email' => 'demo@shop.test', '--days' => 14])->assertSuccessful();

    $owner = User::query()->where('email', 'demo@shop.test')->sole();
    expect($owner->is_super_admin)->toBeFalse()
        ->and(User::query()->where('is_super_admin', true)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'demo+kho@shop.test')->exists())->toBeTrue();

    $tenant = Tenant::query()->where('name', 'Cửa hàng Demo (dữ liệu mẫu)')->sole();
    $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id);
    expect((clone $orders)->count())->toBeGreaterThan(14)
        ->and((clone $orders)->where('placed_at', '<', now()->subDays(7))->exists())->toBeTrue()
        ->and((clone $orders)->where('placed_at', '>', now())->exists())->toBeFalse()
        ->and((clone $orders)->where('marketplace', '!=', 'manual')->exists())->toBeFalse()
        ->and(app(StockVerifier::class)->verify($tenant->id))->toBe([]);

    // The owner sees the history on the dashboard.
    actingInTenant($owner, $tenant)->getJson('/api/v1/orders/summary')->assertOk()
        ->assertJson(fn ($json) => $json->where('data.revenue_by_day', fn ($days) => collect($days)->where('orders', '>', 0)->count() >= 5)->etc());
});

it('refuses an existing email and production without --force', function () {
    User::query()->forceCreate(['email' => 'taken@shop.test', 'name' => 'X', 'password' => 'password1']);
    $this->artisan('ecso:demo-tenant', ['email' => 'taken@shop.test'])->assertFailed();

    app()->detectEnvironment(fn () => 'production');
    $this->artisan('ecso:demo-tenant', ['email' => 'new@shop.test'])->assertFailed();
    expect(User::query()->where('email', 'new@shop.test')->exists())->toBeFalse();
});
