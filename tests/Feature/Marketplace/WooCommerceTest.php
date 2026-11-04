<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Marketplace\Models\MarketplaceListing;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeWooStore;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->mouse = makeVariant($this->tenant, 'G304-BK', ['product_name' => 'Chuột G304', 'ref_price' => 499000]);
    stockIn($this->tenant, $this->mouse, 12);
    $this->store = new FakeWooStore;
    $this->store->addSimple(11, 'G304-BK', 'Chuột Logitech G304', '549000', '499000', stock: 40);
    $this->store->addVariable(20, 'Áo thun', [[21, 'AO-DEN', 'Đen', '150000'], [22, 'AO-TRANG', 'Trắng', '150000']]);
    config(['app.url' => 'https://erp.example.vn']);
});

function wooConnect($test): MarketplaceAccount
{
    actingInTenant($test->owner, $test->tenant)->postJson('/api/v1/marketplace-accounts/woocommerce/keys', [
        'website' => 'shop.example.vn', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ])->assertCreated();

    return MarketplaceAccount::withoutGlobalScopes()->where('marketplace', 'woocommerce')->sole();
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

it('connects through the store approval screen: Woo posts the keys, the user comes back', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $url = $api->postJson('/api/v1/marketplace-accounts/woocommerce/authorize', ['website' => 'https://shop.example.vn/'])
        ->assertOk()->json('data.url');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    expect($url)->toStartWith('https://shop.example.vn/wc-auth/v1/authorize?')
        ->and($q['scope'])->toBe('read_write')
        ->and($q['callback_url'])->toBe('https://erp.example.vn/api/v1/woocommerce/auth-callback');

    // Coming back before Woo delivered the keys is explained, not a crash.
    $api->postJson('/api/v1/marketplace-accounts/woocommerce/complete', ['state' => $q['user_id']])->assertStatus(422)
        ->assertJsonPath('errors.state.0', fn ($m) => str_contains($m, 'Nhập khóa API thủ công'));

    // An unknown state is refused; the real one is accepted (public endpoint, no session).
    $this->postJson('/api/v1/woocommerce/auth-callback', ['user_id' => 'nope', 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x'])->assertStatus(400);
    $this->postJson('/api/v1/woocommerce/auth-callback', ['key_id' => 1, 'user_id' => $q['user_id'], 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test', 'key_permissions' => 'read_write'])->assertOk();

    $api->postJson('/api/v1/marketplace-accounts/woocommerce/complete', ['state' => $q['user_id']])->assertCreated()
        ->assertJsonPath('data.marketplace', 'woocommerce')
        ->assertJsonPath('data.shop_name', 'Cửa hàng Woo')
        ->assertJsonPath('checks.woocommerce_version', '9.3.3')
        ->assertJsonPath('checks.products', 2)
        ->assertJsonPath('checks.webhooks', ['order.created', 'order.updated', 'product.created', 'product.updated', 'product.deleted']);

    $account = MarketplaceAccount::withoutGlobalScopes()->where('marketplace', 'woocommerce')->sole();
    $credential = MarketplaceCredential::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->sole();
    expect($account->external_shop_id)->toBe('shop.example.vn')
        ->and($account->base_url)->toBe('https://shop.example.vn')
        ->and($credential->access_token)->toBe('ck_test')
        ->and($credential->getRawOriginal('access_token'))->not->toContain('ck_test')
        ->and($credential->webhook_secret)->toBe(end($this->store->webhooks)['secret'])
        ->and($this->store->webhooks)->toHaveCount(5)
        ->and(collect($this->store->webhooks)->pluck('delivery_url')->unique()->all())->toBe(['https://erp.example.vn/api/v1/webhooks/woocommerce']);

    // The state is single use.
    $api->postJson('/api/v1/marketplace-accounts/woocommerce/complete', ['state' => $q['user_id']])->assertStatus(422);
});

it('imports simple products and variations and suggests exact SKU matches only', function () {
    $account = wooConnect($this);

    $rows = ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->get()->keyBy('seller_sku');
    expect($rows)->toHaveCount(3)
        ->and($rows['G304-BK']->mapping_status)->toBe(ListingVariant::SUGGESTED)
        ->and((string) $rows['G304-BK']->price)->toBe('499000.00') // the sale price is what a buyer pays
        ->and($rows['AO-DEN']->name)->toBe('Đen')
        ->and($rows['AO-DEN']->mapping_status)->toBe(ListingVariant::UNMAPPED);
});

it('refuses http, private addresses, read-only keys and a store owned by another tenant', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $post = fn (array $d) => $api->postJson('/api/v1/marketplace-accounts/woocommerce/keys', $d + ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']);

    $post(['website' => 'http://shop.example.vn'])->assertStatus(422)->assertJsonPath('errors.website.0', fn ($m) => str_contains($m, 'HTTPS'));
    $post(['website' => 'https://127.0.0.1'])->assertStatus(422)->assertJsonPath('errors.website.0', fn ($m) => str_contains($m, 'nội bộ'));
    $post(['website' => 'https://localhost'])->assertStatus(422);

    $this->store->readOnly = true;
    $post(['website' => 'shop.example.vn'])->assertStatus(422)->assertJsonPath('errors.website.0', fn ($m) => str_contains($m, 'Đọc/Ghi'));
    $this->store->readOnly = false;

    $post(['website' => 'shop.example.vn'])->assertCreated();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->postJson('/api/v1/marketplace-accounts/woocommerce/keys', [
        'website' => 'https://shop.example.vn', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ])->assertStatus(422)->assertJsonPath('errors.website.0', fn ($m) => str_contains($m, 'doanh nghiệp khác'));
});

it('falls back to query-string keys when the host strips the Authorization header', function () {
    $this->store->stripAuthorization = true;
    $account = wooConnect($this);

    expect($account->settings['auth'])->toBe('query')
        ->and(ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->count())->toBe(3);
});

it('takes a signed order webhook into the order list as a WooCommerce order and holds stock', function () {
    $account = wooConnect($this);
    wooMap($this, $account);
    $order = $this->store->addOrder(501, 'processing', [[11, 2, 'G304-BK', 499000]]);

    wooWebhook($this, 'order.created', $order)->assertOk();

    $saved = Order::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->sole();
    expect($saved->marketplace)->toBe('woocommerce')
        ->and($saved->external_order_id)->toBe('501')
        ->and($saved->status)->toBe('READY_TO_PACK')
        ->and($saved->is_cod)->toBeTrue()
        ->and($saved->buyer_name)->toBe('Lan Nguyễn')
        ->and((string) $saved->total)->toBe('1028000.00')
        ->and(TenantContext::run($this->tenant, fn () => $saved->items()->sole()->product_variant_id))->toBe($this->mouse->id)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->mouse)->reserved)->toBe(2);

    // Stock pushed to Woo = available to sell.
    expect($this->store->products[11]['stock_quantity'])->toBe(10);

    // Same snapshot again (Woo retry with a new delivery id) is a duplicate.
    wooWebhook($this, 'order.created', $order)->assertOk()->assertJsonPath('duplicate', true);
});

it('rejects webhooks with a wrong signature or from an unknown store, and answers the ping', function () {
    wooConnect($this);
    $order = $this->store->addOrder(502, 'processing', [[11, 1, 'G304-BK', 499000]]);

    wooWebhook($this, 'order.created', $order, secret: 'wrong')->assertStatus(401);
    [$body, $headers] = $this->store->signed('order.created', $order, source: 'https://evil.example.com/');
    $this->call('POST', '/api/v1/webhooks/woocommerce', [], [], [], collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all(), $body)->assertStatus(401);
    $this->call('POST', '/api/v1/webhooks/woocommerce', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'webhook_id=903')->assertOk();

    expect(Order::withoutGlobalScopes()->count())->toBe(0)
        ->and(WebhookEvent::query()->where('status', WebhookEvent::REJECTED)->count())->toBe(1); // same body: one monitoring row
});

it('confirms, packs, hands over and cancels on the store, and reads the result back', function () {
    $account = wooConnect($this);
    wooMap($this, $account);
    wooWebhook($this, 'order.created', $this->store->addOrder(601, 'on-hold', [[11, 1, 'G304-BK', 499000]], 'bacs'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '601')->sole();
    expect($order->status)->toBe('PENDING_CONFIRMATION');

    $api = actingInTenant($this->owner, $this->tenant);
    foreach (['confirm' => 'READY_TO_PACK', 'pack' => 'PACKED', 'handover' => 'HANDED_OVER'] as $action => $status) {
        $api->postJson("/api/v1/orders/{$order->ulid}/actions/{$action}")->assertOk();
        expect($order->refresh()->status)->toBe($status);
    }
    expect($this->store->orders[601]['status'])->toBe('processing')
        ->and(collect($this->store->orders[601]['meta_data'])->firstWhere('key', '_ecso_stage')['value'])->toBe('handed_over');

    // The shop marks the order completed on Woo: the reconcile poll catches it even without a webhook.
    $this->store->orders[601]['status'] = 'completed';
    $this->store->orders[601]['date_modified_gmt'] = now()->addMinute()->utc()->format('Y-m-d\TH:i:s');
    Artisan::call('ecso:orders:reconcile');
    expect($order->refresh()->status)->toBe('COMPLETED');

    wooWebhook($this, 'order.created', $this->store->addOrder(602, 'processing', [[11, 1, 'G304-BK', 499000]]));
    $second = Order::withoutGlobalScopes()->where('external_order_id', '602')->sole();
    $api->postJson("/api/v1/orders/{$second->ulid}/actions/cancel", ['reason' => 'Khách đổi ý'])->assertOk();
    expect($second->refresh()->status)->toBe('CANCELLED')->and($this->store->orders[602]['status'])->toBe('cancelled');
});

it('re-reads one product after a product webhook and respects the stock and order switches', function () {
    $account = wooConnect($this);
    wooMap($this, $account);

    $this->store->addSimple(30, 'SSD-990', 'SSD 990 Pro', '3190000', '2890000');
    wooWebhook($this, 'product.created', $this->store->products[30])->assertOk();
    expect(ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->where('seller_sku', 'SSD-990')->exists())->toBeTrue();

    unset($this->store->products[30]);
    wooWebhook($this, 'product.deleted', ['id' => 30, 'date_modified_gmt' => 'x'])->assertOk();
    expect(MarketplaceListing::withoutGlobalScopes()->where('external_product_id', '30')->value('status'))->toBe('deleted');

    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/marketplace-accounts/{$account->ulid}", ['settings' => ['sync_stock' => false, 'sync_orders' => false]])
        ->assertOk()->assertJsonPath('data.settings.sync_stock', false)->assertJsonPath('data.settings.sync_prices', false);

    TenantContext::run($this->tenant, fn () => app(InventoryService::class)->setSafetyStock(defaultWarehouse($this->tenant), $this->mouse, 5));
    expect($this->store->products[11]['stock_quantity'])->toBe(12);

    wooWebhook($this, 'order.created', $this->store->addOrder(701, 'processing', [[11, 1, 'G304-BK', 499000]]))->assertOk();
    expect(Order::withoutGlobalScopes()->count())->toBe(0)
        ->and(WebhookEvent::query()->latest('id')->value('result'))->toContain('tắt đồng bộ đơn');
});

it('removes its webhooks from the store when disconnected', function () {
    $account = wooConnect($this);
    expect($this->store->webhooks)->toHaveCount(5);

    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/marketplace-accounts/{$account->ulid}/disconnect")->assertOk();

    expect($this->store->webhooks)->toBeEmpty()
        ->and(MarketplaceCredential::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->exists())->toBeFalse();
});

it('lists store products missing in ECSO for approval, creates them at the sale price and links them', function () {
    $account = wooConnect($this);
    $api = actingInTenant($this->owner, $this->tenant);

    $api->getJson("/api/v1/marketplace-accounts/{$account->ulid}")->assertOk()
        ->assertJsonPath('overview.new_products', 1) // the T-shirt; the mouse matches an ECSO SKU
        ->assertJsonPath('data.settings.import_new_products', true);

    $this->store->addSimple(40, '', 'Cáp USB-C', '90000', '79000');
    wooWebhook($this, 'product.created', $this->store->products[40]);
    $pending = $api->getJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products")->assertOk()->json('data');
    expect(collect($pending)->pluck('title')->all())->toBe(['Cáp USB-C', 'Áo thun'])
        ->and($pending[0]['variants'][0]['sale_price'])->toBe('79000.00');

    $api->postJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products/skip", ['listing_ids' => [$pending[0]['id']]])->assertOk()->assertJsonPath('data.skipped', 1);
    $api->postJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products/approve", ['all' => true])->assertOk()->assertJsonPath('data.created', 1);

    $variants = TenantContext::run($this->tenant, fn () => ProductVariant::query()->whereIn('sku', ['AO-DEN', 'AO-TRANG'])->with('product')->get());
    expect($variants)->toHaveCount(2)
        ->and($variants->first()->product->name)->toBe('Áo thun')
        ->and((string) $variants->first()->ref_price)->toContain('150000')
        ->and(ListingVariant::withoutGlobalScopes()->where('seller_sku', 'AO-DEN')->value('mapping_status'))->toBe(ListingVariant::MAPPED)
        ->and($api->getJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products")->json('data'))->toBe([]);

    // Approving again finds nothing new and creates no duplicate.
    $api->postJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products/approve", ['all' => true])->assertOk()->assertJsonPath('data.created', 0);
});
