<?php

use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Orders\Models\Order;

/** "Cần chuẩn bị hàng": Shopee / TikTok orders waiting for "Chuẩn bị hàng" over all pages, grouped by product. */
beforeEach(function () {
    $this->travelTo(now()->setTime(5, 0));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->glue = makeVariant($this->tenant, 'KEO-30G', ['product_name' => 'Keo tản nhiệt']);
    $this->card = makeVariant($this->tenant, 'WIFI-6E', ['product_name' => 'Card WiFi 6E']);
    $this->antenna = makeVariant($this->tenant, 'ANT-10', ['product_name' => 'Anten 10 dBi']);
    stockIn($this->tenant, $this->glue, 14);
    stockIn($this->tenant, $this->card, 6);
    stockIn($this->tenant, $this->antenna, 1);
    $this->shop = fakeShop($this->tenant);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

/** A fake-shop order shown as a Shopee one (the marketplace that books its own carrier). */
function shopeeOrder($shop, array $lines, ?string $shipBy = null, string $status = 'fake_ready'): Order
{
    $order = fakeOrder($shop, $lines, $status);
    Order::withoutGlobalScopes()->whereKey($order->id)->update(['marketplace' => 'shopee', 'ship_by_at' => $shipBy]);

    return $order->fresh();
}

it('lists the orders waiting for Chuẩn bị hàng with urgency, stock and products', function () {
    $later = shopeeOrder($this->shop, [[$this->glue, 1]], now()->addDay()->toDateTimeString());
    $soon = shopeeOrder($this->shop, [[$this->glue, 3]], now()->addHour()->toDateTimeString());
    $both = shopeeOrder($this->shop, [[$this->card, 1], [$this->antenna, 1]], now()->addHours(5)->toDateTimeString());
    $short = shopeeOrder($this->shop, [[$this->antenna, 2]], now()->addHours(6)->toDateTimeString());
    shopeeOrder($this->shop, [[$this->glue, 1]], null, 'fake_pending'); // not confirmed yet: not waiting
    fakeOrder($this->shop, [[$this->glue, 1]]); // the fake shop does not arrange shipment itself

    $data = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/orders/to-arrange')->assertOk()->json('data');

    expect($data['count'])->toBe(4)
        ->and($data['urgent'])->toBe(1)
        ->and($data['ready'])->toBe(3)
        ->and(collect($data['orders'])->pluck('id')->all())->toBe([$soon->ulid, $both->ulid, $short->ulid, $later->ulid])
        ->and(collect($data['orders'])->firstWhere('id', $short->ulid)['shortage'])->toBeTrue()
        ->and(collect($data['orders'])->firstWhere('id', $both->ulid)['products'])->toBe(['v:'.$this->card->ulid => 1, 'v:'.$this->antenna->ulid => 1]);

    $products = collect($data['products'])->keyBy('sku');
    expect($products['KEO-30G'])->toMatchArray(['name' => 'Keo tản nhiệt', 'quantity' => 4, 'on_hand' => 14, 'short' => false, 'orders' => [$soon->ulid, $later->ulid]])
        ->and($products['ANT-10'])->toMatchArray(['quantity' => 3, 'on_hand' => 1, 'short' => true])
        ->and($products->keys()->first())->toBe('KEO-30G');
});

it('keeps to the list filters and to what the member may see', function () {
    shopeeOrder($this->shop, [[$this->glue, 1]]);

    $api = actingInTenant($this->owner, $this->tenant);
    $api->getJson('/api/v1/orders/to-arrange?q=KEO-30G')->assertJsonPath('data.count', 1);
    $api->getJson('/api/v1/orders/to-arrange?q=KHONG-CO')->assertJsonPath('data.count', 0)->assertJsonPath('data.products', []);
    $api->getJson('/api/v1/orders/to-arrange?marketplace=tiktok')->assertJsonPath('data.count', 0);
});
