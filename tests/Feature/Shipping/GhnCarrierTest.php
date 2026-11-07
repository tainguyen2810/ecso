<?php

use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** GHN (đợt 2) against GHN's documented API, simulated with Http::fake: no call ever leaves the test. */
beforeEach(function () {
    Cache::flush();
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->variant = makeVariant($this->tenant, null, ['weight_gram' => 300]);
    stockIn($this->tenant, $this->variant, 20);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->ward = Ward::query()->where('province_code', '79')->orderBy('code')->firstOrFail();
    $this->province = Province::query()->where('code', '79')->firstOrFail();
    $base = 'https://dev-online-gateway.ghn.vn/shiip/public-api';
    Http::preventStrayRequests();
    Http::fake([
        "{$base}/v2/shop/all" => fn (Request $r) => $r->header('Token')[0] === 'good'
            ? Http::response(['code' => 200, 'data' => ['client_id' => 77001, 'shops' => [['_id' => 885312, 'name' => 'ITSO Quận 10', 'phone' => '0909123456', 'address' => '120 Ba Tháng Hai', 'district_id' => 1452, 'ward_code' => '21012']]]])
            : Http::response(['code' => 401, 'message' => 'Token is not valid'], 401),
        "{$base}/master-data/province" => Http::response(['code' => 200, 'data' => [['ProvinceID' => 202, 'ProvinceName' => 'Hồ Chí Minh', 'NameExtension' => ['TP.HCM', $this->province->name]]]]),
        "{$base}/master-data/district" => Http::response(['code' => 200, 'data' => [['DistrictID' => 1442, 'DistrictName' => 'Quận 1']]]),
        "{$base}/master-data/ward" => Http::response(['code' => 200, 'data' => [['WardCode' => '20109', 'WardName' => 'Phường Khác'], ['WardCode' => '20101', 'WardName' => $this->ward->name]]]),
        "{$base}/v2/shipping-order/available-services" => Http::response(['code' => 200, 'data' => [['service_id' => 53320, 'short_name' => 'Hàng nhẹ', 'service_type_id' => 2], ['service_id' => 100039, 'short_name' => 'Hàng nặng', 'service_type_id' => 5]]]),
        "{$base}/v2/shipping-order/fee" => fn (Request $r) => Http::response(['code' => 200, 'data' => ['total' => $r['service_id'] === 53320 ? 22000 : 41500]]),
        "{$base}/v2/shipping-order/leadtime" => Http::response(['code' => 200, 'data' => ['leadtime' => now()->addDays(2)->startOfDay()->getTimestamp()]]),
        "{$base}/v2/shipping-order/create" => fn (Request $r) => $r['to_phone'] === '0000'
            ? Http::response(['code' => 400, 'message' => 'Số điện thoại không hợp lệ', 'code_message_value' => 'Số điện thoại người nhận không hợp lệ'], 400)
            : Http::response(['code' => 200, 'data' => ['order_code' => 'GYKX7L9P', 'total_fee' => 22000, 'expected_delivery_time' => now()->addDays(2)->toIso8601String()]]),
        "{$base}/v2/switch-status/cancel" => Http::response(['code' => 200, 'data' => [['order_code' => 'GYKX7L9P', 'result' => true]]]),
        "{$base}/v2/a5/gen-token" => Http::response(['code' => 200, 'data' => ['token' => 'tok-123']]),
        "{$base}/v2/shipping-order/detail" => Http::response(['code' => 200, 'data' => ['status' => 'delivered', 'log' => [['status' => 'picked', 'updated_date' => now()->toIso8601String()], ['status' => 'delivered', 'updated_date' => now()->addHour()->toIso8601String()]]]]),
    ]);
    $this->connect = fn (string $token = 'good') => $this->api->postJson('/api/v1/carrier-accounts', ['carrier' => 'ghn', 'environment' => 'sandbox', 'credentials' => ['token' => $token]]);
    $this->order = fn (array $extra = []) => createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 150000]], [
        'stage' => 'pending', 'is_cod' => true, 'buyer_name' => 'Trần Thị Mai', 'buyer_phone' => '0912345789',
        'shipping_street' => '45 Lê Thánh Tôn', 'shipping_province_code' => '79', 'shipping_ward_code' => $this->ward->code, ...$extra,
    ])->assertCreated()->json('data.id');
});

it('connects with the shop token and lists its GHN shops as pickup points', function () {
    ($this->connect)('wrong')->assertStatus(422)->assertJsonPath('errors.credentials.0', 'GHN không nhận token này. Kiểm tra lại token API của shop.');
    $account = ($this->connect)()->assertCreated()->json('data');
    expect($account['name'])->toBe('GHN · ITSO Quận 10')->and($account['external_account'])->toBe('77001')->and($account['environment'])->toBe('sandbox')
        ->and($account['pickups'][0]['pickup']['id'])->toBe('885312');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/shop/all') && $r->hasHeader('Token', 'good'));
});

it('prices every GHN service for the order, finding the new ward in GHN address book', function () {
    ($this->connect)();
    $id = ($this->order)();
    $quote = $this->api->postJson('/api/v1/shipping/quotes', ['order_id' => $id])->assertOk()->json('data');
    $services = $quote['accounts'][0]['services'];
    expect(array_column($services, 'name'))->toBe(['Hàng nhẹ', 'Hàng nặng'])->and($services[0]['fee'])->toBe('22000.00')->and($services[0]['expected_at'])->not->toBeNull();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/shipping-order/fee') && $r['to_ward_code'] === '20101' && $r['to_district_id'] === 1442 && $r['weight'] === 600 && $r['cod_value'] === 300000 && $r->hasHeader('ShopId', '885312'));
});

it('creates the GHN order with our code, then follows webhooks, fee updates and cancels', function () {
    $account = ($this->connect)()->json('data');
    $id = ($this->order)();
    $res = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => '53320', 'service_name' => 'Hàng nhẹ', 'required_note' => 'no_view', 'payer' => 'buyer'])->assertCreated()->json('data');
    expect($res['tracking_number'])->toBe('GYKX7L9P')->and($res['carrier'])->toBe('ghn')->and($res['carrier_name'])->toBe('GHN')->and($res['fee_estimated'])->toBe('22000.00');
    $shipment = Shipment::withoutGlobalScopes()->where('tracking_number', 'GYKX7L9P')->sole();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/shipping-order/create') && $r['client_order_code'] === $shipment->client_code
        && $r['required_note'] === 'KHONGCHOXEMHANG' && $r['payment_type_id'] === 2 && $r['cod_amount'] === 300000 && $r['to_ward_code'] === '20101'
        && $r['items'][0]['weight'] === 300 && $r->hasHeader('ShopId', '885312'));

    $token = CarrierAccount::withoutGlobalScopes()->sole()->webhook_token;
    $hook = fn (array $b) => $this->postJson("/api/v1/carrier-webhooks/{$token}", ['ShopID' => 885312, 'OrderCode' => 'GYKX7L9P', 'ClientOrderCode' => $shipment->client_code, 'Time' => now()->toIso8601String(), ...$b])->assertOk();
    $hook(['Type' => 'switch_status', 'Status' => 'picked']);
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::HANDED_OVER);
    $hook(['Type' => 'update_fee', 'TotalFee' => 24500, 'Time' => now()->addMinute()->toIso8601String()]);
    $hook(['Type' => 'update_weight', 'ConvertedWeight' => 750, 'Time' => now()->addMinutes(2)->toIso8601String()]);
    $shipment->refresh();
    expect($shipment->status)->toBe('picked_up')->and($shipment->fee_actual)->toBe('24500.00')->and($shipment->weight_g)->toBe(750);
    $hook(['Type' => 'switch_status', 'Status' => 'delivered', 'Time' => now()->addDay()->toIso8601String()]);
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::DELIVERED);

    // Labels: GHN's own A5 page with a fresh print token.
    $url = $this->api->postJson('/api/v1/shipments/labels', ['ids' => [$shipment->ulid], 'size' => 'a5'])->assertOk()->json('data.url');
    expect($url)->toBe('https://dev-online-gateway.ghn.vn/a5/public-api/printA5?token=tok-123');
});

it('shows GHN refusals word for word and cancels at GHN before pickup', function () {
    $account = ($this->connect)()->json('data');
    $bad = ($this->order)(['buyer_phone' => '0000']);
    $this->api->postJson("/api/v1/orders/{$bad}/carrier-shipments", ['account_id' => $account['id'], 'service' => '53320'])
        ->assertStatus(422)->assertJsonPath('errors.carrier.0', 'GHN: Số điện thoại người nhận không hợp lệ');

    $id = ($this->order)();
    $shipment = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => '53320'])->assertCreated()->json('data');
    $this->api->postJson("/api/v1/shipments/{$shipment['id']}/carrier-cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/switch-status/cancel') && $r['order_codes'] === ['GYKX7L9P']);
});

it('reads the parcel history from GHN when asked', function () {
    $account = ($this->connect)()->json('data');
    $id = ($this->order)();
    $shipment = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => '53320'])->json('data');
    $this->api->postJson("/api/v1/shipments/{$shipment['id']}/carrier-refresh")->assertOk()->assertJsonPath('data.new_events', 2)->assertJsonPath('data.shipment.status', 'delivered');
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::DELIVERED);
});

it('tells the shop when GHN does not know the ward yet', function () {
    ($this->connect)();
    $other = Ward::query()->where('province_code', '79')->where('code', '!=', $this->ward->code)->orderBy('code')->firstOrFail();
    $quote = $this->api->postJson('/api/v1/shipping/quotes', ['province_code' => '79', 'ward_code' => $other->code])->assertOk()->json('data.accounts.0');
    expect($quote['error'])->toContain("GHN chưa có phường/xã \"{$other->name}\"");
});
