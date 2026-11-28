<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Data\PriceUpdate;
use App\Modules\Marketplace\Connectors\Data\StockUpdate;
use App\Modules\Marketplace\Connectors\Data\StockUpdateResult;
use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Marketplace\Connectors\TikTok\TikTokConnector;
use App\Modules\Marketplace\Connectors\UpdatesPrices;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Jobs\PushPricesJob;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceCredential;
use App\Modules\Marketplace\Models\MarketplaceListing;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Marketplace\Services\AccountService;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\RemoteImage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Shopee and TikTok Shop through EzChat: a faked EzChat gateway that checks the signature like EzChat does. */
const EZ_URL = 'https://app.ezchat.test';
const EZ_SECRET = 'banhang-shared-secret-0123456789abcdef';

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    config(['marketplaces.ezchat.url' => EZ_URL, 'marketplaces.ezchat.secret' => EZ_SECRET]);
    foreach (['shopee', 'tiktok'] as $m) {
        config(["marketplaces.credentials.{$m}" => ['ezchat_url' => EZ_URL, 'ezchat_secret' => EZ_SECRET]]);
    }
    $calls = &ezCalls();
    $calls = [];
});

/** @return array<int, array<string, mixed>> calls the faked EzChat received in this test */
function &ezCalls(): array
{
    static $calls = [];

    return $calls;
}

/** EzChat's side: refuses a bad signature, records the call and answers with $answers[path] (or a closure). */
function fakeEzChat(array $answers): void
{
    Http::fake(function (Request $r) use ($answers) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if (! str_starts_with($r->url(), EZ_URL)) {
            // A file the marketplace links to (TikTok's label PDF, a product picture): not EzChat, no signature.
            $file = $answers[$r->url()] ?? null;

            return $file instanceof Closure ? $file($r) : Http::response($file ?? '', $file !== null ? 200 : 404);
        }
        $ts = $r->header('X-Banhang-Timestamp')[0] ?? '';
        if (! hash_equals(hash_hmac('sha256', "{$ts}.{$path}.{$r->body()}", EZ_SECRET), $r->header('X-Banhang-Signature')[0] ?? '')) {
            return Http::response(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid signature']], 401);
        }
        // As EzChat's schema: "query" must be a map, never a JSON list.
        $raw = json_decode($r->body());
        if ($path === '/api/banhang/gw/call' && ! is_object($raw->query ?? null)) {
            return Http::response(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Request validation failed']], 400);
        }
        $key = $path === '/api/banhang/gw/call' ? $r['method'].' '.$r['path'] : $path;
        $calls = &ezCalls();
        $calls[] = ['key' => $key, 'query' => $r['query'] ?? [], 'body' => $r['body'] ?? null, 'token' => $r->header('Authorization')[0] ?? null];
        $answer = $answers[$key] ?? ['data' => ['data' => []]];

        return $answer instanceof Closure ? $answer($r) : Http::response($answer);
    });
}

function gatewayShop($tenant, string $marketplace, string $shopId = '880011'): MarketplaceAccount
{
    return TenantContext::run($tenant, function () use ($tenant, $marketplace, $shopId) {
        $account = MarketplaceAccount::query()->forceCreate([
            'marketplace' => $marketplace, 'external_shop_id' => $shopId, 'shop_name' => 'Shop Bán Hàng', 'region' => 'VN',
            'status' => MarketplaceAccount::CONNECTED, 'warehouse_id' => defaultWarehouse($tenant)->id, 'connected_at' => now(),
        ]);
        MarketplaceCredential::query()->create(['marketplace_account_id' => $account->id, 'access_token' => 'share-token-1']);

        return $account;
    });
}

it('offers Shopee and TikTok Shop through EzChat once EZCHAT_URL and EZCHAT_SECRET are set', function () {
    foreach (['shopee' => ShopeeConnector::class, 'tiktok' => TikTokConnector::class] as $m => $class) {
        $connector = app(ConnectorRegistry::class)->for($m);
        expect($connector)->toBeInstanceOf($class)
            ->and($connector->capabilities()[Capability::ORDERS_FETCH])->toBe(Capability::SUPPORTED)
            ->and($connector->capabilities()[Capability::INVENTORY_UPDATE])->toBe(Capability::SUPPORTED)
            ->and($connector->capabilities()[Capability::ORDERS_PACK])->toBe(Capability::SUPPORTED)
            ->and($connector->capabilities()[Capability::ORDERS_CANCEL])->toBe(Capability::UNKNOWN);
    }
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/marketplaces')->assertOk()
        ->assertJsonPath('data.0.key', 'shopee')->assertJsonPath('data.0.credentials_configured', true);

    config(['marketplaces.credentials.shopee' => ['ezchat_url' => null, 'ezchat_secret' => null]]);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/marketplace-accounts/authorize/shopee')
        ->assertStatus(422)->assertJsonPath('code', 'CREDENTIALS_MISSING');
});

it('connects a shop shared from EzChat: picker on EzChat, code traded for a share token', function () {
    fakeEzChat([
        '/api/banhang/gw/exchange' => ['data' => [
            'token' => 'share-token-xyz',
            'shop' => ['type' => 'SHOPEE', 'shopId' => '880011', 'name' => 'Shop Bán Hàng', 'region' => 'VN'],
            'workspace' => ['id' => 'ten_1', 'name' => 'Demo'],
        ]],
        'GET /api/v2/product/get_item_list' => ['data' => ['data' => ['response' => ['item' => [], 'has_next_page' => false]]]],
    ]);
    $api = actingInTenant($this->owner, $this->tenant);

    $url = $api->postJson('/api/v1/marketplace-accounts/authorize/shopee')->assertOk()->json('data.url');
    expect($url)->toStartWith(EZ_URL.'/connect/banhang?type=SHOPEE');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    expect($q['redirect'])->toEndWith('/marketplaces/shopee/callback')
        // Signed, so EzChat can offer "Kết nối thẳng" to a seller without EzChat.
        ->and($q['sig'])->toBe(hash_hmac('sha256', "{$q['ts']}.direct.SHOPEE.{$q['state']}.{$q['redirect']}", EZ_SECRET));

    $api->postJson('/api/v1/marketplace-accounts/callback/shopee', ['state' => $q['state'], 'params' => ['code' => 'one-time', 'state' => $q['state']]])
        ->assertCreated()->assertJsonPath('data.external_shop_id', '880011')->assertJsonMissingPath('data.access_token');

    $account = MarketplaceAccount::withoutGlobalScopes()->where('marketplace', 'shopee')->sole();
    expect(MarketplaceCredential::withoutGlobalScopes()->where('marketplace_account_id', $account->id)->value('access_token'))->toBe('share-token-xyz')
        ->and(ezCalls()[0]['key'])->toBe('/api/banhang/gw/exchange');
});

it('refuses a shop of the other marketplace and a cancelled pick', function () {
    fakeEzChat(['/api/banhang/gw/exchange' => ['data' => ['token' => 't', 'shop' => ['type' => 'TIKTOK_SHOP', 'shopId' => '1'], 'workspace' => ['id' => 'w']]]]);
    $shopee = app(ConnectorRegistry::class)->for('shopee');

    expect(fn () => $shopee->completeAuthorization(['code' => 'c'], 'x'))->toThrow(ConnectorException::class, 'không thuộc sàn này')
        ->and(fn () => $shopee->completeAuthorization(['error' => 'cancelled'], 'x'))->toThrow(ConnectorException::class)
        ->and(fn () => $shopee->completeAuthorization(['error' => 'CHANNEL_EXISTS'], 'x'))->toThrow(ConnectorException::class, 'Tôi đang dùng EzChat');
});

it('"Kết nối thẳng": EZCHAT_CONNECT=direct goes straight to the marketplace through EzChat, and the shop has no workspace', function () {
    config(['marketplaces.ezchat.connect' => 'direct']);
    fakeEzChat(['/api/banhang/gw/exchange' => ['data' => [
        'token' => 'direct-token', 'direct' => true,
        'shop' => ['type' => 'TIKTOK_SHOP', 'shopId' => '7490001', 'name' => 'Shop Không Dùng EzChat', 'region' => 'VN'],
        'workspace' => ['id' => 'ten_connector', 'name' => 'hidden'],
    ]]]);
    $tiktok = app(ConnectorRegistry::class)->for('tiktok');

    $url = $tiktok->authorizationUrl('state-0123456789abcdef', 'https://banhang.test/marketplaces/tiktok/callback');
    expect($url)->toStartWith(EZ_URL.'/api/banhang/gw/direct?type=TIKTOK_SHOP');
    $auth = $tiktok->completeAuthorization(['code' => 'one-time-code', 'state' => 'state-0123456789abcdef'], 'x');
    expect($auth->scopes)->toBe(['ezchat:direct'])->and($auth->shopName)->toBe('Shop Không Dùng EzChat');
});

it('reads a Shopee order through EzChat with the share token', function () {
    fakeEzChat([
        'GET /api/v2/order/get_order_detail' => ['data' => ['data' => ['response' => ['order_list' => [[
            'order_sn' => '2411SN1', 'order_status' => 'SHIPPED', 'create_time' => 1731900000, 'update_time' => 1731903600, 'pay_time' => 1731900100,
            'payment_method' => 'COD', 'cod' => true, 'currency' => 'VND', 'total_amount' => 215000, 'actual_shipping_fee' => 15000,
            'buyer_user_id' => 99, 'buyer_username' => 'buyer.one', 'shipping_carrier' => 'SPX Express', 'message_to_seller' => 'Giao giờ hành chính',
            'recipient_address' => ['name' => 'N***n', 'phone' => '******89', 'full_address' => 'Q. Bình Tân, TP. HCM'],
            'item_list' => [
                ['item_id' => 11, 'item_name' => 'Áo thun', 'model_id' => 0, 'item_sku' => 'AO-1', 'model_quantity_purchased' => 2, 'model_original_price' => 100000, 'model_discounted_price' => 90000],
                ['item_id' => 12, 'item_name' => 'Quần', 'model_id' => 501, 'model_name' => 'Đen, M', 'model_sku' => 'QU-M', 'model_quantity_purchased' => 1, 'model_original_price' => 20000, 'model_discounted_price' => 20000],
            ],
        ]]]]]],
        'GET /api/v2/logistics/get_tracking_number' => ['data' => ['data' => ['response' => ['tracking_number' => 'SPXVN001']]]],
    ]);
    $account = gatewayShop($this->tenant, 'shopee');

    $order = app(ConnectorRegistry::class)->for('shopee')->getOrder($account, '2411SN1');

    expect($order->rawStatus)->toBe('SHIPPED')->and($order->isCod)->toBeTrue()->and($order->total)->toBe('215000')
        ->and($order->shippingFee)->toBe('15000')->and($order->trackingNumber)->toBe('SPXVN001')->and($order->buyerAccountId)->toBe('99')
        ->and($order->items)->toHaveCount(2)
        ->and($order->items[0]->externalSkuId)->toBe('i11')->and($order->items[0]->sellerSku)->toBe('AO-1')->and($order->items[0]->discount)->toBe('20000')
        ->and($order->items[1]->externalSkuId)->toBe('501')->and($order->items[1]->variantName)->toBe('Đen, M');
    expect(ezCalls()[0]['token'])->toBe('Bearer share-token-1')->and(ezCalls()[0]['query']['order_sn_list'])->toBe('2411SN1');
    expect(ShopeeConnector::STATUSES['SHIPPED'])->toBe('IN_TRANSIT');
});

it('lists Shopee orders in windows of at most 15 days', function () {
    fakeEzChat(['GET /api/v2/order/get_order_list' => ['data' => ['data' => ['response' => ['order_list' => [['order_sn' => 'A'], ['order_sn' => 'B']], 'more' => false]]]]]);
    $account = gatewayShop($this->tenant, 'shopee');
    $from = CarbonImmutable::parse('2026-10-01');
    $to = CarbonImmutable::parse('2026-11-01');

    $page = app(ConnectorRegistry::class)->for('shopee')->listOrders($account, $from, $to);

    expect($page->orderIds)->toBe(['A', 'B'])->and($page->nextCursor)->toBe(($from->getTimestamp() + 15 * 86400).'|')
        ->and((int) ezCalls()[0]['query']['time_to'] - (int) ezCalls()[0]['query']['time_from'])->toBe(15 * 86400);
});

it('reads Shopee products with their models and pushes stock per model', function () {
    fakeEzChat([
        'GET /api/v2/product/get_item_list' => ['data' => ['data' => ['response' => ['item' => [['item_id' => 11], ['item_id' => 12]], 'has_next_page' => true, 'next_offset' => 50]]]],
        'GET /api/v2/product/get_item_base_info' => ['data' => ['data' => ['response' => ['item_list' => [
            ['item_id' => 11, 'item_name' => 'Áo thun', 'item_sku' => 'AO-1', 'item_status' => 'NORMAL', 'has_model' => false, 'weight' => '0.2',
                'price_info' => [['current_price' => 90000, 'original_price' => 100000]], 'stock_info_v2' => ['seller_stock' => [['stock' => 7]]],
                'image' => ['image_url_list' => ['https://cf.shopee.vn/a.jpg']]],
            ['item_id' => 12, 'item_name' => 'Quần', 'item_status' => 'NORMAL', 'has_model' => true],
        ]]]]],
        'GET /api/v2/product/get_model_list' => ['data' => ['data' => ['response' => [
            'tier_variation' => [['name' => 'Màu', 'option_list' => [['option' => 'Đen'], ['option' => 'Trắng']]]],
            'model' => [
                ['model_id' => 501, 'model_sku' => 'QU-DEN', 'tier_index' => [0], 'price_info' => [['current_price' => 20000, 'original_price' => 20000]], 'stock_info_v2' => ['seller_stock' => [['stock' => 3]]]],
                ['model_id' => 502, 'model_sku' => 'QU-TRANG', 'tier_index' => [1], 'price_info' => [['current_price' => 20000, 'original_price' => 20000]], 'stock_info_v2' => ['seller_stock' => [['stock' => 0]]]],
            ],
        ]]]],
        'POST /api/v2/product/update_stock' => fn (Request $r) => Http::response(['data' => ['data' => ['response' => [
            'success_list' => [['model_id' => 501, 'stock' => 4]],
            'failure_list' => $r['body']['item_id'] === 12 ? [['model_id' => 502, 'failed_reason' => 'Model locked']] : [],
        ]]]]),
    ]);
    $account = gatewayShop($this->tenant, 'shopee');
    $shopee = app(ConnectorRegistry::class)->for('shopee');

    $page = $shopee->listProducts($account);
    expect($page->nextCursor)->toBe('50')->and($page->products)->toHaveCount(2)
        ->and($page->products[0]->variants[0])->toMatchArray(['external_sku_id' => 'i11', 'seller_sku' => 'AO-1', 'price' => '90000', 'stock' => 7, 'sale_price' => '90000', 'weight_gram' => 200])
        ->and($page->products[1]->variants[1])->toMatchArray(['external_sku_id' => '502', 'name' => 'Trắng', 'options' => ['Màu' => 'Trắng']]);

    $results = $shopee->updateInventory($account, [new StockUpdate('12', '501', 4), new StockUpdate('12', '502', 1), new StockUpdate('11', 'i11', -2)]);
    expect(array_map(fn ($r) => [$r->externalSkuId, $r->status], $results))->toBe([
        ['501', StockUpdateResult::OK], ['502', StockUpdateResult::REJECTED], ['i11', StockUpdateResult::OK],
    ]);
    $stock = collect(ezCalls())->where('key', 'POST /api/v2/product/update_stock')->values();
    expect($stock[0]['body'])->toBe(['item_id' => 12, 'stock_list' => [['model_id' => 501, 'seller_stock' => [['stock' => 4]]], ['model_id' => 502, 'seller_stock' => [['stock' => 1]]]]])
        ->and($stock[1]['body']['stock_list'][0])->toBe(['model_id' => 0, 'seller_stock' => [['stock' => 0]]]);
});

it('reads a TikTok Shop order, merging the per-unit lines of one SKU', function () {
    fakeEzChat(['GET /order/202309/orders' => ['data' => ['data' => ['orders' => [[
        'id' => '5760001', 'status' => 'AWAITING_SHIPMENT', 'create_time' => 1731900000, 'update_time' => 1731903600, 'is_cod' => false,
        'payment' => ['currency' => 'VND', 'sub_total' => '180000', 'shipping_fee' => '20000', 'seller_discount' => '10000', 'platform_discount' => '0', 'total_amount' => '190000'],
        'recipient_address' => ['name' => 'B***', 'phone_number' => '(+84)9******12', 'full_address' => 'Hà Nội'],
        'user_id' => '7700', 'tracking_number' => '',
        'line_items' => [
            ['id' => 'L1', 'product_id' => 'P1', 'sku_id' => 'S1', 'seller_sku' => 'AO-1', 'product_name' => 'Áo', 'sku_name' => 'Đỏ', 'sale_price' => '90000', 'original_price' => '100000'],
            ['id' => 'L2', 'product_id' => 'P1', 'sku_id' => 'S1', 'seller_sku' => 'AO-1', 'product_name' => 'Áo', 'sku_name' => 'Đỏ', 'sale_price' => '90000', 'original_price' => '100000'],
        ],
    ]]]]]]);
    $account = gatewayShop($this->tenant, 'tiktok', '7000123');

    $order = app(ConnectorRegistry::class)->for('tiktok')->getOrder($account, '5760001');

    expect($order->rawStatus)->toBe('AWAITING_SHIPMENT')->and($order->total)->toBe('190000')->and($order->discount)->toBe('10000')
        ->and($order->trackingNumber)->toBeNull()->and($order->items)->toHaveCount(1)
        ->and($order->items[0]->quantity)->toBe(2)->and($order->items[0]->externalSkuId)->toBe('S1')->and($order->items[0]->discount)->toBe('20000');
});

it('pushes TikTok Shop stock to the warehouse each SKU uses', function () {
    fakeEzChat([
        'GET /product/202309/products/P1' => ['data' => ['data' => ['id' => 'P1', 'title' => 'Áo', 'status' => 'ACTIVATE', 'skus' => [
            ['id' => 'S1', 'seller_sku' => 'AO-1', 'price' => ['sale_price' => '90000'], 'inventory' => [['warehouse_id' => 'W9', 'quantity' => 5]]],
        ]]]],
        'POST /product/202309/products/P1/inventory/update' => ['data' => ['data' => []]],
    ]);
    $account = gatewayShop($this->tenant, 'tiktok', '7000123');
    $tiktok = app(ConnectorRegistry::class)->for('tiktok');

    $results = $tiktok->updateInventory($account, [new StockUpdate('P1', 'S1', 8), new StockUpdate('P1', 'GONE', 1)]);

    expect(collect($results)->mapWithKeys(fn ($r) => [$r->externalSkuId => $r->status])->all())->toBe(['GONE' => StockUpdateResult::REJECTED, 'S1' => StockUpdateResult::OK])
        ->and(collect(ezCalls())->firstWhere('key', 'POST /product/202309/products/P1/inventory/update')['body'])
        ->toBe(['skus' => [['id' => 'S1', 'inventory' => [['quantity' => 8, 'warehouse_id' => 'W9']]]]])
        ->and($tiktok->getProduct($account, 'P1')->variants[0])->toMatchArray(['external_sku_id' => 'S1', 'price' => '90000', 'stock' => 5]);
});

it('maps EzChat errors: shop no longer shared, marketplace permission', function () {
    $account = gatewayShop($this->tenant, 'shopee');
    $shopee = app(ConnectorRegistry::class)->for('shopee');

    $answers = [
        Http::response(['error' => ['code' => 'BANHANG_NOT_SHARED', 'message' => 'x']], 401),
        Http::response(['error' => ['code' => 'MARKETPLACE_ERROR', 'message' => 'no permission', 'details' => ['auth' => true, 'permission' => true]]], 424),
    ];
    fakeEzChat(['GET /api/v2/product/get_item_list' => function () use (&$answers) {
        return array_shift($answers);
    }]);
    try {
        $shopee->listProducts($account);
        $this->fail('expected an exception');
    } catch (ConnectorException $e) {
        expect($e->authExpired)->toBeTrue()->and($e->temporary)->toBeFalse();
    }

    try {
        $shopee->listProducts($account);
        $this->fail('expected an exception');
    } catch (ConnectorException $e) {
        expect($e->errorCode)->toBe('permission_denied')->and($e->temporary)->toBeFalse();
    }
});

it('accepts pushes EzChat signed and sends them to processing; refuses unsigned ones', function () {
    gatewayShop($this->tenant, 'shopee');
    $body = json_encode(['shop_id' => 880011, 'code' => 3, 'timestamp' => 1731900000, 'data' => ['ordersn' => '2411SN1', 'status' => 'READY_TO_SHIP']]);
    $ts = (string) time();
    $sig = hash_hmac('sha256', "{$ts}./api/v1/webhooks/shopee.{$body}", EZ_SECRET);
    Http::fake(); // the processing job may read the order; it is not what this test checks

    $this->call('POST', '/api/v1/webhooks/shopee', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZCHAT_TIMESTAMP' => $ts, 'HTTP_X_EZCHAT_SIGNATURE' => $sig], $body)
        ->assertOk()->assertJsonPath('duplicate', false);
    $this->call('POST', '/api/v1/webhooks/shopee', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZCHAT_TIMESTAMP' => $ts, 'HTTP_X_EZCHAT_SIGNATURE' => $sig], $body)
        ->assertOk()->assertJsonPath('duplicate', true);
    $this->call('POST', '/api/v1/webhooks/shopee', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZCHAT_TIMESTAMP' => $ts, 'HTTP_X_EZCHAT_SIGNATURE' => str_repeat('0', 64)], $body.' ')
        ->assertStatus(401);

    $event = WebhookEvent::query()->where('marketplace', 'shopee')->where('signature_valid', true)->sole();
    expect($event->external_order_id)->toBe('2411SN1')->and($event->external_shop_id)->toBe('880011');

    $tiktok = app(ConnectorRegistry::class)->for('tiktok')->parseWebhook(json_encode(['type' => 5, 'tts_notification_id' => 'n1', 'shop_id' => '7000123', 'data' => ['product_id' => 'P1']]));
    expect($tiktok->productId)->toBe('P1')->and($tiktok->orderId)->toBeNull()->and($tiktok->eventId)->toBe('n1');
});

it('tells EzChat to forget the share when the shop is disconnected', function () {
    fakeEzChat(['/api/banhang/gw/revoke' => ['data' => ['ok' => true]]]);
    $account = gatewayShop($this->tenant, 'shopee');

    TenantContext::run($this->tenant, fn () => app(AccountService::class)->disconnect($account));

    expect(ezCalls()[0]['key'])->toBe('/api/banhang/gw/revoke')->and(ezCalls()[0]['token'])->toBe('Bearer share-token-1')
        ->and($account->refresh()->status)->toBe(MarketplaceAccount::REVOKED);
});

/** A Shopee order waiting to be packed; once ship_order was called Shopee reports it PROCESSED with a tracking number. */
function shopeeShipFake(array $extra = []): void
{
    $shipped = false;
    $order = fn (string $status) => ['data' => ['data' => ['response' => ['order_list' => [[
        'order_sn' => '2611SHIP1', 'order_status' => $status, 'create_time' => 1763500000, 'update_time' => 1763500000 + ($status === 'PROCESSED' ? 60 : 0),
        'ship_by_date' => 1763600000, 'currency' => 'VND', 'total_amount' => 120000, 'shipping_carrier' => 'SPX Express',
        'recipient_address' => ['name' => 'L***n', 'full_address' => 'TP. HCM'],
        'item_list' => [['item_id' => 11, 'item_name' => 'Áo thun', 'model_id' => 0, 'model_quantity_purchased' => 1, 'model_original_price' => 120000, 'model_discounted_price' => 120000]],
    ]]]]]];
    fakeEzChat([
        'GET /api/v2/order/get_order_detail' => function () use (&$shipped, $order) {
            return Http::response($order($shipped ? 'PROCESSED' : 'READY_TO_SHIP'));
        },
        'GET /api/v2/logistics/get_shipping_parameter' => ['data' => ['data' => ['response' => [
            'info_needed' => ['pickup' => ['address_id', 'pickup_time_id'], 'dropoff' => []],
            'pickup' => ['address_list' => [
                ['address_id' => 7, 'address' => '99 Nguyễn Trãi', 'city' => 'TP. Hồ Chí Minh', 'address_flag' => ['return_address'], 'time_slot_list' => []],
                ['address_id' => 8, 'address' => '12 Lê Lợi', 'district' => 'Quận 1', 'city' => 'TP. Hồ Chí Minh', 'address_flag' => ['pickup_address'],
                    'time_slot_list' => [['date' => 1763604000, 'time_text' => '08:00-12:00', 'pickup_time_id' => 'slot-8']]],
            ]],
        ]]]],
        'POST /api/v2/logistics/ship_order' => function () use (&$shipped) {
            $shipped = true;

            return Http::response(['data' => ['data' => ['response' => []]]]);
        },
        'GET /api/v2/logistics/get_tracking_number' => ['data' => ['data' => ['response' => ['tracking_number' => 'SPXVN0099']]]],
        'POST /api/v2/logistics/get_shipping_document_parameter' => ['data' => ['data' => ['response' => ['result_list' => [['order_sn' => '2611SHIP1', 'suggest_shipping_document_type' => 'THERMAL_AIR_WAYBILL']]]]]],
        'POST /api/v2/logistics/create_shipping_document' => ['data' => ['data' => ['response' => ['result_list' => [['order_sn' => '2611SHIP1']]]]]],
        'POST /api/v2/logistics/get_shipping_document_result' => ['data' => ['data' => ['response' => ['result_list' => [['order_sn' => '2611SHIP1', 'status' => 'READY']]]]]],
        'POST /api/v2/logistics/download_shipping_document' => ['data' => ['data' => ['file' => base64_encode('%PDF-1.4 shopee label'), 'contentType' => 'application/pdf']]],
        ...$extra,
    ]);
}

it('prepares Shopee orders from the list: pickup slot of the shop address, then prints Shopee\'s label', function () {
    shopeeShipFake();
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    expect($order->status)->toBe(OrderStatus::READY_TO_PACK);
    $api = actingInTenant($this->owner, $this->tenant);

    $api->getJson('/api/v1/orders?flags[]=not_arranged')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.marketplace_shipping', true);
    $group = $api->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()->json('data.groups.0');
    expect($group['account']['id'])->toBe($account->ulid)
        ->and($group['options']['pickup']['available'])->toBeTrue()->and($group['options']['dropoff']['available'])->toBeTrue()
        ->and($group['options']['pickup']['addresses'][0])->toMatchArray(['id' => '8', 'label' => '12 Lê Lợi, Quận 1, TP. Hồ Chí Minh'])
        ->and($group['options']['pickup']['addresses'][0]['slots'][0]['id'])->toBe('slot-8')
        ->and($group['options']['pickup']['addresses'][0]['slots'][0]['label'])->toContain('08:00-12:00');

    // Without a choice nothing is sent to Shopee.
    $api->postJson('/api/v1/orders/bulk', ['ids' => [$order->ulid], 'action' => 'pack'])->assertOk()
        ->assertJsonPath('data.0.ok', false)->assertJsonPath('data.0.message', fn ($m) => str_contains($m, 'Chọn cách giao'));
    expect(collect(ezCalls())->where('key', 'POST /api/v2/logistics/ship_order'))->toHaveCount(0);

    $choice = ['method' => 'pickup', 'address_id' => '8', 'slot_id' => 'slot-8', 'slot_label' => 'Thứ 5 20/11 · 08:00-12:00', 'address_label' => '12 Lê Lợi'];
    $api->postJson('/api/v1/orders/bulk', ['ids' => [$order->ulid], 'action' => 'pack', 'shipping' => [$account->ulid => $choice]])->assertOk()
        ->assertJsonPath('data.0.ok', true)->assertJsonPath('data.0.tracking_number', 'SPXVN0099');
    $ship = collect(ezCalls())->firstWhere('key', 'POST /api/v2/logistics/ship_order');
    expect($ship['body'])->toBe(['order_sn' => '2611SHIP1', 'pickup' => ['address_id' => 8, 'pickup_time_id' => 'slot-8']]);
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PACKED)->and($order->ship_arrangement)->toMatchArray(['method' => 'pickup', 'slot' => 'Thứ 5 20/11 · 08:00-12:00', 'address' => '12 Lê Lợi'])
        ->and($account->refresh()->settings['ship_choice'])->toBe(['method' => 'pickup', 'address_id' => '8']);

    $api->getJson('/api/v1/orders?flags[]=label_unprinted')->assertOk()->assertJsonCount(1, 'data');
    $labels = $api->postJson('/api/v1/orders/shipping-labels', ['ids' => [$order->ulid]])->assertOk()->json('data');
    expect($labels['failed'])->toBe([])->and(base64_decode($labels['files'][0]['pdf']))->toBe('%PDF-1.4 shopee label')
        ->and($order->refresh()->label_print_count)->toBe(1);
    $download = collect(ezCalls())->firstWhere('key', 'POST /api/v2/logistics/download_shipping_document');
    expect($download['body'])->toBe(['shipping_document_type' => 'THERMAL_AIR_WAYBILL', 'order_list' => [['order_sn' => '2611SHIP1']]]);
    $api->getJson('/api/v1/orders?flags[]=label_unprinted')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps Shopee\'s kiểu giao, learns its Pickup / Drop off for the list and prepares it in its own group', function () {
    shopeeShipFake(['GET /api/v2/order/get_order_detail' => ['data' => ['data' => ['response' => ['order_list' => [[
        'order_sn' => '2611SHIP1', 'order_status' => 'READY_TO_SHIP', 'create_time' => 1763500000, 'update_time' => 1763500000,
        'ship_by_date' => 1763600000, 'currency' => 'VND', 'total_amount' => 120000,
        'shipping_carrier' => 'SPX Instant', 'checkout_shipping_carrier' => 'Hỏa Tốc',
        'recipient_address' => ['name' => 'L***n', 'full_address' => 'TP. HCM'],
        'item_list' => [['item_id' => 11, 'item_name' => 'Áo thun', 'model_id' => 0, 'model_quantity_purchased' => 1, 'model_original_price' => 120000, 'model_discounted_price' => 120000]],
    ]]]]]]]);
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    expect($order->delivery_option)->toBe('Hỏa Tốc')->and($order->delivery_type)->toBe('express')->and($order->shipping_carrier)->toBe('SPX Instant');
    $api = actingInTenant($this->owner, $this->tenant);

    // Not known yet: the list asks Shopee once in the background, the next load shows it.
    $api->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.ship_methods', null);
    $api->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.ship_methods', ['pickup', 'dropoff']);
    $api->getJson('/api/v1/orders')->assertOk();
    expect(collect(ezCalls())->where('key', 'GET /api/v2/logistics/get_shipping_parameter'))->toHaveCount(1)
        ->and($account->refresh()->settings['ship_methods']['express']['methods'])->toBe(['pickup', 'dropoff']);

    $group = $api->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()->json('data.groups.0');
    expect($group['key'])->toBe($account->ulid.'|express')->and($group['delivery'])->toBe(['type' => 'express', 'option' => 'Hỏa Tốc']);
    $api->postJson('/api/v1/orders/bulk', ['ids' => [$order->ulid], 'action' => 'pack', 'shipping' => [$group['key'] => ['method' => 'dropoff']]])->assertOk()
        ->assertJsonPath('data.0.ok', true);
    // The choice keyed by shop and kiểu giao reached Shopee.
    expect(array_keys((array) collect(ezCalls())->firstWhere('key', 'POST /api/v2/logistics/ship_order')['body']))->toBe(['order_sn', 'dropoff']);
});

it('packing a Shopee order asks how it goes, or uses the shop\'s last choice when Cấu hình says so', function () {
    shopeeShipFake();
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    $line = (string) OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->value('id');
    $api = actingInTenant($this->owner, $this->tenant);

    $api->postJson("/api/v1/orders/{$order->ulid}/packing/finish", ['scanned' => [$line => 1]])->assertStatus(422)->assertJsonValidationErrors('shipping');

    $account->forceFill(['settings' => [...($account->settings ?? []), 'ship_choice' => ['method' => 'dropoff', 'address_id' => null]]])->save();
    $api->putJson('/api/v1/tenant/settings/sales', ['marketplace_auto_arrange' => true])->assertOk();
    $api->postJson("/api/v1/orders/{$order->ulid}/packing/finish", ['scanned' => [$line => 1]])->assertOk()->assertJsonPath('data.status', OrderStatus::PACKED);
    expect(collect(ezCalls())->firstWhere('key', 'POST /api/v2/logistics/ship_order')['body'])->toBe(['order_sn' => '2611SHIP1', 'dropoff' => []])
        ->and($order->refresh()->ship_arrangement['method'])->toBe('dropoff');
});

it('prints the label of an order just prepared even before ECSO read its new status', function () {
    shopeeShipFake();
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    $api = actingInTenant($this->owner, $this->tenant);
    $choice = ['method' => 'pickup', 'address_id' => '8', 'slot_id' => 'slot-8', 'slot_label' => 'Thứ 5', 'address_label' => '12 Lê Lợi'];
    $api->postJson('/api/v1/orders/bulk', ['ids' => [$order->ulid], 'action' => 'pack', 'shipping' => [$account->ulid => $choice]])->assertOk();
    // As when Shopee had not given the tracking number yet at the time of "Chuẩn bị hàng".
    $order->refresh()->forceFill(['status' => OrderStatus::READY_TO_PACK, 'external_updated_at' => null, 'raw_status' => 'READY_TO_SHIP'])->save();

    $labels = $api->postJson('/api/v1/orders/shipping-labels', ['ids' => [$order->ulid]])->assertOk()->json('data');
    expect($labels['failed'])->toBe([])->and($labels['files'])->toHaveCount(1)->and($order->refresh()->status)->toBe(OrderStatus::PACKED);
});

it('reads a Shopee order again when its label is refused: handed to the carrier, the status catches up', function () {
    $state = 'READY_TO_SHIP';
    $detail = function () use (&$state) {
        return Http::response(['data' => ['data' => ['response' => ['order_list' => [[
            'order_sn' => '2611SHIP1', 'order_status' => $state, 'create_time' => 1763500000, 'update_time' => 1763500000 + ['READY_TO_SHIP' => 0, 'PROCESSED' => 60, 'SHIPPED' => 120][$state],
            'ship_by_date' => 1763600000, 'currency' => 'VND', 'total_amount' => 120000, 'shipping_carrier' => 'SPX Express',
            'recipient_address' => ['name' => 'L***n', 'full_address' => 'TP. HCM'],
            'item_list' => [['item_id' => 11, 'item_name' => 'Áo thun', 'model_id' => 0, 'model_quantity_purchased' => 1, 'model_original_price' => 120000, 'model_discounted_price' => 120000]],
        ]]]]]]);
    };
    shopeeShipFake([
        'GET /api/v2/order/get_order_detail' => function () use (&$detail) {
            return $detail();
        },
        'POST /api/v2/logistics/get_shipping_document_parameter' => fn () => Http::response(['error' => ['code' => 'MARKETPLACE_ERROR', 'message' => '/api/v2/logistics/get_shipping_document_parameter: All failed, please check result_list for detail', 'details' => ['auth' => false]]], 424),
    ]);
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    $state = 'PROCESSED';
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    expect($order->refresh()->status)->toBe(OrderStatus::PACKED);

    $state = 'SHIPPED';
    $failed = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/orders/shipping-labels', ['ids' => [$order->ulid]])->assertOk()->json('data.failed');
    expect($failed[0]['message'])->toContain('đã giao cho đơn vị vận chuyển')
        ->and($order->refresh()->status)->not->toBe(OrderStatus::PACKED);
});

it('offers Shopee pickup without a time when Shopee asks for none, and ships without pickup_time_id', function () {
    shopeeShipFake(['GET /api/v2/logistics/get_shipping_parameter' => ['data' => ['data' => ['response' => [
        'info_needed' => ['pickup' => ['address_id']],
        'pickup' => ['address_list' => [
            ['address_id' => 8, 'address' => '12 Lê Lợi', 'city' => 'TP. Hồ Chí Minh', 'address_flag' => ['pickup_address'], 'time_slot_list' => []],
            ['address_id' => 9, 'address' => '5 Hai Bà Trưng', 'city' => 'TP. Hồ Chí Minh', 'address_flag' => [], 'time_slot_list' => []],
        ]],
    ]]]]]);
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    $api = actingInTenant($this->owner, $this->tenant);

    $pickup = $api->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()->json('data.groups.0.options.pickup');
    expect($pickup['available'])->toBeTrue()->and($pickup['addresses'])->toHaveCount(2)
        ->and($pickup['addresses'][0]['slots'])->toBe([['id' => 'shopee', 'label' => 'Shopee tự hẹn giờ lấy hàng']])
        ->and($pickup['addresses'][1]['slots'][0]['id'])->toBe('shopee');

    $choice = ['method' => 'pickup', 'address_id' => '8', 'slot_id' => 'shopee', 'slot_label' => 'Shopee tự hẹn giờ lấy hàng', 'address_label' => '12 Lê Lợi'];
    $api->postJson('/api/v1/orders/bulk', ['ids' => [$order->ulid], 'action' => 'pack', 'shipping' => [$account->ulid => $choice]])->assertOk()
        ->assertJsonPath('data.0.ok', true);
    expect(collect(ezCalls())->firstWhere('key', 'POST /api/v2/logistics/ship_order')['body'])->toBe(['order_sn' => '2611SHIP1', 'pickup' => ['address_id' => 8]]);
});

it('sends the text the shop chose for its labels with each label, off until turned on and only for the chosen shops', function () {
    shopeeShipFake();
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    $api = actingInTenant($this->owner, $this->tenant);
    $choice = ['method' => 'pickup', 'address_id' => '8', 'slot_id' => 'slot-8', 'slot_label' => 'Thứ 5', 'address_label' => '12 Lê Lợi'];
    $api->postJson('/api/v1/orders/bulk', ['ids' => [$order->ulid], 'action' => 'pack', 'shipping' => [$account->ulid => $choice]])->assertOk()->assertJsonPath('data.0.ok', true);
    $order->refresh()->forceFill(['buyer_note' => 'Gói kỹ giúp em'])->save();
    $label = fn () => $api->postJson('/api/v1/orders/shipping-labels', ['ids' => [$order->ulid]])->assertOk()->json('data.files.0');

    // Off by default: the label is printed exactly as Shopee made it.
    expect($label()['extras'])->toBeNull();

    $settings = ['picklist' => true, 'note' => true, 'warehouse' => false, 'notice' => true, 'notice_text' => "QUÝ KHÁCH VUI LÒNG QUAY VIDEO KHI KHUI HÀNG\nCảm ơn {ten_khach}, đơn {ma_don}", 'notice_box' => true, 'size' => 'lg', 'accounts' => null];
    $api->putJson('/api/v1/tenant/settings/print', ['label_extras' => $settings])->assertOk()->assertJsonPath('data.print.label_extras.size', 'lg');
    $extras = $label()['extras'];
    expect($extras['notice']['lines'][0])->toBe('QUÝ KHÁCH VUI LÒNG QUAY VIDEO KHI KHUI HÀNG')
        ->and($extras['notice']['lines'][1])->toContain('đơn 2611SHIP1')->and($extras['notice']['box'])->toBeTrue()
        ->and($extras['picklist'][0])->toMatchArray(['name' => 'Áo thun', 'quantity' => 1])
        ->and($extras['note'])->toBe('Khách nhắn: Gói kỹ giúp em')->and($extras['warehouse'])->toBeNull()->and($extras['size'])->toBe('lg');

    // Only the chosen shops.
    $api->putJson('/api/v1/tenant/settings/print', ['label_extras' => [...$settings, 'accounts' => ['01OTHERSHOP']]])->assertOk();
    expect($label()['extras'])->toBeNull();
    $api->putJson('/api/v1/tenant/settings/print', ['label_extras' => [...$settings, 'size' => 'huge']])->assertStatus(422);
});

it('prepares a TikTok Shop package for drop-off and prints its label from TikTok\'s file link', function () {
    $shipped = false;
    fakeEzChat([
        'GET /order/202309/orders' => function () use (&$shipped) {
            return Http::response(['data' => ['data' => ['orders' => [[
                'id' => '5790001', 'status' => $shipped ? 'AWAITING_COLLECTION' : 'AWAITING_SHIPMENT', 'create_time' => 1763500000, 'update_time' => 1763500000 + ($shipped ? 60 : 0),
                'payment' => ['currency' => 'VND', 'sub_total' => '99000', 'total_amount' => '99000'], 'packages' => [['id' => 'PKG77']],
                'line_items' => [['id' => 'L1', 'sku_id' => 'S1', 'product_name' => 'Mũ', 'sale_price' => '99000', 'original_price' => '99000']],
            ]]]]]);
        },
        'GET /fulfillment/202309/packages/PKG77/handover_time_slots' => ['data' => ['data' => ['can_pickup' => true, 'can_drop_off' => true, 'pickup_slots' => [
            ['start_time' => 1763600000, 'end_time' => 1763614400, 'avaliable' => true], ['start_time' => 1763700000, 'end_time' => 1763714400, 'avaliable' => false],
        ]]]],
        'POST /fulfillment/202309/packages/PKG77/ship' => function () use (&$shipped) {
            $shipped = true;

            return Http::response(['data' => ['data' => []]]);
        },
        'GET /fulfillment/202309/packages/PKG77/shipping_documents' => ['data' => ['data' => ['doc_url' => 'https://open-fs.tiktokshop.test/label.pdf']]],
        'https://open-fs.tiktokshop.test/label.pdf' => '%PDF-1.4 tiktok label',
    ]);
    $account = gatewayShop($this->tenant, 'tiktok', '7000');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '5790001', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '5790001')->sole();
    $api = actingInTenant($this->owner, $this->tenant);

    $options = $api->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()->json('data.groups.0.options');
    expect($options['pickup']['addresses'][0]['slots'])->toHaveCount(1)->and($options['pickup']['addresses'][0]['slots'][0]['id'])->toBe('1763600000-1763614400');

    $api->postJson("/api/v1/orders/{$order->ulid}/actions/pack", ['shipping' => [$account->ulid => ['method' => 'dropoff']]])->assertOk()->assertJsonPath('data.status', OrderStatus::PACKED);
    expect(collect(ezCalls())->firstWhere('key', 'POST /fulfillment/202309/packages/PKG77/ship')['body'])->toBe(['handover_method' => 'DROP_OFF']);

    $labels = $api->postJson('/api/v1/orders/shipping-labels', ['ids' => [$order->ulid]])->assertOk()->json('data');
    expect(base64_decode($labels['files'][0]['pdf']))->toBe('%PDF-1.4 tiktok label');
});

it('sets aside a Shopee order already prepared in Shopee\'s seller page instead of showing Shopee\'s refusal', function () {
    $arranged = false;
    shopeeShipFake([
        'GET /api/v2/order/get_order_detail' => function () use (&$arranged) {
            return Http::response(['data' => ['data' => ['response' => ['order_list' => [[
                'order_sn' => '2611SHIP1', 'order_status' => $arranged ? 'PROCESSED' : 'READY_TO_SHIP', 'create_time' => 1763500000, 'update_time' => 1763500000 + ($arranged ? 60 : 0),
                'ship_by_date' => 1763600000, 'currency' => 'VND', 'total_amount' => 120000, 'shipping_carrier' => 'SPX Express',
                'item_list' => [['item_id' => 11, 'item_name' => 'Áo thun', 'model_id' => 0, 'model_quantity_purchased' => 1, 'model_original_price' => 120000, 'model_discounted_price' => 120000]],
            ]]]]]]);
        },
        'GET /api/v2/logistics/get_shipping_parameter' => fn () => Http::response(['error' => ['code' => 'MARKETPLACE_ERROR', 'message' => '/api/v2/logistics/get_shipping_parameter: Package OFG1 not eligible for rescheduling', 'details' => ['auth' => false]]], 424),
    ]);
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    $api = actingInTenant($this->owner, $this->tenant);

    // Still waiting on Shopee too: Shopee's own words, without the API path.
    $api->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()
        ->assertJsonPath('data.groups.0.error', 'Package OFG1 not eligible for rescheduling');

    // Prepared on Shopee meanwhile: no error, the order moves on in ECSO.
    $arranged = true;
    $data = $api->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()->json('data');
    expect($data['groups'])->toBe([])->and($data['skipped'][0]['message'])->toContain('đã được chuẩn bị trên sàn')
        ->and($order->refresh()->status)->toBe(OrderStatus::PACKED);
});

it('holds back a Shopee order Shopee is still checking: own label, not in Cần chuẩn bị hàng, no Chuẩn bị hàng until released', function () {
    // Asked without request_order_status_pending, Shopee would still say READY_TO_SHIP.
    $status = 'READY_TO_SHIP';
    shopeeShipFake([
        'GET /api/v2/order/get_order_detail' => function (Request $r) use (&$status) {
            $asked = ($r['query']['request_order_status_pending'] ?? null) === 'true';

            return Http::response(['data' => ['data' => ['response' => ['order_list' => [[
                'order_sn' => '2611SHIP1', 'order_status' => $asked ? $status : 'READY_TO_SHIP', 'create_time' => 1763500000,
                'update_time' => 1763500000 + ($status === 'READY_TO_SHIP' ? 120 : 0), 'ship_by_date' => 1763600000, 'currency' => 'VND', 'total_amount' => 9000,
                'shipping_carrier' => 'SPX Express', 'checkout_shipping_carrier' => 'Nhanh', 'pending_terms' => $status === 'PENDING' ? ['SYSTEM_PENDING'] : [],
                'item_list' => [['item_id' => 11, 'item_name' => 'Cáp Micro USB', 'model_id' => 0, 'model_quantity_purchased' => 1, 'model_original_price' => 9000, 'model_discounted_price' => 9000]],
            ]]]]]]);
        },
        'GET /api/v2/logistics/get_shipping_parameter' => fn () => Http::response(['error' => ['code' => 'MARKETPLACE_ERROR', 'message' => '/api/v2/logistics/get_shipping_parameter: Shipping parameters can only be obtained when package is ready to be shipped', 'details' => ['auth' => false]]], 424),
    ]);
    $account = gatewayShop($this->tenant, 'shopee');
    $status = 'PENDING';
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();
    expect($order->status)->toBe(OrderStatus::READY_TO_PACK)->and($order->raw_status)->toBe('PENDING')->and($order->underMarketplaceReview())->toBeTrue()
        ->and($order->raw['pending_terms'])->toBe(['SYSTEM_PENDING'])
        ->and(collect(ezCalls())->where('key', 'GET /api/v2/logistics/get_tracking_number'))->toHaveCount(0);
    $api = actingInTenant($this->owner, $this->tenant);

    $api->getJson('/api/v1/orders?tab=to_pack')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.marketplace_review', true)->assertJsonPath('data.0.ship_methods', null);
    $api->getJson('/api/v1/orders?flags[]=not_arranged')->assertOk()->assertJsonCount(0, 'data');
    $api->getJson("/api/v1/orders/{$order->ulid}")->assertOk()->assertJsonPath('data.actions.pack.allowed', false);
    $data = $api->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()->json('data');
    expect($data['groups'])->toBe([])->and($data['skipped'][0]['message'])->toContain('Shopee đang kiểm tra')
        ->and(collect(ezCalls())->where('key', 'GET /api/v2/logistics/get_shipping_parameter'))->toHaveCount(0);
    $choice = ['method' => 'pickup', 'address_id' => '8', 'slot_id' => 'slot-8'];
    $api->postJson('/api/v1/orders/bulk', ['ids' => [$order->ulid], 'action' => 'pack', 'shipping' => [$account->ulid => $choice]])->assertOk()
        ->assertJsonPath('data.0.ok', false)->assertJsonPath('data.0.message', fn ($m) => str_contains($m, 'Shopee đang kiểm tra'));
    expect(collect(ezCalls())->where('key', 'POST /api/v2/logistics/ship_order'))->toHaveCount(0);

    // Shopee is done: the next read moves it into Cần chuẩn bị hàng.
    $status = 'READY_TO_SHIP';
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'schedule'));
    expect($order->refresh()->underMarketplaceReview())->toBeFalse()->and($order->status)->toBe(OrderStatus::READY_TO_PACK);
    $api->getJson('/api/v1/orders?flags[]=not_arranged')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.marketplace_review', false);
});

it('says Shopee\'s "not ready to be shipped" refusal in Vietnamese', function () {
    shopeeShipFake([
        'GET /api/v2/logistics/get_shipping_parameter' => fn () => Http::response(['error' => ['code' => 'MARKETPLACE_ERROR', 'message' => '/api/v2/logistics/get_shipping_parameter: Shipping parameters can only be obtained when package is ready to be shipped', 'details' => ['auth' => false]]], 424),
    ]);
    $account = gatewayShop($this->tenant, 'shopee');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '2611SHIP1', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '2611SHIP1')->sole();

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()
        ->assertJsonPath('data.groups.0.error', fn ($m) => str_starts_with($m, 'Shopee chưa cho chuẩn bị hàng đơn này'));
});

it('ships a TikTok Hoả tốc package as a pickup without a slot when TikTok offers none', function () {
    fakeEzChat([
        'GET /order/202309/orders' => ['data' => ['data' => ['orders' => [[
            'id' => '5790002', 'status' => 'AWAITING_SHIPMENT', 'create_time' => 1763500000, 'update_time' => 1763500000,
            'payment' => ['currency' => 'VND', 'sub_total' => '99000', 'total_amount' => '99000'], 'packages' => [['id' => 'PKG78']],
            'line_items' => [['id' => 'L1', 'sku_id' => 'S1', 'product_name' => 'Mũ', 'sale_price' => '99000', 'original_price' => '99000']],
        ]]]]],
        'GET /fulfillment/202309/packages/PKG78/handover_time_slots' => fn () => Http::response(['error' => ['code' => 'MARKETPLACE_ERROR', 'message' => '/fulfillment/202309/packages/PKG78/handover_time_slots: Request validation failed', 'details' => ['auth' => false]]], 424),
    ]);
    $account = gatewayShop($this->tenant, 'tiktok', '7001');
    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncOne($account, '5790002', 'webhook'));
    $order = Order::withoutGlobalScopes()->where('external_order_id', '5790002')->sole();
    $api = actingInTenant($this->owner, $this->tenant);

    $group = $api->postJson('/api/v1/orders/ship-options', ['ids' => [$order->ulid]])->assertOk()->json('data.groups.0');
    expect($group['error'])->toBeNull()->and($group['options']['pickup']['available'])->toBeTrue()
        ->and($group['options']['pickup']['addresses'][0]['slots'][0]['id'])->toBe('tiktok');

    $api->postJson("/api/v1/orders/{$order->ulid}/actions/pack", ['shipping' => [$account->ulid => ['method' => 'pickup', 'slot_id' => 'tiktok', 'slot_label' => 'TikTok tự hẹn giờ lấy hàng']]])->assertOk();
    expect(collect(ezCalls())->firstWhere('key', 'POST /fulfillment/202309/packages/PKG78/ship')['body'])->toBe(['handover_method' => 'PICKUP']);
});

it('takes Shopee pictures: links the same SKU by itself, makes new products with their pictures, fills products without one', function () {
    Storage::fake('public');
    RemoteImage::$resolver = fn (string $host) => ['93.184.216.34'];
    $png = fn () => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/png']);
    $img = fn (string $n) => "https://cf.shopee.vn/file/{$n}";
    fakeEzChat([
        'GET /api/v2/product/get_item_list' => ['data' => ['data' => ['response' => ['item' => [['item_id' => 21], ['item_id' => 22]], 'has_next_page' => false]]]],
        'GET /api/v2/product/get_item_base_info' => ['data' => ['data' => ['response' => ['item_list' => [
            ['item_id' => 21, 'item_name' => 'Áo polo', 'item_status' => 'NORMAL', 'has_model' => true, 'image' => ['image_url_list' => [$img('a'), $img('b')]]],
            ['item_id' => 22, 'item_name' => 'Áo thun', 'item_sku' => 'AO-1', 'item_status' => 'NORMAL', 'has_model' => false,
                'price_info' => [['current_price' => 90000]], 'image' => ['image_url_list' => [$img('d')]]],
        ]]]]],
        'GET /api/v2/product/get_model_list' => ['data' => ['data' => ['response' => [
            'tier_variation' => [['name' => 'Màu', 'option_list' => [['option' => 'Đen', 'image' => ['image_url' => $img('c')]], ['option' => 'Trắng']]]],
            'model' => [
                ['model_id' => 601, 'model_sku' => 'POLO-DEN', 'tier_index' => [0], 'price_info' => [['current_price' => 129000]]],
                ['model_id' => 602, 'model_sku' => 'POLO-TRANG', 'tier_index' => [1], 'price_info' => [['current_price' => 129000]]],
            ],
        ]]]],
        $img('a') => $png, $img('b') => $png, $img('c') => $png, $img('d') => $png,
    ]);
    $shirt = makeVariant($this->tenant, 'AO-1', ['product_name' => 'Áo thun']);
    $account = gatewayShop($this->tenant, 'shopee');
    $api = actingInTenant($this->owner, $this->tenant);

    // The same SKU links as the products come in; the polo is not in the warehouse yet.
    $api->postJson("/api/v1/marketplace-accounts/{$account->ulid}/sync-products")->assertOk()->assertJsonPath('data.linked', 1);
    $list = $api->getJson("/api/v1/marketplace-accounts/{$account->ulid}/listings?status=todo")->assertOk();
    expect($list->json('summary'))->toMatchArray(['variants' => 3, 'products' => 2, 'mapped' => 1, 'linked_by_sku' => 1, 'unmapped' => 2, 'creatable_products' => 1, 'creatable_variants' => 2])
        ->and($list->json('data'))->toHaveCount(2)
        ->and($list->json('data.0'))->toMatchArray(['creatable' => true, 'image_url' => $img('c')]);

    // "Tạo sản phẩm từ sàn": previewed with its pictures, then made with them.
    $preview = $api->getJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products")->assertOk()->json('data.0');
    expect($preview['images'])->toBe(2)->and($preview['variants'][0])->toMatchArray(['sku' => 'POLO-DEN', 'image_url' => $img('c')]);
    $api->postJson("/api/v1/marketplace-accounts/{$account->ulid}/new-products/approve", ['listing_ids' => [$preview['id']], 'images' => true])
        ->assertOk()->assertJsonPath('data.created', 1);
    $polo = TenantContext::run($this->tenant, fn () => Product::query()->where('name', 'Áo polo')->sole());
    $den = TenantContext::run($this->tenant, fn () => ProductVariant::query()->where('sku', 'POLO-DEN')->sole());
    expect(ProductImage::withoutGlobalScopes()->where('product_id', $polo->id)->whereNull('product_variant_id')->count())->toBe(2)
        ->and(ProductImage::withoutGlobalScopes()->where('product_variant_id', $den->id)->count())->toBe(1);

    // "Lấy ảnh từ sàn" for the product that had none.
    $api->getJson('/api/v1/marketplace-images/missing')->assertOk()->assertJsonPath('data.count', 1);
    $api->getJson('/api/v1/products?image=none')->assertOk()->assertJsonPath('data.0.name', 'Áo thun')->assertJsonPath('data.0.sold_on.0.marketplace', 'shopee');
    $api->postJson('/api/v1/marketplace-images/queue')->assertOk()->assertJsonPath('data.queued', 1);
    expect(ProductImage::withoutGlobalScopes()->where('product_id', $shirt->product_id)->count())->toBe(1);
    $api->getJson('/api/v1/marketplace-images/missing')->assertJsonPath('data.count', 0);

    // The product page's picker: what the shop has, and only those links can be copied.
    $sources = $api->getJson("/api/v1/products/{$polo->ulid}/marketplace-images")->assertOk()->json('data.0');
    expect($sources['images'])->toBe([$img('a'), $img('b')])->and($sources['variants'][0])->toMatchArray(['url' => $img('c'), 'label' => 'Đen', 'variant_ids' => [$den->ulid]]);
    $api->postJson("/api/v1/products/{$polo->ulid}/marketplace-images", ['urls' => [$img('b'), 'https://evil.test/x.png']])
        ->assertOk()->assertJsonPath('data.added', 1)->assertJsonCount(3, 'data.images');
    RemoteImage::$resolver = null;
});

it('pushes prices to Shopee and TikTok Shop from the price board queue', function () {
    fakeEzChat([
        'POST /api/v2/product/update_price' => fn (Request $r) => Http::response(['data' => ['data' => ['response' => [
            'failure_list' => $r['body']['item_id'] === 12 ? [['model_id' => 502, 'failed_reason' => 'Item is in promotion']] : [],
        ]]]]),
        'POST /product/202309/products/P1/prices/update' => ['data' => ['data' => []]],
    ]);
    $shopeeShop = gatewayShop($this->tenant, 'shopee');
    $tiktokShop = gatewayShop($this->tenant, 'tiktok', '7000123');
    $shopee = app(ConnectorRegistry::class)->for('shopee');
    $tiktok = app(ConnectorRegistry::class)->for('tiktok');
    expect($shopee)->toBeInstanceOf(UpdatesPrices::class);

    $results = $shopee->updatePrices($shopeeShop, [
        new PriceUpdate('12', '501', '120000'),
        new PriceUpdate('12', '502', '125000'),
        new PriceUpdate('11', 'i11', '99000.00'),
    ]);
    expect(array_map(fn ($r) => [$r->externalSkuId, $r->status], $results))->toBe([
        ['501', StockUpdateResult::OK], ['502', StockUpdateResult::REJECTED], ['i11', StockUpdateResult::OK],
    ])->and($results[1]->message)->toBe('Shopee: Item is in promotion');
    $calls = collect(ezCalls())->where('key', 'POST /api/v2/product/update_price')->values();
    expect($calls[0]['body'])->toBe(['item_id' => 12, 'price_list' => [['model_id' => 501, 'original_price' => 120000], ['model_id' => 502, 'original_price' => 125000]]])
        ->and($calls[1]['body']['price_list'][0])->toBe(['model_id' => 0, 'original_price' => 99000]);

    // The job sends a queued TikTok price and shows it as the shop's price at once (no sync_prices switch needed).
    TenantContext::run($this->tenant, function () use ($tiktokShop) {
        $listing = MarketplaceListing::query()->create(['marketplace_account_id' => $tiktokShop->id, 'external_product_id' => 'P1', 'title' => 'Áo']);
        $v = ListingVariant::query()->create(['marketplace_account_id' => $tiktokShop->id, 'listing_id' => $listing->id, 'external_sku_id' => 'S1', 'price' => 90000, 'regular_price' => 90000]);
        $v->forceFill(['target_price' => 110000, 'price_push_status' => 'pending'])->save();
    });
    (new PushPricesJob($tiktokShop->id))->handle(app(ConnectorRegistry::class));

    $row = ListingVariant::withoutGlobalScopes()->where('external_sku_id', 'S1')->sole();
    expect($row->price_push_status)->toBe('ok')->and($row->price)->toBe('110000.00')
        ->and(collect(ezCalls())->firstWhere('key', 'POST /product/202309/products/P1/prices/update')['body'])
        ->toBe(['skus' => [['id' => 'S1', 'price' => ['amount' => '110000', 'currency' => 'VND']]]]);
});
