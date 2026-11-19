<?php

use App\Modules\Marketplace\Connectors\EzChat\EzChatGateway;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Marketplace\Connectors\TikTok\TikTokConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Models\OrderReturn;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(now()->setTime(5, 0));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 10);
    $this->shop = fakeShop($this->tenant);
    $this->fake = app(FakeMarketplace::class);
});

function deliveredOrder($test, int $qty)
{
    $order = fakeOrder($test->shop, [[$test->shirt, $qty]]);
    foreach (['fake_packed', 'fake_shipped', 'fake_delivered'] as $s) {
        $test->fake->setStatus($order->external_order_id, $s);
    }

    return $order;
}

it('brings marketplace return requests into Hoàn/Trả and receives the parcel by scanning its tracking number', function () {
    $order = deliveredOrder($this, 2);
    $this->fake->setStatus($order->external_order_id, 'fake_return_requested', ['return_reason' => 'Sai size']);
    $this->artisan('ecso:marketplace:returns')->assertSuccessful();

    $ret = OrderReturn::withoutGlobalScopes()->where('order_id', $order->id)->sole();
    expect($ret->external_return_id)->toBe('R'.$order->external_order_id)
        ->and($ret->status)->toBe(OrderReturn::REQUESTED)->and($ret->reason)->toBe('Sai size')
        ->and($ret->respond_by)->not->toBeNull()
        ->and((int) $ret->lines()->withoutGlobalScopes()->sum('quantity'))->toBe(2);

    $api = actingInTenant($this->owner, $this->tenant);
    $api->getJson('/api/v1/returns?source=marketplace')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.external_return_id', 'R'.$order->external_order_id);

    // The buyer sends it back: in transit, with its tracking number. Read again: still one return.
    $this->travel(1)->minutes();
    $this->fake->setStatus($order->external_order_id, 'fake_return_requested', ['return_tracking' => 'SPXVNRET01']);
    $api->postJson('/api/v1/returns/sync')->assertOk()->assertJsonPath('data.created', 0);
    expect($ret->refresh()->status)->toBe(OrderReturn::IN_TRANSIT)->and($ret->return_tracking_number)->toBe('SPXVNRET01')
        ->and(OrderReturn::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(1);

    // Scan at the warehouse: received, ready to inspect.
    $api->postJson('/api/v1/returns/scan', ['code' => 'KHONGCO'])->assertNotFound();
    $api->postJson('/api/v1/returns/scan', ['code' => 'SPXVNRET01'])->assertOk()
        ->assertJsonPath('data.status', 'received')->assertJsonPath('data.id', $ret->ulid);

    // Inspected: sellable goods back in stock.
    $line = $api->getJson("/api/v1/returns/{$ret->ulid}")->json('data.lines.0.id');
    $api->postJson("/api/v1/returns/{$ret->ulid}/inspect", ['warehouse_id' => defaultWarehouse($this->tenant)->ulid, 'lines' => [['line_id' => $line, 'received_qty' => 2, 'sellable_qty' => 2, 'damaged_qty' => 0]]])
        ->assertOk()->assertJsonPath('data.status', 'inspected');
    expect(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->on_hand)->toBe(10);
});

it('maps Shopee and TikTok return requests', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00'));
    $account = new MarketplaceAccount(['marketplace' => 'shopee', 'status' => 'connected']);
    $gateway = Mockery::mock(EzChatGateway::class);
    $gateway->shouldReceive('call')->andReturn(['response' => ['more' => false, 'return' => [[
        'return_sn' => '2511RET', 'order_sn' => '2511SN', 'status' => 'ACCEPTED', 'reason' => 'WRONG_ITEM', 'text_reason' => 'Giao màu đen', 'refund_amount' => 150000,
        'tracking_number' => 'SPXRET9', 'needs_logistics' => true, 'due_date' => 1763600000,
        'item' => [['item_id' => 11, 'model_id' => 22, 'name' => 'Áo thun', 'amount' => 2, 'variation_sku' => 'AO-001']], 'image' => ['https://cf.shopee.vn/a.jpg', 'http://x'],
    ]]]]);
    $page = (new ShopeeConnector($gateway))->listReturns($account, now()->subDays(3)->toImmutable());
    expect($page['next'])->toBeNull()
        ->and($page['returns'][0])->toMatchArray(['id' => '2511RET', 'order_id' => '2511SN', 'status' => 'shipping', 'goods' => true, 'reason' => 'Giao sai hàng: Giao màu đen', 'refund' => 150000.0, 'tracking_number' => 'SPXRET9', 'images' => ['https://cf.shopee.vn/a.jpg']])
        ->and($page['returns'][0]['items'][0])->toBe(['external_sku_id' => '22', 'seller_sku' => 'AO-001', 'name' => 'Áo thun', 'quantity' => 2]);

    $tt = new MarketplaceAccount(['marketplace' => 'tiktok', 'status' => 'connected']);
    $gw = Mockery::mock(EzChatGateway::class);
    $gw->shouldReceive('call')->andReturn(['next_page_token' => '', 'return_orders' => [[
        'return_id' => '4035', 'order_id' => '5780', 'return_status' => 'RETURN_OR_REFUND_REQUEST_PENDING', 'return_type' => 'RETURN_AND_REFUND', 'return_reason_text' => 'Hàng lỗi',
        'refund_amount' => ['refund_total' => '99000'], 'seller_next_action_response' => [['deadline' => 1763700000]],
        'return_line_items' => [['sku_id' => '17', 'seller_sku' => 'AO-001', 'product_name' => 'Áo', 'sku_name' => 'Đen, M'], ['sku_id' => '17', 'seller_sku' => 'AO-001', 'product_name' => 'Áo', 'sku_name' => 'Đen, M']],
    ]]]);
    $t = (new TikTokConnector($gw))->listReturns($tt, now()->subDays(3)->toImmutable())['returns'][0];
    expect($t['status'])->toBe('requested')->and($t['goods'])->toBeTrue()->and($t['refund'])->toBe(99000.0)
        ->and($t['items'])->toHaveCount(1)->and($t['items'][0]['quantity'])->toBe(2)
        ->and($t['respond_by']?->getTimestamp())->toBe(1763700000);
});
