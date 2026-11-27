<?php

use App\Modules\Geo\Models\Ward;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Messaging\Models\PushSubscription;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Shipping\Jobs\CancelCarrierShipments;
use App\Modules\Shipping\Jobs\SendShipmentAlert;
use App\Modules\Shipping\Models\CarrierAccount;
use App\Modules\Shipping\Services\CarrierShipmentService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/** Vận chuyển menu: Cấu hình giao hàng (defaults, default carrier, automation), Tổng quan, phí ship lệch. Fake carrier. */
beforeEach(function () {
    config(['shipping.fake_enabled' => true]);
    Cache::flush();
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->variant = makeVariant($this->tenant, null, ['weight_gram' => 300]);
    stockIn($this->tenant, $this->variant, 20);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->ward = Ward::query()->where('province_code', '79')->orderBy('code')->firstOrFail();
    $this->order = fn () => createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 150000]], [
        'stage' => 'pending', 'is_cod' => true, 'buyer_name' => 'Trần Thị Mai', 'buyer_phone' => '0912345789',
        'shipping_street' => '45 Lê Thánh Tôn', 'shipping_province_code' => '79', 'shipping_ward_code' => $this->ward->code,
    ])->assertCreated()->json('data.id');
    $this->connect = fn () => $this->api->postJson('/api/v1/carrier-accounts', ['carrier' => 'fake', 'credentials' => ['token' => 'abc']])->assertCreated()->json('data');
    $this->push = fn (string $orderId, string $account) => $this->api->postJson("/api/v1/orders/{$orderId}/carrier-shipments", ['account_id' => $account, 'service' => 'standard'])->assertCreated()->json('data');
    $this->webhook = fn (array $body) => $this->postJson('/api/v1/carrier-webhooks/'.CarrierAccount::withoutGlobalScopes()->sole()->webhook_token, $body);
    $this->warehouse = fn () => Warehouse::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();
});

it('saves the shipping settings, checks the default carriers belong to the shop and fills new parcels from them', function () {
    $account = ($this->connect)();
    $settings = $this->api->getJson('/api/v1/tenant/settings')->assertOk()->json('data.shipping');
    expect($settings['weight_g'])->toBe(500)->and($settings['required_note'])->toBe('view_no_try')->and($settings['notify_problems'])->toBeTrue()->and($settings['cancel_with_order'])->toBeFalse();

    $warehouse = ($this->warehouse)();
    $this->api->putJson('/api/v1/tenant/settings/shipping', ['default_carriers' => [['warehouse_id' => $warehouse->ulid, 'account_id' => str_repeat('A', 26)]]])
        ->assertStatus(422)->assertJsonValidationErrors('default_carriers.0.account_id');
    $this->api->putJson('/api/v1/tenant/settings/shipping', ['weight_g' => 0])->assertStatus(422);

    $this->api->putJson('/api/v1/tenant/settings/shipping', [
        'required_note' => 'try', 'payer' => 'buyer', 'note' => 'Gọi trước khi giao', 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10,
        'default_carriers' => [['warehouse_id' => $warehouse->ulid, 'account_id' => $account['id']]],
    ])->assertOk()->assertJsonPath('data.shipping.payer', 'buyer');

    // Staff without settings rights cannot change it.
    actingInTenant(addMember($this->tenant, 'WAREHOUSE_STAFF'), $this->tenant)->putJson('/api/v1/tenant/settings/shipping', ['payer' => 'shop'])->assertForbidden();

    $quote = $this->api->postJson('/api/v1/shipping/quotes', ['order_id' => ($this->order)()])->assertOk()->json('data');
    expect($quote['preferred']['account_id'])->toBe($account['id'])->and($quote['preferred']['code'])->toBe('standard');

    $id = ($this->order)();
    ($this->push)($id, $account['id']);
    $shipment = Shipment::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($shipment->payer)->toBe('buyer')->and($shipment->length_cm)->toBe(20);
});

it('tells the phones when a parcel fails, only when it is switched on', function () {
    Queue::fake([SendShipmentAlert::class]);
    $account = ($this->connect)();
    TenantContext::run($this->tenant, fn () => PushSubscription::query()->forceCreate(['user_id' => $this->owner->id, 'endpoint' => 'https://push.example/x', 'endpoint_hash' => hash('sha256', 'https://push.example/x')]));

    $tracking = ($this->push)(($this->order)(), $account['id'])['tracking_number'];
    ($this->webhook)(['tracking' => $tracking, 'status' => 'delivery_fail', 'reason' => 'Khách không nghe máy', 'time' => now()->toIso8601String()])->assertOk();
    Queue::assertPushed(SendShipmentAlert::class, 1);
    $title = TenantContext::run($this->tenant, fn () => SendShipmentAlert::message(Shipment::query()->where('tracking_number', $tracking)->sole(), 'failed')[0]);
    expect($title)->toStartWith('Giao thất bại · ');

    $this->api->putJson('/api/v1/tenant/settings/shipping', ['notify_problems' => false])->assertOk();
    $tracking2 = ($this->push)(($this->order)(), $account['id'])['tracking_number'];
    ($this->webhook)(['tracking' => $tracking2, 'status' => 'delivery_fail', 'time' => now()->toIso8601String()])->assertOk();
    Queue::assertPushed(SendShipmentAlert::class, 1);
});

it('cancels the parcel at the carrier when the order is cancelled before pickup, only when it is switched on', function () {
    Queue::fake([CancelCarrierShipments::class]);
    $account = ($this->connect)();
    $id = ($this->order)();
    ($this->push)($id, $account['id']);
    $this->api->postJson("/api/v1/orders/{$id}/actions/cancel", ['reason' => 'Khách đổi ý'])->assertOk();
    Queue::assertNotPushed(CancelCarrierShipments::class);

    $this->api->putJson('/api/v1/tenant/settings/shipping', ['cancel_with_order' => true])->assertOk();
    $id2 = ($this->order)();
    $parcel = ($this->push)($id2, $account['id']);
    $this->api->postJson("/api/v1/orders/{$id2}/actions/cancel", ['reason' => 'Khách đổi ý'])->assertOk();
    Queue::assertPushed(CancelCarrierShipments::class, function (CancelCarrierShipments $job) {
        $job->handle(app(CarrierShipmentService::class));

        return true;
    });
    $shipment = Shipment::withoutGlobalScopes()->where('ulid', $parcel['id'])->sole();
    expect($shipment->status)->toBe('cancelled')
        ->and(ShipmentEvent::withoutGlobalScopes()->where('shipment_id', $shipment->id)->where('dedupe_key', 'auto_cancel:'.$parcel['tracking_number'])->exists())->toBeTrue();
});

it('shows the overview: parcels now, what needs the shop, each carrier, and the fee gap list', function () {
    $account = ($this->connect)();
    $a = ($this->push)(($this->order)(), $account['id']);
    $b = ($this->push)(($this->order)(), $account['id']);
    ($this->order)(); // confirmed later: not pushed yet
    ($this->webhook)(['tracking' => $a['tracking_number'], 'status' => 'picked', 'time' => now()->toIso8601String()])->assertOk();
    ($this->webhook)(['tracking' => $a['tracking_number'], 'status' => 'delivered', 'time' => now()->addHour()->toIso8601String(), 'fee' => 26000])->assertOk();
    ($this->webhook)(['tracking' => $b['tracking_number'], 'status' => 'delivery_fail', 'time' => now()->toIso8601String()])->assertOk();

    $data = $this->api->getJson('/api/v1/shipping/overview')->assertOk()->json('data');
    expect($data['now']['failed'])->toBe(1)->and($data['now']['cod_held']['count'])->toBe(1)->and($data['now']['cod_held']['amount'])->toBe('300000.00')
        ->and($data['now']['fee_gap'])->toBe(['count' => 1, 'amount' => '6000.00'])->and($data['total'])->toBe(2);
    expect($data['carriers'][0])->toMatchArray(['count' => 2, 'delivered_rate' => 100.0, 'avg_fee' => '23000.00']);
    expect(collect($data['days'])->sum('delivered'))->toBe(1)->and(collect($data['days'])->sum('problem'))->toBe(1);

    $gap = $this->api->getJson('/api/v1/shipments?fee=gap')->assertOk()->json('data');
    expect($gap)->toHaveCount(1)->and($gap[0]['id'])->toBe($a['id']);

    $this->api->getJson('/api/v1/shipping/overview?from=2026-01-01&to=2026-12-31')->assertStatus(422);
});
