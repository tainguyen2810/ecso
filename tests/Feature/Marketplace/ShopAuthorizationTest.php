<?php

use App\Modules\Access\Models\Role;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthorizingTestConnector;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function useTestMarketplace(): void
{
    config([
        'marketplaces.connectors.testmp' => AuthorizingTestConnector::class,
        'marketplaces.credentials.testmp' => ['partner_id' => '1', 'partner_key' => 'k'],
    ]);
}

function stateFrom(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return $q['state'];
}

it('registers Shopee as a skeleton whose capabilities are all UNKNOWN', function () {
    $connector = app(ConnectorRegistry::class)->for('shopee');

    expect($connector)->toBeInstanceOf(ShopeeConnector::class)
        ->and(array_unique(array_values($connector->capabilities())))->toBe([Capability::UNKNOWN])
        ->and($connector->verifyWebhook('{}', []))->toBeFalse();

    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/marketplaces')->assertOk()
        ->assertJsonPath('data.0.key', 'shopee')
        ->assertJsonPath('data.0.available', true)
        ->assertJsonPath('data.0.credentials_configured', false)
        ->assertJsonPath('data.0.capabilities', fn ($c) => collect($c)->every(fn ($s) => $s === 'unknown'));
});

it('refuses to connect Shopee without app credentials, then reports UNKNOWN instead of faking OAuth', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->postJson('/api/v1/marketplace-accounts/authorize/shopee')->assertStatus(422)->assertJsonPath('code', 'CREDENTIALS_MISSING');

    config(['marketplaces.credentials.shopee' => ['partner_id' => '123', 'partner_key' => 'secret']]);
    $api->postJson('/api/v1/marketplace-accounts/authorize/shopee')->assertStatus(422)
        ->assertJsonPath('code', 'UNSUPPORTED_CAPABILITY')
        ->assertJsonPath('capability', 'shop.authorize')
        ->assertJsonPath('state', 'unknown');

    expect(MarketplaceAccount::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects Shopee webhooks: the signature scheme is not verified, nothing is processed', function () {
    $this->postJson('/api/v1/webhooks/shopee', ['shop_id' => 1, 'code' => 3])->assertStatus(401);

    expect(WebhookEvent::query()->where('marketplace', 'shopee')->value('status'))->toBe(WebhookEvent::REJECTED);
});

it('connects a shop through the generic authorization flow and stores tokens encrypted', function () {
    useTestMarketplace();
    $api = actingInTenant($this->owner, $this->tenant);

    $url = $api->postJson('/api/v1/marketplace-accounts/authorize/testmp')->assertOk()->json('data.url');
    $state = stateFrom($url);
    expect($url)->toContain('redirect=')->toContain(urlencode('/marketplaces/testmp/callback'));

    $api->postJson('/api/v1/marketplace-accounts/callback/testmp', ['state' => $state, 'params' => ['code' => 'abc', 'shop_id' => '777']])
        ->assertCreated()->assertJsonPath('data.external_shop_id', '777')->assertJsonPath('data.status', 'connected')
        ->assertJsonMissingPath('data.access_token');

    $account = MarketplaceAccount::withoutGlobalScopes()->where('external_shop_id', '777')->firstOrFail();
    expect($account->tenant_id)->toBe($this->tenant->id);
    $raw = DB::table('marketplace_credentials')->where('marketplace_account_id', $account->id)->value('access_token');
    expect($raw)->not->toContain('access-abc')
        ->and(MarketplaceCredential::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->first()->access_token)->toBe('access-abc');

    // The state is single use.
    $api->postJson('/api/v1/marketplace-accounts/callback/testmp', ['state' => $state, 'params' => ['code' => 'x', 'shop_id' => '888']])
        ->assertStatus(422)->assertJsonValidationErrors('state');
});

it('binds the callback to the tenant and user that started it', function () {
    useTestMarketplace();
    $state = stateFrom(actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/marketplace-accounts/authorize/testmp')->json('data.url'));

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->postJson('/api/v1/marketplace-accounts/callback/testmp', ['state' => $state, 'params' => ['code' => 'a', 'shop_id' => '1']])
        ->assertStatus(422)->assertJsonValidationErrors('state');

    expect(MarketplaceAccount::withoutGlobalScopes()->count())->toBe(0);
});

it('does not let a shop already connected to another tenant be taken over', function () {
    useTestMarketplace();
    $connect = function ($tenant, $user, $shop) {
        $api = actingInTenant($user, $tenant);
        $state = stateFrom($api->postJson('/api/v1/marketplace-accounts/authorize/testmp')->json('data.url'));

        return $api->postJson('/api/v1/marketplace-accounts/callback/testmp', ['state' => $state, 'params' => ['code' => 'c', 'shop_id' => $shop]]);
    };

    $connect($this->tenant, $this->owner, '555')->assertCreated();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $connect($other, $otherOwner, '555')->assertStatus(422)->assertJsonValidationErrors('shop');

    // Reconnecting the same shop in the same tenant refreshes it instead of duplicating it.
    $connect($this->tenant, $this->owner, '555')->assertCreated();
    expect(MarketplaceAccount::withoutGlobalScopes()->where('external_shop_id', '555')->count())->toBe(1);
});

it('requires the connect permission', function () {
    useTestMarketplace();
    $staff = addMember($this->tenant, Role::WAREHOUSE_STAFF);

    actingInTenant($staff, $this->tenant)->postJson('/api/v1/marketplace-accounts/authorize/testmp')->assertForbidden();
});

it('returns UNSUPPORTED_CAPABILITY for order actions on a Shopee order', function () {
    $variant = makeVariant($this->tenant, 'AO-1');
    $account = TenantContext::run($this->tenant, fn () => MarketplaceAccount::query()->forceCreate([
        'marketplace' => 'shopee', 'external_shop_id' => 'sp-1', 'shop_name' => 'Shopee shop', 'region' => 'VN',
        'status' => MarketplaceAccount::CONNECTED, 'warehouse_id' => defaultWarehouse($this->tenant)->id, 'connected_at' => now(),
    ]));
    $order = TenantContext::run($this->tenant, fn () => Order::query()->forceCreate([
        'marketplace_account_id' => $account->id, 'marketplace' => 'shopee', 'external_order_id' => 'SP1',
        'raw_status' => 'READY_TO_SHIP', 'status' => 'READY_TO_PACK', 'placed_at' => now(),
    ]));

    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/orders/{$order->ulid}/actions/pack")
        ->assertStatus(422)->assertJsonPath('code', 'UNSUPPORTED_CAPABILITY')->assertJsonPath('state', 'unknown');
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/orders/{$order->ulid}")
        ->assertJsonPath('data.actions.pack.capability', 'unknown');
});

it('enforces the shop limit of the plan when a new shop comes back from authorization', function () {
    useTestMarketplace();
    $limit = TenantContext::run($this->tenant, fn () => app(TenantContext::class)->entitlements()->limit('limit.shops'));
    for ($i = 0; $i < $limit; $i++) {
        fakeShop($this->tenant, false, "Shop {$i}");
    }
    $api = actingInTenant($this->owner, $this->tenant);
    $state = stateFrom($api->postJson('/api/v1/marketplace-accounts/authorize/testmp')->json('data.url'));

    $api->postJson('/api/v1/marketplace-accounts/callback/testmp', ['state' => $state, 'params' => ['code' => 'c', 'shop_id' => '999']])
        ->assertStatus(403)->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');
});
