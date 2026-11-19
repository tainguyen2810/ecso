<?php

use App\Modules\Messaging\Jobs\SendNewOrderPush;
use App\Modules\Messaging\Models\PushSubscription;
use App\Modules\Messaging\Push\WebPush;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 10);
});

it('signs VAPID tokens the push services accept', function () {
    $push = app(WebPush::class);
    $key = $push->publicKey();
    expect(strlen(base64_decode(strtr($key, '-_', '+/'))))->toBe(65);

    Http::fake(['push.example.test/*' => Http::response('', 201), 'gone.example.test/*' => Http::response('', 410)]);
    expect($push->wake('https://push.example.test/sub/1'))->toBeTrue()
        ->and($push->wake('https://gone.example.test/sub/2'))->toBeFalse();
    Http::assertSent(function (Request $r) use ($key) {
        if (! str_starts_with($r->url(), 'https://push.example.test/')) {
            return false;
        }
        preg_match('/^vapid t=([^,]+), k=(.+)$/', $r->header('Authorization')[0], $m);
        [, $c, $sig] = explode('.', $m[1]);
        $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);

        return $m[2] === $key && $claims['aud'] === 'https://push.example.test' && strlen(base64_decode(strtr($sig, '-_', '+/'))) === 64 && $r->header('TTL')[0] === '3600';
    });
});

it('tells subscribed phones about new orders, several at once in one notification', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->getJson('/api/v1/push')->assertOk()->assertJsonPath('data.subscribed', false);
    $api->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://push.example.test/sub/abc', 'device' => 'Android'])->assertOk();
    $api->getJson('/api/v1/push?endpoint='.urlencode('https://push.example.test/sub/abc'))->assertJsonPath('data.subscribed', true);
    Http::fake(['push.example.test/*' => Http::response('', 201)]);

    // The queue runs at once in tests: the first order is told, the second falls in the same half minute.
    $channel = defaultChannel($this->tenant)->ulid;
    $api->postJson('/api/v1/sales-orders', ['channel_id' => $channel, 'buyer_name' => 'Chị Lan', 'lines' => [['variant_id' => $this->shirt->ulid, 'quantity' => 1]]])->assertCreated();
    $api->postJson('/api/v1/sales-orders', ['channel_id' => $channel, 'buyer_name' => 'Anh Nam', 'lines' => [['variant_id' => $this->shirt->ulid, 'quantity' => 1]]])->assertCreated();
    Http::assertSentCount(1);
    $msg = $this->postJson('/api/v1/push/last', ['endpoint' => 'https://push.example.test/sub/abc'])->assertOk()->json('data');
    expect($msg['title'])->toStartWith('Đơn mới')->and($msg['body'])->toContain('Chị Lan')->and($msg['url'])->toStartWith('/orders/');

    $title = TenantContext::run($this->tenant, fn () => SendNewOrderPush::message(Order::query()->latest('id')->limit(2)->get()->all())['title']);
    expect($title)->toBe('2 đơn mới');

    // Unknown endpoints read nothing; unsubscribing stops it.
    $this->postJson('/api/v1/push/last', ['endpoint' => 'https://push.example.test/sub/other'])->assertOk()->assertJsonPath('data', null);
    $api->deleteJson('/api/v1/push/subscriptions', ['endpoint' => 'https://push.example.test/sub/abc'])->assertOk();
    expect(PushSubscription::withoutGlobalScopes()->count())->toBe(0);
});
