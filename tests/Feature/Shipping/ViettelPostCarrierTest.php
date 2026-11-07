<?php

use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Viettel Post against its partner API as other integrations use it, simulated with Http::fake: nothing leaves the test. */
beforeEach(function () {
    Cache::flush();
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->variant = makeVariant($this->tenant, null, ['weight_gram' => 300]);
    stockIn($this->tenant, $this->variant, 20);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->ward = Ward::query()->where('province_code', '79')->orderBy('code')->firstOrFail();
    $this->province = Province::query()->where('code', '79')->firstOrFail();
    $this->lostAnswer = false;
    $this->created = 0;
    $base = 'https://partnerdev.viettelpost.vn';
    $ok = fn ($data, string $message = 'OK') => Http::response(['status' => 200, 'error' => false, 'message' => $message, 'data' => $data]);
    Http::preventStrayRequests();
    Http::fake([
        "{$base}/v2/user/Login" => fn (Request $r) => $r['PASSWORD'] === 'secret'
            ? $ok(['userId' => 1, 'token' => 'short-tok', 'phone' => $r['USERNAME']])
            : Http::response(['status' => 205, 'error' => true, 'message' => 'Sai tên đăng nhập hoặc mật khẩu', 'data' => null]),
        "{$base}/v2/user/ownerconnect" => fn (Request $r) => $r->hasHeader('Token', 'short-tok') ? $ok(['token' => 'long-tok']) : Http::response([], 401),
        "{$base}/v2/user/listInventory" => fn (Request $r) => $r->hasHeader('Token', 'long-tok')
            ? $ok([['groupaddressId' => 5818802, 'cusId' => 722, 'name' => 'Kho Quận 10', 'phone' => '0909123456', 'address' => '120 Ba Tháng Hai, Phường Diên Hồng, TP Hồ Chí Minh']])
            : Http::response(['status' => 401, 'error' => true, 'message' => 'Token invalid', 'data' => null]),
        "{$base}/v2/order/getPriceAllNlp" => Http::response([
            ['MA_DV_CHINH' => 'VHT', 'TEN_DICHVU' => 'Hỏa tốc', 'GIA_CUOC' => 45000, 'THOI_GIAN' => '12 giờ'],
            ['MA_DV_CHINH' => 'LCOD', 'TEN_DICHVU' => 'TMĐT Tiết kiệm', 'GIA_CUOC' => 21000, 'THOI_GIAN' => '48 giờ'],
        ]),
        "{$base}/v2/order/createOrderNlp" => function (Request $r) use ($ok) {
            if ($r['RECEIVER_PHONE'] === '0000') {
                return Http::response(['status' => 204, 'error' => true, 'message' => 'Số điện thoại người nhận không hợp lệ', 'data' => null]);
            }
            if ($this->lostAnswer) {
                return Http::response('', 504);
            }

            return $ok(['ORDER_NUMBER' => (string) (1727390123456 + $this->created++), 'MONEY_TOTAL' => 21000, 'KPI_HT' => 48]);
        },
        "{$base}/v2/order/UpdateOrder" => $ok(null),
        "{$base}/v2/order/printing-code" => $ok(null, 'PRINT-abc'),
        "{$base}/v3/order/detail-v2*" => $ok(['ORDER_NUMBER' => '1727390123456', 'ORDER_STATUS' => 501, 'ORDER_STATUSDATE' => now('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s'), 'STATUS_NAME' => 'Giao thành công']),
    ]);
    $this->connect = fn (string $password = 'secret') => $this->api->postJson('/api/v1/carrier-accounts', ['carrier' => 'viettelpost', 'environment' => 'sandbox', 'credentials' => ['username' => '0909123456', 'password' => $password]]);
    $this->order = fn (array $extra = []) => createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 150000]], [
        'stage' => 'pending', 'is_cod' => true, 'buyer_name' => 'Trần Thị Mai', 'buyer_phone' => '0912345789',
        'shipping_street' => '45 Lê Thánh Tôn', 'shipping_province_code' => '79', 'shipping_ward_code' => $this->ward->code, ...$extra,
    ])->assertCreated()->json('data.id');
    $this->hook = fn (array $data) => $this->postJson('/api/v1/carrier-webhooks/'.CarrierAccount::withoutGlobalScopes()->sole()->webhook_token, ['DATA' => $data, 'TOKEN' => 'x'])->assertOk();
});

it('logs in with phone and password, keeps only the long-lived token and lists the pickup points', function () {
    ($this->connect)('wrong')->assertStatus(422)->assertJsonPath('errors.credentials.0', 'Viettel Post: Sai tên đăng nhập hoặc mật khẩu');
    $account = ($this->connect)()->assertCreated()->json('data');
    expect($account['name'])->toBe('Viettel Post · Kho Quận 10')->and($account['external_account'])->toBe('0909123456')
        ->and($account['pickups'][0]['pickup']['id'])->toBe('5818802');
    $stored = CarrierAccount::withoutGlobalScopes()->sole()->credentials;
    expect($stored)->toBe(['token' => 'long-tok', 'username' => '0909123456']);

    // "Kiểm tra kết nối" uses the kept token, no password needed.
    $this->api->postJson("/api/v1/carrier-accounts/{$account['id']}/check")->assertOk()->assertJsonPath('data.status', 'connected');
    Http::assertSentCount(5);
});

it('prices every Viettel Post service with the addresses as text', function () {
    ($this->connect)();
    $id = ($this->order)();
    $services = $this->api->postJson('/api/v1/shipping/quotes', ['order_id' => $id])->assertOk()->json('data.accounts.0.services');
    expect(array_column($services, 'name'))->toBe(['TMĐT Tiết kiệm', 'Hỏa tốc'])->and($services[0]['fee'])->toBe('21000.00')->and($services[0]['expected_at'])->not->toBeNull();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getPriceAllNlp') && $r->hasHeader('Token', 'long-tok')
        && str_contains($r['RECEIVER_ADDRESS'], '45 Lê Thánh Tôn') && str_contains($r['RECEIVER_ADDRESS'], $this->province->name)
        && str_starts_with($r['SENDER_ADDRESS'], '120 Ba Tháng Hai') && $r['PRODUCT_WEIGHT'] === 600 && $r['MONEY_COLLECTION'] === 300000);

    // From the order form (only the ward is picked yet): ward and province names make the address.
    $quote = $this->api->postJson('/api/v1/shipping/quotes', ['province_code' => '79', 'ward_code' => $this->ward->code])->assertOk()->json('data.accounts.0');
    expect($quote['services'])->toHaveCount(2);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getPriceAllNlp') && $r['RECEIVER_ADDRESS'] === $this->ward->name.', '.$this->province->name);
});

it('creates the order with our code, follows webhooks, prints labels and cancels', function () {
    $account = ($this->connect)()->json('data');
    $id = ($this->order)();
    $res = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'LCOD', 'service_name' => 'TMĐT Tiết kiệm', 'required_note' => 'no_view', 'payer' => 'buyer'])->assertCreated()->json('data');
    expect($res['tracking_number'])->toBe('1727390123456')->and($res['carrier_name'])->toBe('Viettel Post')->and($res['fee_estimated'])->toBe('21000.00');
    $shipment = Shipment::withoutGlobalScopes()->where('tracking_number', '1727390123456')->sole();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/createOrderNlp') && $r['ORDER_NUMBER'] === $shipment->client_code && $r['CHECK_UNIQUE'] === true
        && $r['GROUPADDRESS_ID'] === 5818802 && $r['CUS_ID'] === 722 && $r['ORDER_PAYMENT'] === 2 && $r['MONEY_COLLECTION'] === 300000 && $r['ORDER_SERVICE'] === 'LCOD'
        && str_starts_with($r['ORDER_NOTE'], 'Không cho xem hàng') && $r['LIST_ITEM'][0]['PRODUCT_WEIGHT'] === 300);

    $at = fn (int $minutes) => now('Asia/Ho_Chi_Minh')->addMinutes($minutes)->format('d/m/Y H:i:s');
    ($this->hook)(['ORDER_NUMBER' => '1727390123456', 'ORDER_REFERENCE' => $shipment->client_code, 'ORDER_STATUS' => 105, 'ORDER_STATUSDATE' => $at(1), 'STATUS_NAME' => 'Bưu tá đã nhận hàng', 'PRODUCT_WEIGHT' => 650, 'MONEY_TOTAL' => 23000]);
    ($this->hook)(['ORDER_NUMBER' => '1727390123456', 'ORDER_STATUS' => 105, 'ORDER_STATUSDATE' => $at(1)]); // repeated: counted once
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::HANDED_OVER);
    $shipment->refresh();
    expect($shipment->status)->toBe('picked_up')->and($shipment->weight_g)->toBe(650)->and($shipment->fee_actual)->toBe('23000.00')
        ->and(ShipmentEvent::withoutGlobalScopes()->where('shipment_id', $shipment->id)->where('raw_status', '105')->count())->toBe(1);

    $url = $this->api->postJson('/api/v1/shipments/labels', ['ids' => [$shipment->ulid], 'size' => 'a5'])->assertOk()->json('data.url');
    expect($url)->toBe('https://dev-print.viettelpost.vn/DigitalizePrint/report.do?type=1&bill=PRINT-abc&showPostage=1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/printing-code') && $r['ORDER_ARRAY'] === ['1727390123456']);

    ($this->hook)(['ORDER_NUMBER' => '1727390123456', 'ORDER_STATUS' => 501, 'ORDER_STATUSDATE' => $at(60 * 24), 'MONEY_COLLECTION' => 300000]);
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::DELIVERED)
        ->and($shipment->refresh()->cod_collected)->toBe('300000.00');

    $other = ($this->order)();
    $second = $this->api->postJson("/api/v1/orders/{$other}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'LCOD'])->assertCreated()->json('data');
    $this->api->postJson("/api/v1/shipments/{$second['id']}/carrier-cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/UpdateOrder') && $r['TYPE'] === 4 && $r['ORDER_NUMBER'] === '1727390123457');
});

it('marks a failed delivery from the webhook', function () {
    $account = ($this->connect)()->json('data');
    $id = ($this->order)();
    $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'LCOD'])->assertCreated();
    ($this->hook)(['ORDER_NUMBER' => '1727390123456', 'ORDER_STATUS' => 506, 'ORDER_STATUSDATE' => now('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s'), 'NOTE' => 'Gọi không nghe máy']);
    $shipment = Shipment::withoutGlobalScopes()->where('tracking_number', '1727390123456')->sole();
    expect($shipment->status)->toBe('failed')->and($shipment->failure_reason)->toBe('Khách không có nhà: Gọi không nghe máy')
        ->and(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::FAILED_DELIVERY);
});

it('shows refusals word for word and takes the number from the first webhook after a lost answer', function () {
    $account = ($this->connect)()->json('data');
    $bad = ($this->order)(['buyer_phone' => '0000']);
    $this->api->postJson("/api/v1/orders/{$bad}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'LCOD'])
        ->assertStatus(422)->assertJsonPath('errors.carrier.0', 'Viettel Post: Số điện thoại người nhận không hợp lệ');

    $this->lostAnswer = true;
    $id = ($this->order)();
    $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'LCOD'])->assertStatus(422);
    $shipment = Shipment::withoutGlobalScopes()->whereNull('tracking_number')->where('status', 'pending')->whereNotNull('client_code')->latest('id')->firstOrFail();
    ($this->hook)(['ORDER_NUMBER' => '1727390999999', 'ORDER_REFERENCE' => $shipment->client_code, 'ORDER_STATUS' => 102, 'ORDER_STATUSDATE' => now('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s')]);
    $shipment->refresh();
    expect($shipment->tracking_number)->toBe('1727390999999')->and($shipment->status)->toBe('awaiting_pickup')->and($shipment->api_error)->toBeNull()
        ->and(Order::withoutGlobalScopes()->where('ulid', $id)->value('tracking_number'))->toBe('1727390999999');
});

it('reads the current state from Viettel Post when asked', function () {
    $account = ($this->connect)()->json('data');
    $id = ($this->order)();
    $shipment = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'LCOD'])->json('data');
    $this->api->postJson("/api/v1/shipments/{$shipment['id']}/carrier-refresh")->assertOk()->assertJsonPath('data.shipment.status', 'delivered');
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::DELIVERED);
});
