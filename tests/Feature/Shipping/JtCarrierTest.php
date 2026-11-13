<?php

use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Shipping\Connectors\Jt\JtClient;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** J&T Express on its open platform, simulated with Http::fake: nothing leaves the test. */
beforeEach(function () {
    Cache::flush();
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->variant = makeVariant($this->tenant, null, ['weight_gram' => 300]);
    stockIn($this->tenant, $this->variant, 20);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->ward = Ward::query()->where('province_code', '79')->orderBy('code')->firstOrFail();
    $this->province = Province::query()->where('code', '79')->firstOrFail();
    Warehouse::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['address' => '120 Ba Tháng Hai', 'province_code' => '79', 'ward_code' => $this->ward->code]);
    $this->created = 0;
    $this->lostAnswer = false;
    $base = 'https://demoopenapi.jtexpress.vn/webopenplatformapi/api/';
    $biz = fn (Request $r) => json_decode($r['bizContent'], true);
    // J&T checks both signatures; a wrong private key or password gets its "bad key" code.
    $signed = fn (Request $r) => $r->hasHeader('apiAccount', '178337126125932605')
        && $r->header('digest')[0] === JtClient::headerDigest($r['bizContent'], 'pk-123')
        && $biz($r)['digest'] === JtClient::businessDigest('084LC12345', 'api-pass', 'pk-123');
    $ok = fn ($data) => Http::response(['code' => '1', 'msg' => 'success', 'data' => $data]);
    Http::preventStrayRequests();
    Http::fake([
        "{$base}order/cancelOrder" => fn (Request $r) => ! $signed($r)
            ? Http::response(['code' => '145003030', 'msg' => 'headers digest is invalid', 'data' => null])
            : (str_starts_with($biz($r)['txlogisticId'], 'EZCHECK') ? Http::response(['code' => '999002000', 'msg' => 'Đơn hàng không tồn tại', 'data' => null]) : $ok(['txlogisticId' => $biz($r)['txlogisticId']])),
        "{$base}spmComCost/getComCost" => $ok(['totalFee' => 28500]),
        "{$base}order/addOrder" => function (Request $r) use ($ok, $biz, $signed) {
            if (! $signed($r)) {
                return Http::response(['code' => '145003030', 'msg' => 'digest invalid', 'data' => null]);
            }
            if ($biz($r)['receiver']['mobile'] === '0000') {
                return Http::response(['code' => '145003056', 'msg' => 'Số điện thoại người nhận không hợp lệ', 'data' => null]);
            }
            if ($this->lostAnswer) {
                return Http::response('', 504);
            }

            return $ok(['billCode' => '850'.(1234567 + $this->created++), 'txlogisticId' => $biz($r)['txlogisticId'], 'sumFreight' => 28500]);
        },
        "{$base}order/getOrders" => fn (Request $r) => $ok([['billCode' => '850099999', 'txlogisticId' => $biz($r)['serialNumber'][0]]]),
        "{$base}order/printOrder" => $ok(['base64EncodeContent' => base64_encode('%PDF-1.4 label')]),
        "{$base}logistics/trace" => $ok([['billCode' => '8501234567', 'details' => [
            ['scanTime' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'), 'scanTypeCode' => '100', 'scanType' => 'Ký nhận', 'desc' => 'Người nhận: Mai'],
        ]]]),
    ]);
    $this->creds = ['customer_code' => '084LC12345', 'api_account' => '178337126125932605', 'private_key' => 'pk-123', 'api_password' => 'api-pass'];
    $this->connect = fn (array $override = []) => $this->api->postJson('/api/v1/carrier-accounts', ['carrier' => 'jt', 'environment' => 'sandbox', 'credentials' => [...$this->creds, ...$override]]);
    $this->order = fn (array $extra = []) => createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 150000]], [
        'stage' => 'pending', 'is_cod' => true, 'buyer_name' => 'Trần Thị Mai', 'buyer_phone' => '0912345789',
        'shipping_street' => '45 Lê Thánh Tôn', 'shipping_province_code' => '79', 'shipping_ward_code' => $this->ward->code, ...$extra,
    ])->assertCreated()->json('data.id');
    $this->hook = function (array $data, ?string $digest = null) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $this->withHeaders(['digest' => $digest ?? JtClient::headerDigest($json, 'pk-123')])
            ->post('/api/v1/carrier-webhooks/'.CarrierAccount::withoutGlobalScopes()->sole()->webhook_token, ['bizContent' => $json])->assertOk();
    };
});

it('checks the four keys with J&T and uses the shop warehouses as pickup points', function () {
    ($this->connect)(['private_key' => 'wrong'])->assertStatus(422)->assertJsonPath('errors.credentials.0', fn ($m) => str_contains($m, 'J&T không nhận thông tin kết nối'));
    ($this->connect)(['api_password' => ''])->assertStatus(422);
    $account = ($this->connect)()->assertCreated()->json('data');
    expect($account['name'])->toBe('J&T · 084LC12345')->and($account['external_account'])->toBe('084LC12345')
        ->and($account['pickups'][0]['pickup']['address'])->toBe('120 Ba Tháng Hai');
    $this->api->postJson("/api/v1/carrier-accounts/{$account['id']}/check")->assertOk()->assertJsonPath('data.status', 'connected');
});

it('prices, creates with our code and the COD, prints the label through a signed link, follows the webhook and cancels', function () {
    $account = ($this->connect)()->json('data');
    $id = ($this->order)();
    $services = $this->api->postJson('/api/v1/shipping/quotes', ['order_id' => $id])->assertOk()->json('data.accounts.0.services');
    expect($services)->toHaveCount(1)->and($services[0]['fee'])->toBe('28500.00');

    $res = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'EZ', 'payer' => 'buyer', 'required_note' => 'try'])->assertCreated()->json('data');
    expect($res['tracking_number'])->toBe('8501234567')->and($res['fee_estimated'])->toBe('28500.00');
    $shipment = Shipment::withoutGlobalScopes()->where('tracking_number', '8501234567')->sole();
    $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), 'order/addOrder'))->last();
    $b = json_decode($sent[0]['bizContent'], true);
    expect($b['txlogisticId'])->toBe($shipment->client_code)->and($b['customerCode'])->toBe('084LC12345')->and($b['payType'])->toBe('CC_CASH')
        ->and($b['itemsValue'])->toBe(300000)->and($b['weight'])->toBe('0.60')->and($b['receiver']['prov'])->toBe($this->province->name)
        ->and($b['receiver']['area'])->toBe($this->ward->name)->and($b['receiver']['address'])->toBe('45 Lê Thánh Tôn')
        ->and($b['sender']['address'])->toBe('120 Ba Tháng Hai')->and($b['remark'])->toStartWith('Cho thử hàng');

    $url = $this->api->postJson('/api/v1/shipments/labels', ['ids' => [$shipment->ulid]])->assertOk()->json('data.url');
    expect($url)->toContain('/carrier-labels/')->toContain('signature=');
    expect($this->get($url)->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');
    $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertForbidden();

    $at = fn (int $m) => now('Asia/Ho_Chi_Minh')->addMinutes($m)->format('Y-m-d H:i:s');
    ($this->hook)(['billCode' => '8501234567', 'txlogisticId' => $shipment->client_code, 'details' => [['scanTime' => $at(1), 'scanTypeCode' => '10', 'scanType' => 'Nhận hàng']]])
        ->assertJsonPath('code', '1');
    expect($shipment->refresh()->status)->toBe('picked_up')
        ->and(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(OrderStatus::HANDED_OVER);
    // A push with a wrong signature changes nothing.
    ($this->hook)(['billCode' => '8501234567', 'details' => [['scanTime' => $at(2), 'scanTypeCode' => '100', 'scanType' => 'Ký nhận']]], 'forged');
    expect($shipment->refresh()->status)->toBe('picked_up');
    ($this->hook)(['billCode' => '8501234567', 'details' => [['scanTime' => $at(3), 'scanType' => 'Phát hàng']]]);
    expect($shipment->refresh()->status)->toBe('out_for_delivery');

    $other = ($this->order)();
    $second = $this->api->postJson("/api/v1/orders/{$other}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'EZ'])->assertCreated()->json('data');
    $this->api->postJson("/api/v1/shipments/{$second['id']}/carrier-cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'order/cancelOrder') && ! str_starts_with(json_decode($r['bizContent'], true)['txlogisticId'], 'EZCHECK'));
});

it('shows refusals word for word and finds the parcel by our code after a lost answer', function () {
    $account = ($this->connect)()->json('data');
    $bad = ($this->order)(['buyer_phone' => '0000']);
    $this->api->postJson("/api/v1/orders/{$bad}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'EZ'])
        ->assertStatus(422)->assertJsonPath('errors.carrier.0', 'J&T: Số điện thoại người nhận không hợp lệ');

    $this->lostAnswer = true;
    $id = ($this->order)();
    $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'EZ'])
        ->assertCreated()->assertJsonPath('data.tracking_number', '850099999');
    $shipment = Shipment::withoutGlobalScopes()->where('tracking_number', '850099999')->sole();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'order/getOrders') && json_decode($r['bizContent'], true)['serialNumber'] === [$shipment->client_code]);
});

it('reads the parcel history from J&T when asked', function () {
    $account = ($this->connect)()->json('data');
    $id = ($this->order)();
    $res = $this->api->postJson("/api/v1/orders/{$id}/carrier-shipments", ['account_id' => $account['id'], 'service' => 'EZ'])->assertCreated()->json('data');
    $this->api->postJson("/api/v1/shipments/{$res['id']}/carrier-refresh")->assertOk();
    expect(Shipment::withoutGlobalScopes()->where('ulid', $res['id'])->value('status'))->toBe('delivered');
});
