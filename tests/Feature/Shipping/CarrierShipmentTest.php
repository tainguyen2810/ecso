<?php

use App\Modules\Geo\Models\Ward;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Shipping\Connectors\Fake\FakeCarrier;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Support\Facades\Cache;

/** Carrier connections (đợt 1): connect, compare prices, push, follow the carrier's reports, cancel. Fake carrier only. */
beforeEach(function () {
    config(['shipping.fake_enabled' => true]);
    Cache::flush();
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->variant = makeVariant($this->tenant, null, ['weight_gram' => 300]);
    stockIn($this->tenant, $this->variant, 20);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->ward = Ward::query()->where('province_code', '79')->orderBy('code')->firstOrFail();
    $this->order = function (array $extra = []) {
        return createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 150000]], [
            'stage' => 'pending', 'is_cod' => true, 'buyer_name' => 'Trần Thị Mai', 'buyer_phone' => '0912345789',
            'shipping_street' => '45 Lê Thánh Tôn', 'shipping_province_code' => '79', 'shipping_ward_code' => $this->ward->code, ...$extra,
        ])->assertCreated()->json('data.id');
    };
    $this->connect = fn () => $this->api->postJson('/api/v1/carrier-accounts', ['carrier' => 'fake', 'credentials' => ['token' => 'abc']])->assertCreated()->json('data');
    $this->shipment = fn (string $orderId) => Shipment::withoutGlobalScopes()->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('ulid', $orderId))->latest('id')->first();
    $this->webhook = fn (array $body) => $this->postJson('/api/v1/carrier-webhooks/'.CarrierAccount::withoutGlobalScopes()->sole()->webhook_token, $body);
});

it('connects in one step, hides the credentials and matches warehouses to pickup points', function () {
    $this->api->postJson('/api/v1/carrier-accounts', ['carrier' => 'fake', 'credentials' => ['token' => 'bad']])->assertStatus(422)->assertJsonPath('errors.credentials.0', 'Mã kết nối không đúng.');
    $account = ($this->connect)();
    expect($account['name'])->toBe('Tài khoản giả lập')->and($account['status'])->toBe('connected')->and($account['pickups'])->toHaveCount(1)
        ->and($account['webhook_url'])->toContain('/api/v1/carrier-webhooks/')->and($account)->not->toHaveKey('credentials');
    expect(CarrierAccount::withoutGlobalScopes()->sole()->getRawOriginal('credentials'))->not->toContain('abc');

    // Connecting the same account again updates it instead of adding a second one.
    ($this->connect)();
    expect($this->api->getJson('/api/v1/carrier-accounts')->json('data'))->toHaveCount(1);
    $catalog = collect($this->api->getJson('/api/v1/carriers')->json('data'))->keyBy('carrier');
    expect($catalog['fake']['available'])->toBeTrue()->and($catalog['jt']['available'])->toBeFalse();

    // Staff without settings rights cannot connect; other businesses never see the account.
    $staff = addMember($this->tenant, 'WAREHOUSE_STAFF');
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/carrier-accounts', ['carrier' => 'fake', 'credentials' => ['token' => 'x']])->assertForbidden();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    expect(actingInTenant($otherOwner, $other)->getJson('/api/v1/carrier-accounts')->json('data'))->toBe([]);

    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->api->deleteJson("/api/v1/carrier-accounts/{$account['id']}")->assertOk();
    expect(CarrierAccount::withoutGlobalScopes()->sole()->credentials)->toBeNull()->and($this->api->getJson('/api/v1/carrier-accounts')->json('data'))->toBe([]);
});

it('compares prices for a saved order and for the order form, cheapest and fastest first', function () {
    ($this->connect)();
    $id = ($this->order)();
    $quote = $this->api->postJson('/api/v1/shipping/quotes', ['order_id' => $id])->assertOk()->json('data');
    expect($quote['weight_g'])->toBe(600)->and($quote['cod_amount'])->toBe('300000.00')
        ->and(array_column($quote['accounts'][0]['services'], 'code'))->toBe(['standard', 'express'])
        ->and($quote['cheapest']['code'])->toBe('standard')->and($quote['fastest']['code'])->toBe('express');

    $form = $this->api->postJson('/api/v1/shipping/quotes', ['province_code' => '01', 'ward_code' => Ward::query()->where('province_code', '01')->value('code'), 'weight_g' => 1200])->assertOk()->json('data');
    expect($form['accounts'][0]['services'][0]['fee'])->toBe('33000.00'); // 3 steps of 500 g + another province

    $missing = $this->api->postJson('/api/v1/shipping/quotes', ['province_code' => '79'])->assertOk()->json('data.accounts.0');
    expect($missing['services'])->toBe([])->and($missing['error'])->toBe('Địa chỉ nhận thiếu tỉnh/thành hoặc phường/xã.');
});

it('pushes an order: confirms it, gets the tracking number, and follows the carrier to delivery', function () {
    $account = ($this->connect)();
    $id = ($this->order)();
    $res = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard', 'service_name' => 'Tiêu chuẩn', 'required_note' => 'try'])->assertCreated()->json('data');
    $order = Order::withoutGlobalScopes()->where('ulid', $id)->sole();
    expect($res['status'])->toBe('awaiting_pickup')->and($res['tracking_number'])->toStartWith('FAKE')->and($res['source'])->toBe('api')
        ->and($res['fee_estimated'])->toBe('20000.00')->and($res['service_name'])->toBe('Tiêu chuẩn')
        ->and($order->status)->toBe(OrderStatus::READY_TO_PACK)->and($order->tracking_number)->toBe($res['tracking_number'])->and($order->shipping_carrier)->toBe('Hãng giả lập');
    $balance = fn () => StockBalance::withoutGlobalScopes()->where('product_variant_id', $this->variant->id)->value('on_hand');
    expect($balance())->toBe(20); // nothing leaves before the carrier picks it up

    // Pushing again is refused: the order has its parcel.
    $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard'])->assertStatus(422);

    $tracking = $res['tracking_number'];
    // One report, sent twice below: its time is fixed so the second send is the very same event even when the clock
    // ticks over to the next second in between.
    $picked = ['tracking' => $tracking, 'status' => 'picked', 'time' => now()->toIso8601String()];
    ($this->webhook)($picked)->assertOk()->assertJsonPath('new', 1);
    expect($order->refresh()->status)->toBe(OrderStatus::HANDED_OVER)->and($balance())->toBe(18);
    // The same report twice counts once.
    ($this->webhook)($picked)->assertOk()->assertJsonPath('new', 0);

    ($this->webhook)(['tracking' => $tracking, 'status' => 'delivering', 'time' => now()->addHour()->toIso8601String()])->assertOk();
    expect(($this->shipment)($id)->status)->toBe('out_for_delivery');
    ($this->webhook)(['tracking' => $tracking, 'status' => 'delivered', 'time' => now()->addHours(2)->toIso8601String(), 'fee' => 16500])->assertOk();
    $shipment = ($this->shipment)($id);
    expect($order->refresh()->status)->toBe(OrderStatus::DELIVERED)->and($shipment->status)->toBe('delivered')->and($shipment->cod_status)->toBe('collected')
        ->and($shipment->fee_actual)->toBe('16500.00')->and($shipment->fee_status)->toBe('confirmed')->and($balance())->toBe(18);

    // A late, older report only goes into the history.
    ($this->webhook)(['tracking' => $tracking, 'status' => 'transporting', 'time' => now()->toIso8601String()])->assertOk()->assertJsonPath('new', 1);
    expect(($this->shipment)($id)->status)->toBe('delivered');
    expect(ShipmentEvent::withoutGlobalScopes()->where('shipment_id', $shipment->id)->where('source', 'webhook')->count())->toBe(4);

    // Unknown token: 404. Unknown parcel: accepted and ignored.
    $this->postJson('/api/v1/carrier-webhooks/'.str_repeat('x', 48), ['tracking' => $tracking, 'status' => 'cancel'])->assertNotFound();
    ($this->webhook)(['tracking' => 'NOPE', 'status' => 'delivered'])->assertOk()->assertJsonPath('new', 0);
});

it('opens the return on a failed delivery and never adds stock when the parcel comes back', function () {
    $account = ($this->connect)();
    $id = ($this->order)();
    $tracking = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard'])->json('data.tracking_number');
    // GHN may report the failure without a separate "picked" first: the order goes through handover on the way.
    ($this->webhook)(['tracking' => $tracking, 'status' => 'delivery_fail', 'reason' => 'Khách không nghe máy', 'time' => now()->toIso8601String()])->assertOk();
    $order = Order::withoutGlobalScopes()->where('ulid', $id)->sole();
    $shipment = ($this->shipment)($id);
    expect($order->status)->toBe(OrderStatus::FAILED_DELIVERY)->and($shipment->status)->toBe('failed')->and($shipment->attempts)->toBe(1)
        ->and(OrderReturn::withoutGlobalScopes()->count())->toBe(1);

    ($this->webhook)(['tracking' => $tracking, 'status' => 'return', 'time' => now()->addDay()->toIso8601String()])->assertOk();
    ($this->webhook)(['tracking' => $tracking, 'status' => 'returned', 'time' => now()->addDays(2)->toIso8601String()])->assertOk();
    expect(($this->shipment)($id)->status)->toBe('returned')->and(($this->shipment)($id)->cod_status)->toBe('returned')
        ->and(StockBalance::withoutGlobalScopes()->where('product_variant_id', $this->variant->id)->value('on_hand'))->toBe(18);
});

it('keeps a refused parcel as "Chờ đẩy" with the reason, retries with the same code, and finds a parcel whose answer was lost', function () {
    $account = ($this->connect)();
    $id = ($this->order)();
    $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard', 'note' => '[reject]'])
        ->assertStatus(422)->assertJsonPath('errors.carrier.0', 'Hãng từ chối: số điện thoại người nhận không hợp lệ.');
    $shipment = ($this->shipment)($id);
    $code = $shipment->client_code;
    expect($shipment->status)->toBe('pending')->and($shipment->api_error)->toContain('không hợp lệ')->and($shipment->tracking_number)->toBeNull();

    // Pushed again: same row, same code. The carrier made it but the answer was lost: found by our code.
    $res = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'express', 'note' => '[timeout]'])->assertCreated()->json('data');
    expect(Shipment::withoutGlobalScopes()->count())->toBe(1)->and(($this->shipment)($id)->client_code)->toBe($code)
        ->and($res['tracking_number'])->toBe('FAKE'.strtoupper(substr(hash('sha256', $code), 0, 8)))->and($res['api_error'])->toBeNull();
});

it('cancels at the carrier before pickup, refuses after, and lets the shop give up a refused parcel', function () {
    $account = ($this->connect)();
    $id = ($this->order)();
    $shipment = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard'])->json('data');
    $this->api->postJson("/api/v1/shipments/{$shipment['id']}/carrier-cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('tracking_number'))->toBeNull();

    // Pushed again after the cancel, then picked up: no longer cancellable.
    $again = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard'])->assertCreated()->json('data');
    ($this->webhook)(['tracking' => $again['tracking_number'], 'status' => 'picked', 'time' => now()->toIso8601String()])->assertOk();
    $this->api->postJson("/api/v1/shipments/{$again['id']}/carrier-cancel")->assertStatus(422);

    $id2 = ($this->order)();
    $this->api->postJson("/api/v1/orders/{$id2}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard', 'note' => '[reject]'])->assertStatus(422);
    $refused = ($this->shipment)($id2);
    $this->api->postJson("/api/v1/shipments/{$refused->ulid}/carrier-discard")->assertOk()->assertJsonPath('data.status', 'cancelled');
    // Now it can be shipped another way.
    $this->api->postJson("/api/v1/orders/{$id2}/shipments", ['mode' => 'manual', 'carrier' => 'J&T Express', 'tracking_number' => 'JT777'])->assertCreated();
});

it('pushes in bulk, one result per order, and refuses marketplace orders', function () {
    $account = ($this->connect)();
    $a = ($this->order)();
    $b = ($this->order)(['shipping_ward_code' => null, 'shipping_province_code' => null, 'shipping_address' => 'Không rõ']);
    $res = $this->api->postJson('/api/v1/carrier-shipments/bulk', ['orders' => [
        ['id' => $a, 'account_id' => $account['id'], 'service' => 'standard'],
        ['id' => $b, 'account_id' => $account['id'], 'service' => 'standard'],
    ]])->assertOk()->json('data');
    expect($res['pushed'])->toBe(1)->and($res['failed'])->toBe(1)->and($res['results'][1]['error'])->toBe('Địa chỉ nhận thiếu tỉnh/thành hoặc phường/xã.');

    $shop = fakeShop($this->tenant);
    $market = fakeOrder($shop, [[$this->variant, 1]], 'fake_ready');
    $this->api->postJson("/api/v1/orders/{$market->ulid}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard'])->assertStatus(422);

    // The fake carrier has no labels: the shop is told to print with EzSell instead.
    $this->api->postJson('/api/v1/shipments/labels', ['ids' => [$res['results'][0]['shipment_id']]])->assertStatus(422)->assertJsonPath('errors.ids.0', 'Hãng giả lập không có nhãn in. Dùng "In phiếu" của EzSell.');
});

it('asks the carrier again about parcels that heard nothing, without asking twice in a row', function () {
    $account = ($this->connect)();
    $id = ($this->order)();
    $tracking = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard'])->json('data.tracking_number');
    FakeCarrier::setHistory($tracking, [['tracking' => $tracking, 'status' => 'picked', 'time' => now()->toIso8601String()], ['tracking' => $tracking, 'status' => 'delivered', 'time' => now()->addHour()->toIso8601String()]]);

    $this->artisan('ecso:carriers:track')->expectsOutputToContain('Đã hỏi 0 vận đơn')->assertSuccessful(); // too recent
    $this->travel(3)->hours();
    $this->artisan('ecso:carriers:track')->expectsOutputToContain('Đã hỏi 1 vận đơn, 1 vận đơn có tin mới')->assertSuccessful();
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::DELIVERED);

    // "Cập nhật từ hãng" by hand.
    $this->api->postJson('/api/v1/shipments/'.($this->shipment)($id)->ulid.'/carrier-refresh')->assertOk()->assertJsonPath('data.new_events', 0);
});

it('lists the orders waiting to be pushed, keeps refused ones there and drops pushed ones', function () {
    $account = ($this->connect)();
    $waiting = ($this->order)();
    $refused = ($this->order)(['note' => 'x']);
    $pushed = ($this->order)();
    $this->api->postJson("/api/v1/orders/{$refused}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard', 'note' => '[reject]'])->assertStatus(422);
    $this->api->postJson("/api/v1/orders/{$pushed}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'standard'])->assertCreated();

    $rows = collect($this->api->getJson('/api/v1/carrier-shipments/queue')->assertOk()->json('data'))->keyBy('id');
    expect($rows->keys()->all())->toEqualCanonicalizing([$waiting, $refused])
        ->and($rows[$waiting]['weight_g'])->toBe(600)->and($rows[$waiting]['has_address'])->toBeTrue()->and($rows[$waiting]['failed_shipment'])->toBeNull()
        ->and($rows[$refused]['failed_shipment']['error'])->not->toBeNull();
});
