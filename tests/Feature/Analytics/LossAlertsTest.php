<?php

use App\Modules\Messaging\Models\PushSubscription;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Đợt 14 "Cảnh báo đơn lỗ": loss orders after fees, SKUs to raise, the bell and the phone alert. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    // Sold at 454 000, costs 420 000: fine before fees, a loss once the shop's 19.9 % + 3 000 đ are taken.
    $this->card = makeVariant($this->tenant, 'AX210', ['product_name' => 'Card WiFi AX210', 'ref_price' => 454000]);
    $this->cheap = makeVariant($this->tenant, 'CAP-1', ['product_name' => 'Cáp mạng', 'ref_price' => 100000]);
    stockIn($this->tenant, $this->card, 10);
    stockIn($this->tenant, $this->cheap, 10);
    TenantContext::run($this->tenant, function () {
        DB::table('product_variants')->where('id', $this->card->id)->update(['cost_price' => 420000]);
        DB::table('product_variants')->where('id', $this->cheap->id)->update(['cost_price' => 30000]);
    });
    $this->shop = fakeShop($this->tenant);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->api->putJson("/api/v1/marketplace-pricing/rules/{$this->shop->ulid}", [
        'shop' => ['commission_pct' => 9, 'payment_pct' => 4.9, 'service_pct' => 6, 'fixed_fee' => 3000, 'rounding' => 100, 'min_margin_pct' => 8, 'auto_apply' => false, 'auto_update_fees' => false],
        'categories' => [],
    ])->assertOk();
});

it('lists orders sold at a loss after fees and the SKUs to raise with a break-even price', function () {
    $loss = fakeOrder($this->shop, [[$this->card, 1]]);
    fakeOrder($this->shop, [[$this->cheap, 1]]);

    $orders = $this->api->getJson('/api/v1/reports/loss-orders')->assertOk()->json('data');
    // 454 000 − 420 000 − (454 000 × 19,9 % + 3 000 = 93 346) = −59 346
    expect($orders['totals']['orders'])->toBe(1)
        ->and($orders['rows'][0])->toMatchArray(['id' => $loss->ulid, 'fee_kind' => 'estimated', 'skus' => ['AX210']])
        ->and($orders['rows'][0]['profit'])->toEqual(-59346);

    $products = $this->api->getJson('/api/v1/reports/loss-orders?by=product')->assertOk()->json('data');
    expect($products['rows'])->toHaveCount(1)->and($products['rows'][0]['variant']['sku'])->toBe('AX210')
        // (454 000 + 59 346 / (1 − 0,2056)) rounded up to 1 000
        ->and($products['rows'][0]['break_even'])->toEqual(529000);

    // The bell shows it to members who may see profit.
    $bell = collect($this->api->getJson('/api/v1/inbox')->assertOk()->json('data'))->firstWhere('key', 'orders.loss');
    expect($bell['count'])->toBe(1)->and($bell['to'])->toBe('/reports/view/loss-orders');
});

it('tells the owner phone once per loss order', function () {
    $this->api->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://push.example.test/sub/abc', 'device' => 'Android'])->assertOk();
    Http::fake(['push.example.test/*' => Http::response('', 201)]);
    $order = fakeOrder($this->shop, [[$this->card, 1]]);
    Http::fake(['push.example.test/*' => Http::response('', 201)]);

    $this->artisan('ecso:orders:loss-alerts')->assertSuccessful();
    $msg = PushSubscription::withoutGlobalScopes()->sole();
    expect($msg->last_title)->toBe('Đơn lỗ sau phí · '.$this->shop->shop_name)->and($msg->last_body)->toContain('lỗ 59.346đ')
        ->and($msg->last_url)->toBe('/orders/'.$order->ulid)
        ->and(Order::withoutGlobalScopes()->find($order->id)->loss_alerted_at)->not->toBeNull();

    // Already told: the next pass sends nothing.
    Http::fake(['push.example.test/*' => Http::response('', 201)]);
    $this->artisan('ecso:orders:loss-alerts')->assertSuccessful();
    Http::assertNothingSent();
});
