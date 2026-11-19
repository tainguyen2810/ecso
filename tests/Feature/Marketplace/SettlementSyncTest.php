<?php

use App\Modules\Marketplace\Connectors\EzChat\EzChatGateway;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Marketplace\Connectors\TikTok\TikTokConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceSettlement;
use App\Modules\Tenancy\Support\TenantContext;

beforeEach(function () {
    $this->travelTo(now()->setTime(5, 0));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 20);
    $this->shop = fakeShop($this->tenant);
});

it('reads tiền thực nhận from the sàn: an estimate once delivered, final once completed, with cost and profit', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    $api = actingInTenant($this->owner, $this->tenant);

    // Nothing from the sàn before delivery.
    $api->getJson("/api/v1/orders/{$order->ulid}")->assertOk()
        ->assertJsonPath('data.payout.supported', true)->assertJsonPath('data.payout.settlement', null);
    $api->postJson("/api/v1/orders/{$order->ulid}/payout")->assertOk()->assertJsonPath('data.settlement', null);

    app(FakeMarketplace::class)->setStatus($order->external_order_id, 'fake_delivered');
    $payout = $api->postJson("/api/v1/orders/{$order->ulid}/payout")->assertOk()->json('data');
    $goods = (float) $payout['settlement']['order_amount'];
    expect($payout['settlement']['final'])->toBeFalse()
        ->and((float) $payout['settlement']['net_amount'])->toBe($goods - round($goods * 0.04) - round($goods * 0.025) - round($goods * 0.02))
        ->and($payout['settlement']['lines'])->toHaveCount(4)
        ->and((float) $payout['cogs'])->toBe(100000.0)
        ->and((float) $payout['profit'])->toBe((float) $payout['settlement']['net_amount'] - 100000);

    // The scheduled pass skips an estimate read less than 6 hours ago, then makes it final once completed.
    app(FakeMarketplace::class)->setStatus($order->external_order_id, 'fake_completed');
    $this->artisan('ecso:marketplace:settlements')->assertSuccessful();
    expect(MarketplaceSettlement::withoutGlobalScopes()->sole()->final)->toBeFalse();
    $this->travel(7)->hours();
    $this->artisan('ecso:marketplace:settlements')->assertSuccessful();
    $row = MarketplaceSettlement::withoutGlobalScopes()->sole();
    expect($row->final)->toBeTrue()->and($row->source)->toBe('api')->and($row->order_id)->toBe($order->id);

    // Tổng quan: "Thực nhận" for finance members; the order page shows no profit to warehouse staff.
    $api->getJson('/api/v1/orders/summary')->assertOk()->assertJsonPath('data.payout.orders', 1);
    $staff = addMember($this->tenant, 'WAREHOUSE_STAFF');
    actingInTenant($staff, $this->tenant)->getJson('/api/v1/orders/summary')->assertOk()->assertJsonPath('data.payout', null);
    actingInTenant($staff, $this->tenant)->getJson("/api/v1/orders/{$order->ulid}")->assertOk()
        ->assertJsonPath('data.payout.profit', null)->assertJsonPath('data.payout.profit_hidden', true)
        ->assertJsonPath('data.payout.settlement.final', true);

    // Tài chính: the shop's row has its payout.
    $finance = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/finance/summary?from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString())->assertOk()->json('data');
    expect((float) collect($finance['by_shop'])->firstWhere('id', $this->shop->ulid)['payout'])->toBe((float) $row->net_amount);
});

it('never overwrites a settlement imported from a file', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    app(FakeMarketplace::class)->setStatus($order->external_order_id, 'fake_completed');
    TenantContext::run($this->tenant, fn () => MarketplaceSettlement::query()->create(['marketplace_account_id' => $this->shop->id, 'external_order_id' => $order->external_order_id, 'order_amount' => 1, 'net_amount' => 1, 'source' => 'file']));
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/orders/{$order->ulid}/payout")->assertOk()
        ->assertJsonPath('data.settlement.source', 'file')->assertJsonPath('data.settlement.net_amount', '1.00');
});

it('maps Shopee escrow detail and TikTok statements', function () {
    $account = new MarketplaceAccount(['marketplace' => 'shopee', 'status' => 'connected']);
    $gateway = Mockery::mock(EzChatGateway::class);
    $gateway->shouldReceive('call')->with($account, 'GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => 'SN1'])->andReturn(['response' => ['order_income' => [
        'escrow_amount' => 171500, 'cost_of_goods_sold' => 200000, 'original_price' => 220000, 'seller_discount' => 20000, 'voucher_from_seller' => 10000,
        'commission_fee' => 8000, 'service_fee' => 6000, 'seller_transaction_fee' => 4500, 'final_shipping_fee' => 0, 'order_ams_commission_fee' => 0,
    ]]]);
    $s = (new ShopeeConnector($gateway))->settlement($account, 'SN1');
    expect($s['net_amount'])->toBe(171500.0)->and($s['order_amount'])->toBe(200000.0)->and($s['commission_fee'])->toBe(8000.0)
        ->and($s['payment_fee'])->toBe(4500.0)->and($s['final'])->toBeNull()
        ->and(array_column($s['lines'], 'label'))->toBe(['Tiền hàng', 'Voucher của shop', 'Phí cố định (hoa hồng)', 'Phí dịch vụ', 'Phí thanh toán']);

    $tt = new MarketplaceAccount(['marketplace' => 'tiktok', 'status' => 'connected']);
    $gw = Mockery::mock(EzChatGateway::class);
    $gw->shouldReceive('call')->with($tt, 'GET', '/finance/202309/orders/577/statement_transactions')->andReturn(['statement_transactions' => [[
        'status' => 'SETTLED', 'statement_time' => 1763600000, 'revenue_amount' => '300000', 'settlement_amount' => '262000', 'fee_amount' => '-30000',
        'platform_commission_amount' => '-18000', 'transaction_fee_amount' => '-9000', 'affiliate_commission_amount' => '-3000', 'shipping_cost_amount' => '-8000',
    ]]]);
    $t = (new TikTokConnector($gw))->settlement($tt, '577');
    expect($t['net_amount'])->toBe(262000.0)->and($t['commission_fee'])->toBe(18000.0)->and($t['payment_fee'])->toBe(9000.0)
        ->and($t['service_fee'])->toBe(3000.0)->and($t['shipping_fee'])->toBe(8000.0)->and($t['final'])->toBeTrue()
        ->and(array_column($t['lines'], 'label'))->toContain('Phí hoa hồng sàn', 'Hoa hồng tiếp thị liên kết', 'Phí vận chuyển shop trả');
});
