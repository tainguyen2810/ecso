<?php

use App\Modules\Access\Models\Role;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\OrderPayment;
use Illuminate\Support\Facades\DB;

/** Order audit, batch 1 (ORDER_MODULE_AUDIT.md B1, B2, B5, B6, B8, B9, M1). */
function returnUntilReceived($user, $tenant, string $returnId): void
{
    foreach ([OrderReturn::APPROVED, OrderReturn::RECEIVED] as $status) {
        actingInTenant($user, $tenant)->postJson("/api/v1/returns/{$returnId}/status", ['status' => $status])->assertOk();
    }
}

function firstItemId($user, $tenant, string $orderId): int
{
    return actingInTenant($user, $tenant)->getJson("/api/v1/orders/{$orderId}")->json('data.items.0.id');
}

it('refuses to return goods that never left the warehouse and shows what can still be returned', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $order = createSalesOrder($owner, $tenant, [[$variant, 2]])->json('data'); // only reserved
    $itemId = firstItemId($owner, $tenant, $order['id']);

    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order['id']}")->assertJsonPath('data.items.0.returnable', 0);
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');

    // Once handed over, the shipped quantity can come back, and only once in total.
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$order['id']}/actions/confirm")->assertOk();
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$order['id']}/actions/handover")->assertOk();
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order['id']}")->assertJsonPath('data.items.0.returnable', 2);
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertCreated();
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order['id']}")->assertJsonPath('data.items.0.returnable', 1);
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 2]]])
        ->assertUnprocessable();

    $b = balanceOf(defaultWarehouse($tenant), $variant);
    expect($b->on_hand)->toBe(3)->and($b->reserved)->toBe(0);
});

it('still lets a refund without goods be recorded on an order that was not shipped', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $order = createSalesOrder($owner, $tenant, [[$variant, 1]])->json('data');

    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'refund_only', 'refund_amount' => 10000])->assertCreated();
});

it('refuses the same product twice in one return and the same line twice at inspection', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $order = createSalesOrder($owner, $tenant, [[$variant, 2]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 200000]]])->json('data');
    $itemId = firstItemId($owner, $tenant, $order['id']);

    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [
        ['order_item_id' => $itemId, 'quantity' => 1], ['order_item_id' => $itemId, 'quantity' => 1],
    ]])->assertUnprocessable()->assertJsonValidationErrors('lines.1.order_item_id');

    $return = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])
        ->assertCreated()->json('data');
    returnUntilReceived($owner, $tenant, $return['id']);
    $lineId = actingInTenant($owner, $tenant)->getJson("/api/v1/returns/{$return['id']}")->json('data.lines.0.id');
    $line = ['line_id' => $lineId, 'received_qty' => 1, 'sellable_qty' => 1, 'damaged_qty' => 0];

    actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/inspect", ['warehouse_id' => defaultWarehouse($tenant)->ulid, 'lines' => [$line, $line]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.1.line_id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/inspect", ['warehouse_id' => defaultWarehouse($tenant)->ulid, 'lines' => [$line]])->assertOk();

    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(4); // 5 - 2 sold + 1 back
});

it('lets order staff and warehouse staff record a return, but not a viewer', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $order = createSalesOrder($owner, $tenant, [[$variant, 3]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 300000]]])->json('data');
    $body = ['type' => 'return', 'lines' => [['order_item_id' => firstItemId($owner, $tenant, $order['id']), 'quantity' => 1]]];

    actingInTenant(addMember($tenant, Role::WAREHOUSE_STAFF), $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", $body)->assertCreated();
    actingInTenant(addMember($tenant, Role::ORDER_STAFF), $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", $body)->assertCreated();
    actingInTenant(addMember($tenant, Role::VIEWER), $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", $body)->assertForbidden();
});

it('creates one order and one payment when the same sale is sent twice with one key', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $body = [
        'channel_id' => defaultChannel($tenant)->ulid, 'stage' => 'completed',
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1, 'unit_price' => 100000]],
        'payments' => [['method' => 'cash', 'amount' => 100000]],
    ];
    $headers = ['Idempotency-Key' => 'pos-cart-1-abc'];

    $first = actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', $body, $headers)->assertCreated();
    $again = actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', $body, $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(OrderPayment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(4);

    // The same key for a different cart is refused; a new key sells again.
    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', [...$body, 'lines' => [['variant_id' => $variant->ulid, 'quantity' => 2, 'unit_price' => 100000]]], $headers)
        ->assertUnprocessable();
    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', $body, ['Idempotency-Key' => 'pos-cart-1-def'])->assertCreated();
    expect(Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('lets a failed sale be fixed and sent again with the same key, and answers 409 while the first is running', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 1);
    $headers = ['Idempotency-Key' => 'k-1'];
    $sale = fn (int $qty) => ['channel_id' => defaultChannel($tenant)->ulid, 'stage' => 'completed',
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => $qty, 'unit_price' => 100000]], 'payments' => [['method' => 'cash', 'amount' => 100000 * $qty]]];

    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', $sale(2), $headers)->assertUnprocessable(); // stock short
    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', $sale(1), $headers)->assertCreated();

    // A request still running under a key blocks a second one with that key.
    DB::table('idempotency_keys')->insert(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'key' => 'k-2', 'route' => 'POST api/v1/sales-orders',
        'request_hash' => hash('sha256', 'POST api/v1/sales-orders'."\n".json_encode($sale(1))), 'created_at' => now()]);
    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', $sale(1), ['Idempotency-Key' => 'k-2'])->assertStatus(409);
});

it('records one payment when the same payment is sent twice with one key', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $id = createSalesOrder($owner, $tenant, [[$variant, 3, 100000]])->json('data.id');
    $headers = ['Idempotency-Key' => 'pay-1'];

    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['method' => 'cash', 'amount' => 100000], $headers)->assertCreated();
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['method' => 'cash', 'amount' => 100000], $headers)->assertCreated();

    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.paid_amount', '100000.00')->assertJsonCount(1, 'data.payments');
});

it('keeps idempotency keys per business and per user', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    foreach ([[$tenant, $owner], [$other, $otherOwner]] as [$t, $u]) {
        $variant = makeVariant($t);
        stockIn($t, $variant, 5);
        actingInTenant($u, $t)->postJson('/api/v1/sales-orders', [
            'channel_id' => defaultChannel($t)->ulid, 'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1]],
        ], ['Idempotency-Key' => 'same-key'])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    }
});

it('refunds from a return up to the value of the returned goods, once, and shows the return and refund state', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01', ['ref_price' => 100000]);
    stockIn($tenant, $variant, 5);
    // 2 x 100,000 with 20,000 off the whole order: each unit really cost 90,000.
    $order = createSalesOrder($owner, $tenant, [[$variant, 2, 100000]], ['stage' => 'completed', 'discount' => 20000, 'payments' => [['method' => 'cash', 'amount' => 180000]]])->json('data');
    $itemId = firstItemId($owner, $tenant, $order['id']);

    $return = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])
        ->assertCreated()->json('data');
    actingInTenant($owner, $tenant)->getJson("/api/v1/returns/{$return['id']}")
        ->assertJsonPath('data.goods_value', '90000.00')->assertJsonPath('data.refundable', '90000.00')->assertJsonPath('data.refunded', '0.00');

    actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/refund", ['method' => 'cash', 'amount' => 100000])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/refund", ['method' => 'cash', 'amount' => 90000], ['Idempotency-Key' => 'r-1'])->assertOk()
        ->assertJsonPath('data.refunded', '90000.00')->assertJsonPath('data.refundable', '0.00');
    // Same click again: replayed, no second refund. Another refund: nothing left.
    actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/refund", ['method' => 'cash', 'amount' => 90000], ['Idempotency-Key' => 'r-1'])->assertOk();
    actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/refund", ['method' => 'cash', 'amount' => 1000])->assertUnprocessable();

    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order['id']}")->assertOk()
        ->assertJsonPath('data.refunded_amount', '90000.00')
        ->assertJsonPath('data.refund_status', 'partial')
        ->assertJsonPath('data.return_state', 'partial')
        ->assertJsonCount(2, 'data.payments');
    expect(OrderPayment::withoutGlobalScopes()->where('kind', 'refund')->sole()->order_return_id)->not->toBeNull();

    // A cashier without the payment permission cannot refund.
    $warehouse = addMember($tenant, Role::WAREHOUSE_STAFF);
    actingInTenant($warehouse, $tenant)->postJson("/api/v1/returns/{$return['id']}/refund", ['method' => 'cash', 'amount' => 1000])->assertForbidden();
});

it('marks an order fully returned once everything shipped has come back', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $order = createSalesOrder($owner, $tenant, [[$variant, 2]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 200000]]])->json('data');
    $itemId = firstItemId($owner, $tenant, $order['id']);

    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order['id']}")->assertJsonPath('data.return_state', 'none')->assertJsonPath('data.refund_status', 'none');
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 2]]])->assertCreated();
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order['id']}")->assertJsonPath('data.return_state', 'full');
});

it('does not refund marketplace orders from a return', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $order = fakeOrder(fakeShop($tenant), [[$variant, 1]], 'fake_delivered');
    $itemId = firstItemId($owner, $tenant, $order->ulid);

    $return = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order->ulid}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])
        ->assertCreated()->json('data');
    actingInTenant($owner, $tenant)->getJson("/api/v1/returns/{$return['id']}")->assertJsonPath('data.refundable', '0.00');
    actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/refund", ['method' => 'cash', 'amount' => 1000])->assertUnprocessable();
});
