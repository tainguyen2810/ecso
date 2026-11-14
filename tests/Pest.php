<?php

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\AccountService;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Arch');
// Concurrency tests need committed data visible to other processes: no wrapping transaction.
// Excluded from the default run (phpunit.xml); run with: php artisan test --group=concurrency
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->group('concurrency')->in('Concurrency');

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

/**
 * Sets the tenant context for service-level tests (as a job or command would).
 */
function inTenant(Tenant $tenant): void
{
    app(TenantContext::class)->set($tenant);
}

function makeVariant(Tenant $tenant, ?string $sku = null, array $attributes = []): ProductVariant
{
    return TenantContext::run($tenant, function () use ($sku, $attributes) {
        $product = Product::create(['name' => $attributes['product_name'] ?? 'Sản phẩm '.Str::random(4), 'status' => 'active']);

        return $product->variants()->create(['sku' => $sku ?? 'SKU-'.Str::upper(Str::random(6))] + collect($attributes)->except('product_name')->all());
    });
}

function defaultWarehouse(Tenant $tenant): Warehouse
{
    return Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_default', true)->firstOrFail();
}

function balanceOf(Warehouse $warehouse, ProductVariant $variant): ?StockBalance
{
    return StockBalance::withoutGlobalScopes()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variant->id)->first();
}

/**
 * Connects a FAKE marketplace shop for the tenant (its listings are copied from the catalog) and, by default,
 * confirms the exact-SKU suggestions so listings are mapped.
 */
function fakeShop(Tenant $tenant, bool $map = true, string $name = 'Shop giả lập'): MarketplaceAccount
{
    return TenantContext::run($tenant, function () use ($map, $name) {
        $account = app(AccountService::class)->connectFake($name, null);
        if ($map) {
            app(ListingService::class)->confirmSuggestions($account);
        }

        return $account->refresh();
    });
}

/** The fake marketplace SKU id whose seller SKU is the variant's SKU. */
function fakeSkuOf(MarketplaceAccount $account, ProductVariant $variant): string
{
    return DB::table('fake_marketplace_products')->where('shop_id', $account->external_shop_id)->where('seller_sku', $variant->sku)->value('sku_id');
}

/**
 * Creates an order on the fake marketplace (which sends its signed webhook through the real pipeline).
 *
 * @param  array<int, array{0: ProductVariant, 1: int}>  $lines
 */
function fakeOrder(MarketplaceAccount $account, array $lines, string $status = 'fake_ready', array $overrides = []): Order
{
    $remote = app(FakeMarketplace::class)->createOrder(
        $account->external_shop_id,
        array_map(fn ($l) => ['sku_id' => fakeSkuOf($account, $l[0]), 'quantity' => $l[1]], $lines),
        $status,
        $overrides,
    );

    return Order::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->where('external_order_id', $remote['order_sn'])->firstOrFail();
}

function stockIn(Tenant $tenant, ProductVariant $variant, int $quantity, ?Warehouse $warehouse = null): void
{
    TenantContext::run($tenant, fn () => app(InventoryService::class)->receive(
        $warehouse ?? defaultWarehouse($tenant), [['variant_id' => $variant->id, 'quantity' => $quantity, 'unit_cost' => 50000]], 'stock-in-'.Str::uuid()
    ));
}

/** The shop-counter channel every business starts with. */
function defaultChannel($tenant): SalesChannel
{
    return SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
}

function createSalesOrder($user, $tenant, array $lines, array $extra = []): TestResponse
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => defaultChannel($tenant)->ulid,
        'lines' => array_map(fn ($l) => ['variant_id' => $l[0]->ulid, 'quantity' => $l[1], 'unit_price' => $l[2] ?? 100000], $lines),
        ...$extra,
    ]);
}

// WooCommerce tests (tests/Support/FakeWooStore.php is the simulated store; $test->store, ->owner, ->tenant).

function wooConnect($test): MarketplaceAccount
{
    actingInTenant($test->owner, $test->tenant)->postJson('/api/v1/marketplace-accounts/woocommerce/keys', [
        'website' => 'shop.example.vn', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ])->assertCreated()->assertJsonPath('data.setup_pending', true);

    // A5: a new store starts in the setup steps (no stock pushed); these tests work on a store already set up.
    $account = MarketplaceAccount::withoutGlobalScopes()->where('marketplace', 'woocommerce')->sole();
    $settings = (array) $account->settings;
    unset($settings['setup']);
    $account->forceFill(['settings' => $settings])->save();

    return $account;
}

function wooMap($test, MarketplaceAccount $account): void
{
    TenantContext::run($test->tenant, fn () => actingInTenant($test->owner, $test->tenant)
        ->postJson("/api/v1/marketplace-accounts/{$account->ulid}/listings/confirm-suggestions")->assertOk());
}

function wooWebhook($test, string $topic, array $payload, ?string $secret = null)
{
    [$body, $headers] = $test->store->signed($topic, $payload, $secret);

    return $test->call('POST', '/api/v1/webhooks/woocommerce', [], [], [], collect($headers)
        ->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['CONTENT_TYPE' => 'application/json'], $body);
}

/** A voucher type id by name. */
function cashType($user, $tenant, string $direction, string $name): string
{
    return collect(actingInTenant($user, $tenant)->getJson("/api/v1/cash-voucher-types?direction={$direction}")->assertOk()->json('data'))->firstWhere('name', $name)['id'];
}

/** A completed counter sale on credit for a customer. */
function cashSale($user, $tenant, $variant, int $price, string $customer): string
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => defaultChannel($tenant)->ulid, 'stage' => 'completed', 'customer_id' => $customer,
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1, 'unit_price' => $price]],
    ])->assertCreated()->json('data.id');
}

/** POST with an Idempotency-Key header. */
function postOnce($user, $tenant, string $url, array $body, string $key)
{
    $test = actingInTenant($user, $tenant)->withHeader('Idempotency-Key', $key);
    $response = $test->postJson($url, $body);
    $test->flushHeaders(); // the next request of the test sends no key unless it asks for one

    return $response;
}

/** Takes one permission away from a system role, as a business tightening its staff rights would. */
function revoke(string $role, string $permission): void
{
    Role::query()->whereNull('tenant_id')->where('key', $role)->firstOrFail()
        ->permissions()->detach(Permission::query()->where('key', $permission)->value('id'));
}

/** The fund named $name, from the funds list. */
function fund($user, $tenant, string $name): array
{
    return collect(actingInTenant($user, $tenant)->getJson('/api/v1/cash-accounts')->assertOk()->json('data'))->firstWhere('name', $name);
}
