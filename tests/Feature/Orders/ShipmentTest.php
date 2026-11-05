<?php

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use Illuminate\Support\Facades\DB;

/** Shipping round S2: every order that goes out with a carrier has a shipment (vận đơn) that follows it. */
beforeEach(function () {
    $this->travelTo(now()->setTime(5, 0));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->variant = makeVariant($this->tenant);
    stockIn($this->tenant, $this->variant, 20);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->shipmentOf = fn (string $orderId) => Shipment::withoutGlobalScopes()->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('ulid', $orderId))->sole();
});

it('opens a shipment at handover and follows the order to delivery, with the COD the carrier holds', function () {
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 150000]], ['stage' => 'confirmed', 'is_cod' => true])->assertCreated()->json('data.id');
    expect(Shipment::withoutGlobalScopes()->count())->toBe(0); // no tracking number, not handed over: nothing yet

    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/handover", ['carrier' => 'Giao Hàng Nhanh', 'tracking_number' => 'GHN1001'])->assertOk();
    $shipment = ($this->shipmentOf)($id);
    expect($shipment->status)->toBe('picked_up')->and($shipment->carrier)->toBe('ghn')->and($shipment->carrier_name)->toBe('Giao Hàng Nhanh')
        ->and($shipment->tracking_number)->toBe('GHN1001')->and($shipment->source)->toBe('manual')
        ->and($shipment->cod_amount)->toBe('300000.00')->and($shipment->cod_status)->toBe('pending')->and($shipment->picked_up_at)->not->toBeNull();

    // A typo corrected on the order is corrected on the shipment, with a step in its history.
    $this->api->putJson("/api/v1/orders/{$id}/meta", ['tracking_number' => 'GHN1002'])->assertOk();
    expect($shipment->refresh()->tracking_number)->toBe('GHN1002')
        ->and(ShipmentEvent::withoutGlobalScopes()->where('shipment_id', $shipment->id)->where('dedupe_key', 'tracking:GHN1002')->value('description'))->toContain('GHN1001 → GHN1002');

    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/deliver")->assertOk();
    $shipment->refresh();
    expect($shipment->status)->toBe('delivered')->and($shipment->cod_status)->toBe('collected')->and($shipment->delivered_at)->not->toBeNull();

    // Read API: list, search, detail with its history, and on the order.
    expect($this->api->getJson('/api/v1/shipments?q=GHN1002')->assertOk()->json('data.0.order.id'))->toBe($id);
    expect($this->api->getJson('/api/v1/shipments?status=failed')->json('data'))->toBe([]);
    $detail = $this->api->getJson("/api/v1/shipments/{$shipment->ulid}")->assertOk()->json('data');
    expect(array_column($detail['events'], 'status'))->toBe(['delivered', 'picked_up', 'picked_up'])->and($detail['status_label'])->toBe('Giao thành công');
    expect($this->api->getJson("/api/v1/orders/{$id}")->json('data.shipments.0.id'))->toBe($shipment->ulid);
    $summary = $this->api->getJson('/api/v1/shipments/summary')->assertOk()->json('data');
    expect($summary['by_status'])->toBe(['delivered' => 1])->and($summary['tabs']['delivered'])->toBe(1)->and($summary['cod_held'])->toBe([['carrier' => 'ghn', 'carrier_name' => 'Giao Hàng Nhanh', 'amount' => '300000.00', 'count' => 1]]);
});

it('counts every failed attempt, marks redelivery, and ends returned when the parcel is back', function () {
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 200000]], ['stage' => 'confirmed', 'is_cod' => true])->json('data.id');
    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/handover", ['carrier' => 'J&T Express', 'tracking_number' => 'JT1'])->assertOk();

    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/fail_delivery")->assertOk();
    $shipment = ($this->shipmentOf)($id);
    expect($shipment->status)->toBe('failed')->and($shipment->attempts)->toBe(1)->and($shipment->carrier)->toBe('jt');

    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/redeliver")->assertOk();
    expect($shipment->refresh()->status)->toBe('redelivering');
    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/fail_delivery")->assertOk();
    expect($shipment->refresh()->status)->toBe('failed')->and($shipment->attempts)->toBe(2);

    $return = OrderReturn::withoutGlobalScopes()->sole();
    $this->api->postJson("/api/v1/returns/{$return->ulid}/status", ['status' => 'received'])->assertOk();
    $line = $this->api->getJson("/api/v1/returns/{$return->ulid}")->json('data.lines.0.id');
    $this->api->postJson("/api/v1/returns/{$return->ulid}/inspect", ['warehouse_id' => defaultWarehouse($this->tenant)->ulid, 'lines' => [['line_id' => $line, 'received_qty' => 1, 'sellable_qty' => 1, 'damaged_qty' => 0]]])->assertOk();

    $shipment->refresh();
    expect($shipment->status)->toBe('returned')->and($shipment->cod_status)->toBe('returned')->and($shipment->returned_at)->not->toBeNull();
    expect(ShipmentEvent::withoutGlobalScopes()->where('shipment_id', $shipment->id)->orderBy('id')->pluck('status')->all())
        ->toBe(['picked_up', 'failed', 'redelivering', 'failed', 'returned']);
});

it('waits for pickup when the number is typed early, cancels with the order, and skips counter sales', function () {
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['stage' => 'confirmed', 'carrier' => 'Viettel Post', 'tracking_number' => 'VTP9'])->assertCreated()->json('data.id');
    $shipment = ($this->shipmentOf)($id);
    expect($shipment->status)->toBe('awaiting_pickup')->and($shipment->carrier)->toBe('viettelpost')->and($shipment->picked_up_at)->toBeNull();

    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/cancel", ['reason' => 'Khách hủy'])->assertOk();
    expect($shipment->refresh()->status)->toBe('cancelled')->and($shipment->cancelled_at)->not->toBeNull();

    // Sold at the counter and taken away: no parcel, no shipment.
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 100000]]])->assertCreated();
    expect(Shipment::withoutGlobalScopes()->count())->toBe(1);
});

it('gives marketplace orders a marketplace shipment, visible only to their own business', function () {
    $shop = fakeShop($this->tenant);
    $order = fakeOrder($shop, [[$this->variant, 1]], 'fake_shipped', ['tracking_number' => 'SPXVN01', 'carrier' => 'SPX Express', 'cod' => false]);
    $shipment = Shipment::withoutGlobalScopes()->where('order_id', $order->id)->sole();
    expect($shipment->source)->toBe('marketplace')->and($shipment->carrier)->toBe('spx')->and($shipment->status)->toBe('picked_up')
        ->and($shipment->cod_status)->toBe('none');

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->getJson("/api/v1/shipments/{$shipment->ulid}")->assertNotFound();
    expect(actingInTenant($otherOwner, $other)->getJson('/api/v1/shipments')->json('data'))->toBe([]);
});

it('fills in shipments for older orders without touching them, and only once', function () {
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 120000]], ['stage' => 'confirmed', 'is_cod' => true])->json('data.id');
    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/handover", ['carrier' => 'GHN', 'tracking_number' => 'OLD1'])->assertOk();
    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/deliver")->assertOk();
    // As before this round: the order exists, its shipment does not.
    DB::table('shipments')->delete();
    $before = (array) DB::table('orders')->where('ulid', $id)->first();

    $this->artisan('ecso:backfill-shipments')->expectsOutputToContain('Đã tạo 1 vận đơn')->assertSuccessful();
    $shipment = ($this->shipmentOf)($id);
    $order = Order::withoutGlobalScopes()->where('ulid', $id)->first();
    expect($shipment->status)->toBe('delivered')->and($shipment->tracking_number)->toBe('OLD1')->and($shipment->cod_status)->toBe('collected')
        ->and($shipment->picked_up_at?->equalTo($order->handed_over_at))->toBeTrue()
        ->and($shipment->delivered_at?->equalTo($order->delivered_at))->toBeTrue();
    expect((array) DB::table('orders')->where('ulid', $id)->first())->toBe($before);

    $this->artisan('ecso:backfill-shipments')->expectsOutputToContain('Đã tạo 0 vận đơn')->assertSuccessful();
    expect(Shipment::withoutGlobalScopes()->count())->toBe(1);
});

it('opens a shipment by hand, records the carrier steps through the order, and refuses it on marketplace orders', function () {
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 250000]], ['stage' => 'confirmed', 'is_cod' => true])->json('data.id');
    $this->api->postJson("/api/v1/orders/{$id}/shipments", ['mode' => 'manual', 'carrier' => 'GHN'])->assertUnprocessable()->assertJsonValidationErrors('tracking_number');

    $created = $this->api->postJson("/api/v1/orders/{$id}/shipments", ['mode' => 'manual', 'carrier' => 'GHN', 'tracking_number' => ' GHN77 ', 'weight_g' => 800, 'packages' => 1, 'fee_estimated' => 32000])
        ->assertCreated()->json('data');
    expect($created['status'])->toBe('awaiting_pickup')->and($created['tracking_number'])->toBe('GHN77')->and($created['fee_status'])->toBe('estimated')
        ->and($created['cod_amount'])->toBe('250000.00')->and(array_keys($created['actions']))->toBe(['pick_up', 'cancel']);
    $order = Order::withoutGlobalScopes()->where('ulid', $id)->first();
    expect($order->tracking_number)->toBe('GHN77')->and($order->shipping_carrier)->toBe('GHN');
    // One live shipment per order.
    $this->api->postJson("/api/v1/orders/{$id}/shipments", ['mode' => 'self'])->assertUnprocessable()->assertJsonValidationErrors('order');

    $sid = $created['id'];
    $this->api->postJson("/api/v1/shipments/{$sid}/actions/pick_up")->assertOk()->assertJsonPath('data.status', 'picked_up');
    expect($order->refresh()->status)->toBe('HANDED_OVER');
    $this->api->postJson("/api/v1/shipments/{$sid}/actions/fail", ['reason' => 'Không liên lạc được'])->assertOk()
        ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', 'Không liên lạc được');
    expect($order->refresh()->status)->toBe('FAILED_DELIVERY');
    $this->api->postJson("/api/v1/shipments/{$sid}/actions/return", ['reason' => 'Khách từ chối'])->assertOk()->assertJsonPath('data.status', 'returning');
    expect($order->refresh()->status)->toBe('FAILED_DELIVERY'); // the return on its way closes the order once inspected
    $this->api->postJson("/api/v1/shipments/{$sid}/actions/deliver")->assertUnprocessable();

    $detail = $this->api->getJson("/api/v1/shipments/{$sid}")->assertOk()->json('data');
    expect($detail['events'][0]['status'])->toBe('returning')->and($detail['events'][0]['description'])->toBe('Khách từ chối')
        ->and($detail['events'][1]['description'])->toBe('Không liên lạc được')->and($detail['returns'])->toHaveCount(1);

    // Marketplace orders: the marketplace makes the shipment and reports its steps.
    $mk = fakeOrder(fakeShop($this->tenant), [[$this->variant, 1]], 'fake_shipped', ['tracking_number' => 'SPX1', 'carrier' => 'SPX Express']);
    $this->api->postJson("/api/v1/orders/{$mk->ulid}/shipments", ['mode' => 'self'])->assertUnprocessable();
    $mks = Shipment::withoutGlobalScopes()->where('order_id', $mk->id)->sole();
    $actions = $this->api->getJson("/api/v1/shipments/{$mks->ulid}")->json('data.actions');
    expect($actions['deliver']['allowed'])->toBeFalse()->and($actions['deliver']['reason'])->toContain('Đơn sàn');
    $this->api->postJson("/api/v1/shipments/{$mks->ulid}/actions/deliver")->assertUnprocessable();
    $this->api->putJson("/api/v1/shipments/{$mks->ulid}", ['tracking_number' => 'X'])->assertUnprocessable();
    $this->api->putJson("/api/v1/shipments/{$mks->ulid}", ['weight_g' => 500])->assertOk()->assertJsonPath('data.weight_g', 500);
});

it('cancels a shipment not picked up yet so a new one can be made, and edits the tracking number', function () {
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['stage' => 'confirmed'])->json('data.id');
    $sid = $this->api->postJson("/api/v1/orders/{$id}/shipments", ['mode' => 'manual', 'carrier' => 'GHN', 'tracking_number' => 'G1'])->json('data.id');
    $this->api->putJson("/api/v1/shipments/{$sid}", ['tracking_number' => 'G2', 'carrier' => 'J&T Express'])->assertOk()
        ->assertJsonPath('data.tracking_number', 'G2')->assertJsonPath('data.carrier', 'jt');
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('tracking_number'))->toBe('G2');

    $this->api->postJson("/api/v1/shipments/{$sid}/actions/cancel", ['reason' => 'Tạo nhầm hãng'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(Order::withoutGlobalScopes()->where('ulid', $id)->value('tracking_number'))->toBeNull();

    // Own shipper: no tracking number needed.
    $self = $this->api->postJson("/api/v1/orders/{$id}/shipments", ['mode' => 'self'])->assertCreated()->json('data');
    expect($self['source'])->toBe('self')->and($self['carrier_name'])->toBe('Tự giao')->and($self['tracking_number'])->toBeNull();
    $order = $this->api->getJson("/api/v1/orders/{$id}")->json('data');
    expect(array_column($order['shipments'], 'status'))->toBe(['cancelled', 'awaiting_pickup']);

    // Page data: tabs, late parcels, filters.
    $this->travel(6)->days();
    $this->api->postJson("/api/v1/shipments/{$self['id']}/actions/pick_up")->assertOk();
    $this->travel(6)->days();
    $summary = $this->api->getJson('/api/v1/shipments/summary')->assertOk()->json('data');
    expect($summary['tabs']['delivering'])->toBe(1)->and($summary['tabs']['cancelled'])->toBe(1)->and($summary['tabs']['all'])->toBe(2)->and($summary['late'])->toBe(1);
    expect($this->api->getJson('/api/v1/shipments?late=1')->json('data.0.id'))->toBe($self['id']);
    expect($this->api->getJson('/api/v1/shipments?tab=cancelled')->json('data.0.id'))->toBe($sid);
    expect($this->api->getJson('/api/v1/shipments?carrier=other&tab=delivering')->json('data'))->toHaveCount(1);
});
