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
    expect(array_column($detail['events'], 'status'))->toBe(['picked_up', 'picked_up', 'delivered'])->and($detail['status_label'])->toBe('Giao thành công');
    expect($this->api->getJson("/api/v1/orders/{$id}")->json('data.shipments.0.id'))->toBe($shipment->ulid);
    $summary = $this->api->getJson('/api/v1/shipments/summary')->assertOk()->json('data');
    expect($summary['by_status'])->toBe(['delivered' => 1])->and($summary['cod_held'])->toBe([['carrier' => 'ghn', 'amount' => '300000.00', 'count' => 1]]);
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
