<?php

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\EzChatLink;

const EZL_URL = 'https://app.ezchat.test';
const EZL_SECRET = 'ezchat-shared-secret-0123456789abcdef';

beforeEach(function () {
    config(['marketplaces.ezchat.url' => EZL_URL, 'marketplaces.ezchat.secret' => EZL_SECRET]);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 10);
});

/** EzChat's side: links the store and returns [api base, secret]. */
function linkEzChat($test): array
{
    $api = actingInTenant($test->owner, $test->tenant);
    $api->postJson('/api/v1/ezchat-link/grant', ['state' => 's1', 'redirect' => 'https://evil.test/back'])->assertUnprocessable();
    $url = $api->postJson('/api/v1/ezchat-link/grant', ['state' => 's1', 'redirect' => EZL_URL.'/assistant/orders/store'])->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    expect($q['state'])->toBe('s1');

    $path = '/api/v1/ezchat-link/exchange';
    $body = json_encode(['code' => $q['code'], 'workspace' => ['id' => 'ten_1', 'name' => 'Shop Demo']]);
    $ts = (string) time();
    $test->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZCHAT_TIMESTAMP' => $ts, 'HTTP_X_EZCHAT_SIGNATURE' => 'bad'], $body)->assertUnauthorized();
    $r = $test->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZCHAT_TIMESTAMP' => $ts, 'HTTP_X_EZCHAT_SIGNATURE' => hash_hmac('sha256', "{$ts}.{$path}.{$body}", EZL_SECRET)], $body)->assertOk();
    // The code works once.
    $test->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZCHAT_TIMESTAMP' => $ts, 'HTTP_X_EZCHAT_SIGNATURE' => hash_hmac('sha256', "{$ts}.{$path}.{$body}", EZL_SECRET)], $body)->assertUnprocessable();

    return [parse_url($r->json('url'), PHP_URL_PATH), $r->json('secret')];
}

/** A call signed like EzChat signs a shop's own API. */
function ezStore($test, string $secret, string $method, string $uri, ?array $body = null)
{
    $ts = (string) time();
    $raw = $body === null ? '' : json_encode($body);
    $payload = $method === 'GET' ? (string) parse_url($uri, PHP_URL_QUERY) : $raw;

    return $test->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZCHAT_TIMESTAMP' => $ts, 'HTTP_X_EZCHAT_SIGNATURE' => 'sha256='.hash_hmac('sha256', "{$ts}.{$payload}", $secret)], $raw ?: null);
}

it('links an EzChat workspace and gives it products with real price and stock', function () {
    [$base, $secret] = linkEzChat($this);
    $api = actingInTenant($this->owner, $this->tenant);
    $api->getJson('/api/v1/ezchat-link')->assertOk()->assertJsonPath('data.linked', true)
        ->assertJsonPath('data.workspace_name', 'Shop Demo')->assertJsonPath('data.channel.name', 'Chat EzChat');

    ezStore($this, 'wrong', 'GET', "{$base}/products?page=1")->assertUnauthorized();
    $products = ezStore($this, $secret, 'GET', "{$base}/products?page=1")->assertOk()->json();
    expect($products)->toHaveCount(1)
        ->and($products[0]['name'])->toBe('Áo thun')->and($products[0]['sku'])->toBe('AO-001')
        ->and((float) $products[0]['price'])->toBe(150000.0)->and($products[0]['stock_quantity'])->toBe(10);

    ezStore($this, $secret, 'GET', "{$base}/stock?sku={$products[0]['id']}")->assertOk()->assertJson(['inStock' => true, 'qty' => 10]);

    // "Ngắt kết nối" in EzChat unlinks here too.
    ezStore($this, $secret, 'POST', "{$base}/unlink", [])->assertOk();
    $api->getJson('/api/v1/ezchat-link')->assertJsonPath('data.linked', false);
});

it('turns an order closed in chat into an order that holds stock, once, and cancels it', function () {
    [$base, $secret] = linkEzChat($this);
    $product = ezStore($this, $secret, 'GET', "{$base}/products?page=1")->json('0.id');
    $order = [
        'code' => 'DH0007', 'items' => [['sku' => $product, 'variationId' => null, 'name' => 'Áo thun', 'variant' => null, 'qty' => 2, 'price' => 140000], ['sku' => null, 'name' => 'Gói quà', 'qty' => 1, 'price' => 10000]],
        'subtotal' => 290000, 'shippingFee' => 30000, 'discount' => 0, 'total' => 320000, 'currency' => 'VND', 'payMethod' => 'cod', 'delivery' => 'ship',
        'customer' => ['name' => 'Chị Lan', 'phone' => '0912345678', 'email' => null, 'address' => '12 Lê Lợi, Quận 1, TP Hồ Chí Minh', 'province' => 'TP Hồ Chí Minh'], 'note' => 'Giao giờ hành chính',
    ];
    $number = ezStore($this, $secret, 'POST', "{$base}/orders", $order)->assertOk()->json('id');
    expect(ezStore($this, $secret, 'POST', "{$base}/orders", $order)->json('id'))->toBe($number);

    $made = Order::withoutGlobalScopes()->where('external_order_id', $number)->sole();
    expect($made->status)->toBe('PENDING_CONFIRMATION')->and((float) $made->total)->toBe(320000.0)
        ->and($made->buyer_name)->toBe('Chị Lan')->and($made->tags)->toContain('EzChat')
        ->and(StockBalance::withoutGlobalScopes()->where('product_variant_id', $this->shirt->id)->value('reserved'))->toBe(2);

    // The assistant's "Tra đơn": by phone, then the order with the right phone only.
    ezStore($this, $secret, 'GET', "{$base}/orders?phone=0912345678")->assertOk()->assertJsonPath('orders.0.number', $number);
    ezStore($this, $secret, 'GET', "{$base}/orders/lookup?number={$number}&phone=0999999999")->assertOk()->assertJson(['found' => false]);
    ezStore($this, $secret, 'GET', "{$base}/orders/lookup?number={$number}&phone=0912345678")->assertOk()
        ->assertJsonPath('order.statusName', 'Chờ xác nhận')->assertJsonPath('order.items.0.qty', 2);
    ezStore($this, $secret, 'GET', "{$base}/stock?sku={$product}")->assertJson(['qty' => 8]);

    ezStore($this, $secret, 'POST', "{$base}/orders/{$number}/cancel", [])->assertOk()->assertJson(['ok' => true]);
    expect($made->refresh()->status)->toBe('CANCELLED')
        ->and(StockBalance::withoutGlobalScopes()->where('product_variant_id', $this->shirt->id)->value('reserved'))->toBe(0);

    // Unlinked: EzChat's calls stop at once.
    actingInTenant($this->owner, $this->tenant)->deleteJson('/api/v1/ezchat-link')->assertOk()->assertJsonPath('data.linked', false);
    expect(EzChatLink::withoutGlobalScopes()->count())->toBe(0);
    ezStore($this, $secret, 'GET', "{$base}/products?page=1")->assertNotFound();
});
