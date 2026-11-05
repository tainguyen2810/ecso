<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Support\Tracking;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeWooStore;

/** Shipping round S1: tracking numbers, failed delivery and redelivery, COD statements that prove delivery. */
beforeEach(function () {
    $this->travelTo(now()->setTime(5, 0));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->variant = makeVariant($this->tenant);
    stockIn($this->tenant, $this->variant, 20);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->handedOver = function (string $tracking, array $extra = []): string {
        $id = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 200000]], ['stage' => 'confirmed', 'is_cod' => true] + $extra)->assertCreated()->json('data.id');
        $this->api->postJson("/api/v1/sales-orders/{$id}/actions/handover", ['carrier' => 'GHN', 'tracking_number' => $tracking])->assertOk();

        return $id;
    };
});

it('refuses a tracking number already on another live order, and lets the shop correct it after handover', function () {
    $a = ($this->handedOver)('GHN500');
    $b = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['stage' => 'confirmed'])->assertCreated()->json('data.id');

    $this->api->postJson("/api/v1/sales-orders/{$b}/actions/handover", ['carrier' => 'GHN', 'tracking_number' => ' GHN500 '])
        ->assertUnprocessable()->assertJsonValidationErrors('tracking_number');
    expect(Order::withoutGlobalScopes()->where('ulid', $b)->value('status'))->toBe('READY_TO_PACK');
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['tracking_number' => 'GHN500'])->assertUnprocessable()->assertJsonValidationErrors('tracking_number');

    // Typed wrong at handover: corrected on the order, with the change in the audit log.
    $this->api->putJson("/api/v1/orders/{$a}/meta", ['carrier' => 'J&T Express', 'tracking_number' => 'JT777'])->assertOk()
        ->assertJsonPath('data.carrier', 'J&T Express')->assertJsonPath('data.tracking_number', 'JT777');
    $log = AuditLog::withoutGlobalScopes()->where('action', 'order.meta_updated')->latest('id')->first();
    expect($log->changes['shipping']['before']['tracking_number'] ?? null)->toBe('GHN500');
    // The old number is free again.
    $this->api->postJson("/api/v1/sales-orders/{$b}/actions/handover", ['tracking_number' => 'GHN500'])->assertOk();

    // Another business may use the same number.
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $v = makeVariant($other);
    stockIn($other, $v, 2);
    createSalesOrder($otherOwner, $other, [[$v, 1]], ['tracking_number' => 'GHN500'])->assertCreated();

    // A marketplace order's tracking number comes from the marketplace.
    $shop = fakeShop($this->tenant);
    $mk = fakeOrder($shop, [[$this->variant, 1]]);
    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/orders/{$mk->ulid}/meta", ['tracking_number' => 'X1'])->assertUnprocessable();
});

it('redelivers after a failed delivery, withdraws the automatic return, and reopens it on a second failure', function () {
    $id = ($this->handedOver)('GHN600');
    $warehouse = defaultWarehouse($this->tenant);
    $onHand = fn () => balanceOf($warehouse, $this->variant)->on_hand;
    expect($onHand())->toBe(19);

    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/fail_delivery", ['reason' => 'Khách hẹn hôm khác'])->assertOk()->assertJsonPath('data.status', 'FAILED_DELIVERY');
    $return = OrderReturn::withoutGlobalScopes()->sole();
    expect($return->status)->toBe('in_transit')->and($return->type)->toBe('failed_delivery');

    $actions = $this->api->getJson("/api/v1/orders/{$id}")->json('data.actions');
    expect($actions['redeliver']['allowed'])->toBeTrue()->and($actions['deliver']['allowed'])->toBeTrue();

    // Giao lại: the parcel goes out again, the return is withdrawn, no stock moves.
    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/redeliver")->assertOk()->assertJsonPath('data.status', 'IN_TRANSIT');
    expect($return->refresh()->status)->toBe('rejected')->and($onHand())->toBe(19);

    // Fails again: the same return is on its way back once more (not a second one).
    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/fail_delivery")->assertOk();
    expect(OrderReturn::withoutGlobalScopes()->count())->toBe(1)->and($return->refresh()->status)->toBe('in_transit');

    // The parcel is back: received and inspected, it goes back on the shelf and the order is closed as returned.
    $this->api->postJson("/api/v1/returns/{$return->ulid}/status", ['status' => 'received'])->assertOk();
    $line = $this->api->getJson("/api/v1/returns/{$return->ulid}")->json('data.lines.0.id');
    $this->api->postJson("/api/v1/returns/{$return->ulid}/inspect", ['warehouse_id' => $warehouse->ulid, 'lines' => [['line_id' => $line, 'received_qty' => 1, 'sellable_qty' => 1, 'damaged_qty' => 0]]])->assertOk();
    expect($onHand())->toBe(20)->and(Order::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe('RETURNED');
    expect(collect($this->api->getJson("/api/v1/orders/{$id}")->json('data.history'))->last()['to'])->toBe('RETURNED');
});

it('marks a failed delivery delivered when the carrier delivered on its second try', function () {
    $id = ($this->handedOver)('GHN601');
    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/fail_delivery")->assertOk();
    $this->api->postJson("/api/v1/sales-orders/{$id}/actions/deliver")->assertOk()->assertJsonPath('data.status', 'DELIVERED');
    expect(OrderReturn::withoutGlobalScopes()->sole()->status)->toBe('rejected')
        ->and(Order::withoutGlobalScopes()->where('ulid', $id)->value('delivered_at'))->not->toBeNull();
});

it('marks orders delivered from a COD statement only when asked, and tells apart orders sharing a tracking number', function () {
    $a = ($this->handedOver)('GHN700');
    $b = ($this->handedOver)('GHN701');
    $preview = $this->api->postJson('/api/v1/cod-reconciliations/preview', ['carrier' => 'ghn', 'lines' => [['tracking' => 'GHN700', 'cod' => 200000], ['tracking' => 'GHN701', 'cod' => 200000]]])->assertOk()->json('data.lines');
    expect(array_column($preview, 'deliverable'))->toBe([true, true])->and($preview[0]['order']['status'])->toBe('HANDED_OVER');

    // Without the flag: money booked, orders stay on their way.
    postOnce($this->owner, $this->tenant, '/api/v1/cod-reconciliations', ['carrier' => 'ghn', 'lines' => [['tracking' => 'GHN700', 'cod' => 200000, 'fee' => 20000]]], 'cod-s1')->assertCreated();
    expect(Order::withoutGlobalScopes()->where('ulid', $a)->value('status'))->toBe('HANDED_OVER');
    // With it: delivered, so the revenue counts.
    postOnce($this->owner, $this->tenant, '/api/v1/cod-reconciliations', ['carrier' => 'ghn', 'mark_delivered' => true, 'lines' => [['tracking' => 'GHN701', 'cod' => 200000, 'fee' => 20000]]], 'cod-s2')->assertCreated();
    $order = Order::withoutGlobalScopes()->where('ulid', $b)->first();
    expect($order->status)->toBe('DELIVERED')->and($order->payment_status)->toBe('paid');

    // Two orders carrying the same number (typed before the check existed): the statement's carrier decides,
    // otherwise the line is not booked at all.
    $c = ($this->handedOver)('X900');
    $d = ($this->handedOver)('X901', []);
    DB::table('orders')->where('ulid', $d)->update(['tracking_number' => 'X900', 'shipping_carrier' => 'Viettel Post']);
    $line = fn (?string $carrier) => $this->api->postJson('/api/v1/cod-reconciliations/preview', array_filter(['carrier' => $carrier, 'lines' => [['tracking' => 'X900', 'cod' => 200000]]]))->json('data.lines.0');
    expect($line('viettelpost')['order']['id'])->toBe($d)->and($line('ghn')['order']['id'])->toBe($c);
    $unknown = $line(null);
    expect($unknown['status'])->toBe('not_found')->and($unknown['note'])->toContain('nhiều đơn');
    // A single order whose carrier differs from the statement's is still matched, with a note.
    expect($this->api->postJson('/api/v1/cod-reconciliations/preview', ['carrier' => 'jt', 'lines' => [['tracking' => 'GHN700', 'cod' => 0, 'fee' => 5000]]])->json('data.lines.0.note'))->toContain('Đơn ghi hãng GHN');
});

it('recognises carriers whatever way they are written', function () {
    expect(Tracking::carrierKey('GHN'))->toBe('ghn')->and(Tracking::carrierKey('Giao Hàng Nhanh'))->toBe('ghn')
        ->and(Tracking::carrierKey('Viettel Post'))->toBe('viettelpost')->and(Tracking::carrierKey('SPX Express'))->toBe('spx')
        ->and(Tracking::carrierKey('J&T Express'))->toBe('jt')->and(Tracking::carrierKey('jnt'))->toBe('jt')
        ->and(Tracking::carrierKey('tự giao'))->toBeNull()->and(Tracking::carrierKey(null))->toBeNull();
});

it('keeps the tracking number typed at handover on a website order through later syncs', function () {
    $this->store = new FakeWooStore;
    $this->store->addSimple(11, $this->variant->sku, 'Áo', '200000', '200000', stock: 40);
    config(['app.url' => 'https://erp.example.vn']);
    $account = wooConnect($this);
    wooMap($this, $account);
    wooWebhook($this, 'order.created', $this->store->addOrder(960, 'processing', [[11, 1, $this->variant->sku, 200000]]))->assertOk();
    $order = Order::withoutGlobalScopes()->where('external_order_id', '960')->sole();

    $this->api->postJson("/api/v1/orders/{$order->ulid}/actions/pack")->assertOk();
    $this->api->postJson("/api/v1/orders/{$order->ulid}/actions/handover", ['carrier' => 'GHN', 'tracking_number' => 'GHNW1'])->assertOk();
    expect($order->refresh()->tracking_number)->toBe('GHNW1')->and($order->shipping_carrier)->toBe('GHN');

    // The store sends the order again (it has no tracking field): ECSO's number and carrier stay.
    $this->travel(1)->minutes();
    $this->store->orders[960] = array_merge($this->store->orders[960], ['customer_note' => 'Giao giờ hành chính', 'date_modified_gmt' => now()->utc()->format('Y-m-d\TH:i:s')]);
    wooWebhook($this, 'order.updated', $this->store->orders[960])->assertOk();
    expect($order->refresh()->tracking_number)->toBe('GHNW1')->and($order->shipping_carrier)->toBe('GHN')->and($order->buyer_note)->toBe('Giao giờ hành chính');

    // So a carrier statement finds it by tracking number.
    expect($this->api->postJson('/api/v1/cod-reconciliations/preview', ['carrier' => 'ghn', 'lines' => [['tracking' => 'GHNW1', 'cod' => 0, 'fee' => 1000]]])->json('data.lines.0.order.id'))->toBe($order->ulid);
});
