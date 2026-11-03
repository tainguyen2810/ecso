<?php

use App\Modules\Access\Models\Role;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesChannel;

it('gives every new business a shop-counter channel', function () {
    [$tenant] = tenantWithOwner();

    expect(defaultChannel($tenant)->only(['name', 'type', 'is_active']))->toBe(['name' => SalesChannel::DEFAULT_NAME, 'type' => 'pos', 'is_active' => true]);
});

it('creates a manual order that holds stock, with a new customer found again by phone', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01', ['ref_price' => 150000]);
    stockIn($tenant, $variant, 10);

    $response = createSalesOrder($owner, $tenant, [[$variant, 3, 150000]], [
        'customer' => ['name' => 'Nguyễn Văn A', 'phone' => '+84 912 345 678', 'address' => '1 Lê Lợi, Q1'],
        'shipping_fee' => 30000,
        'discount' => 20000,
    ])->assertCreated();

    expect($response->json('data.status'))->toBe(OrderStatus::PENDING_CONFIRMATION)
        ->and($response->json('data.total'))->toBe('460000.00')
        ->and($response->json('data.external_order_id'))->toBe('SO-000001')
        ->and($response->json('data.buyer_phone'))->toBe('0912345678')
        ->and($response->json('data.payment_status'))->toBe('unpaid');

    $balance = balanceOf(defaultWarehouse($tenant), $variant);
    expect($balance->on_hand)->toBe(10)->and($balance->reserved)->toBe(3);

    $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($customer->phone)->toBe('0912345678')
        ->and($customer->getRawOriginal('phone'))->not->toContain('0912345678'); // encrypted at rest

    // The same phone in another format is the same customer.
    createSalesOrder($owner, $tenant, [[$variant, 1]], ['customer' => ['name' => 'A', 'phone' => '0912.345.678']])->assertCreated();
    expect(Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);

    actingInTenant($owner, $tenant)->getJson('/api/v1/customers?q=0912345678')
        ->assertOk()->assertJsonPath('data.0.orders_count', 2)->assertJsonPath('data.0.debt', '560000.00');
});

it('walks a manual order through confirm, pack, handover, deliver and complete, consuming stock once', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $id = createSalesOrder($owner, $tenant, [[$variant, 2]])->json('data.id');

    foreach (['confirm', 'pack'] as $action) {
        actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/{$action}")->assertOk();
    }
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/handover", ['carrier' => 'GHN', 'tracking_number' => 'GHN123'])
        ->assertOk()->assertJsonPath('data.status', OrderStatus::HANDED_OVER)->assertJsonPath('data.tracking_number', 'GHN123');

    $balance = balanceOf(defaultWarehouse($tenant), $variant);
    expect($balance->on_hand)->toBe(3)->and($balance->reserved)->toBe(0);

    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/deliver")->assertOk();
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/complete")->assertOk()->assertJsonPath('data.status', OrderStatus::COMPLETED);

    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(3);
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertOk()
        ->assertJsonCount(6, 'data.history')->assertJsonPath('data.source', 'manual');

    // Backwards or repeated actions are refused.
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/pack")->assertUnprocessable();
});

it('also accepts the generic order action endpoint for manual orders (bulk confirm)', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $a = createSalesOrder($owner, $tenant, [[$variant, 1]])->json('data.id');
    $b = createSalesOrder($owner, $tenant, [[$variant, 1]])->json('data.id');

    actingInTenant($owner, $tenant)->postJson('/api/v1/orders/bulk', ['ids' => [$a, $b], 'action' => 'confirm'])
        ->assertOk()->assertJsonPath('data.0.ok', true)->assertJsonPath('data.1.ok', true);
    expect(Order::withoutGlobalScopes()->whereIn('ulid', [$a, $b])->pluck('status')->unique()->all())->toBe([OrderStatus::READY_TO_PACK]);
});

it('never ships a manual order while stock is short, and completes at the counter only with stock', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SHORT-1');
    stockIn($tenant, $variant, 2);

    // A counter sale beyond stock is refused and leaves nothing behind (no order, no number used).
    createSalesOrder($owner, $tenant, [[$variant, 3]], ['stage' => 'completed'])->assertUnprocessable()->assertJsonValidationErrors('stock');
    expect(Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);

    // A backorder is accepted and holds what exists.
    $id = createSalesOrder($owner, $tenant, [[$variant, 3]], ['stage' => 'confirmed'])->assertCreated()->assertJsonPath('data.flags.shortage', true)->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/handover")->assertUnprocessable()->assertJsonValidationErrors('stock');

    stockIn($tenant, $variant, 1); // the shortage is filled automatically
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/handover")->assertOk();
    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(0);

    stockIn($tenant, $variant, 4);
    createSalesOrder($owner, $tenant, [[$variant, 4]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 400000]]])
        ->assertCreated()->assertJsonPath('data.status', OrderStatus::COMPLETED)->assertJsonPath('data.payment_status', 'paid');
    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(0);
});

it('releases stock on cancel and asks for a refund when the buyer had paid', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $id = createSalesOrder($owner, $tenant, [[$variant, 2]], ['payments' => [['method' => 'bank_transfer', 'amount' => 50000]]])->json('data.id');

    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/cancel", ['reason' => 'Khách đổi ý'])
        ->assertOk()->assertJsonPath('data.status', OrderStatus::CANCELLED)->assertJsonPath('data.cancel_reason', 'Khách đổi ý');
    expect(balanceOf(defaultWarehouse($tenant), $variant)->reserved)->toBe(0);

    $order = Order::withoutGlobalScopes()->where('ulid', $id)->first();
    expect($order->sync_warning)->toContain('hoàn tiền');

    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['kind' => 'refund', 'method' => 'cash', 'amount' => 60000])->assertUnprocessable();
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['kind' => 'refund', 'method' => 'cash', 'amount' => 50000])->assertCreated();
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['method' => 'cash', 'amount' => 1000])->assertUnprocessable();
});

it('records partial payments, refuses overpayment and recomputes after a void', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $id = createSalesOrder($owner, $tenant, [[$variant, 2, 100000]])->json('data.id');

    $first = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['method' => 'cash', 'amount' => 80000])->assertCreated()->json('data.id');
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.balance_due', '120000.00');

    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['method' => 'cash', 'amount' => 130000])->assertUnprocessable()->assertJsonValidationErrors('amount');
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['method' => 'bank_transfer', 'amount' => 120000, 'reference' => 'FT123'])->assertCreated();
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.payment_status', 'paid')->assertJsonCount(2, 'data.payments');

    actingInTenant($owner, $tenant)->postJson("/api/v1/payments/{$first}/void", ['reason' => 'Nhập nhầm'])->assertOk();
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.paid_amount', '120000.00');
});

it('edits lines of an unpacked order and re-holds stock for the new lines', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'A');
    $b = makeVariant($tenant, 'B');
    stockIn($tenant, $a, 5);
    stockIn($tenant, $b, 5);
    $id = createSalesOrder($owner, $tenant, [[$a, 3]])->json('data.id');

    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-orders/{$id}", [
        'lines' => [['variant_id' => $a->ulid, 'quantity' => 1, 'unit_price' => 100000], ['variant_id' => $b->ulid, 'quantity' => 2, 'unit_price' => 50000]],
        'shipping_fee' => 0,
    ])->assertOk()->assertJsonPath('data.total', '200000.00');

    expect(balanceOf(defaultWarehouse($tenant), $a)->reserved)->toBe(1)
        ->and(balanceOf(defaultWarehouse($tenant), $b)->reserved)->toBe(2);

    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/confirm")->assertOk();
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/pack")->assertOk();
    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-orders/{$id}", ['discount' => 1000])->assertUnprocessable();
});

it('returns goods of a manual order through the normal return flow', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $order = createSalesOrder($owner, $tenant, [[$variant, 2]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 200000]]])->json('data');
    $itemId = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order['id']}")->json('data.items.0.id');

    $return = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertCreated()->json('data');
    foreach ([OrderReturn::APPROVED, OrderReturn::RECEIVED] as $status) {
        actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/status", ['status' => $status])->assertOk();
    }
    $lineId = actingInTenant($owner, $tenant)->getJson("/api/v1/returns/{$return['id']}")->json('data.lines.0.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$return['id']}/inspect", [
        'warehouse_id' => defaultWarehouse($tenant)->ulid,
        'lines' => [['line_id' => $lineId, 'received_qty' => 1, 'sellable_qty' => 1, 'damaged_qty' => 0]],
    ])->assertOk();

    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(4);
});

it('enforces permissions and tenant isolation on sales data', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $id = createSalesOrder($owner, $tenant, [[$variant, 1]], ['customer' => ['name' => 'Khách X', 'phone' => '0900000001']])->json('data.id');
    $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $viewer = addMember($tenant, Role::VIEWER);
    createSalesOrder($viewer, $tenant, [[$variant, 1]])->assertForbidden();
    actingInTenant($viewer, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['method' => 'cash', 'amount' => 1000])->assertForbidden();
    actingInTenant($viewer, $tenant)->getJson('/api/v1/customers')->assertOk();
    actingInTenant($viewer, $tenant)->postJson('/api/v1/customers', ['name' => 'Y'])->assertForbidden();

    $staff = addMember($tenant, Role::ORDER_STAFF);
    createSalesOrder($staff, $tenant, [[$variant, 1]])->assertCreated();

    actingInTenant($otherOwner, $other)->getJson("/api/v1/customers/{$customer->ulid}")->assertNotFound();
    actingInTenant($otherOwner, $other)->postJson("/api/v1/sales-orders/{$id}/actions/confirm")->assertNotFound();
    actingInTenant($otherOwner, $other)->getJson('/api/v1/customers?q=0900000001')->assertOk()->assertJsonCount(0, 'data');
    // A channel of another business cannot be used.
    actingInTenant($otherOwner, $other)->postJson('/api/v1/sales-orders', ['channel_id' => defaultChannel($tenant)->ulid, 'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1]]])
        ->assertUnprocessable();
});

it('refuses manual actions on marketplace orders and payments on them', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $order = fakeOrder(fakeShop($tenant), [[$variant, 1]]);

    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$order->ulid}/actions/complete")->assertUnprocessable();
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order->ulid}/payments", ['method' => 'cash', 'amount' => 1000])->assertUnprocessable();
});

it('manages customers and sales channels', function () {
    [$tenant, $owner] = tenantWithOwner();

    $c = actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Trần B', 'phone' => '0987 654 321'])->assertCreated()->json('data');
    expect($c['code'])->toBe('KH-000001')->and($c['phone'])->toBe('0987654321');
    actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Trùng', 'phone' => '84987654321'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Sai', 'phone' => '12'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    actingInTenant($owner, $tenant)->getJson('/api/v1/customers?q=4321')->assertJsonCount(1, 'data');
    actingInTenant($owner, $tenant)->deleteJson("/api/v1/customers/{$c['id']}")->assertNoContent();

    $ch = actingInTenant($owner, $tenant)->postJson('/api/v1/sales-channels', ['name' => 'Fanpage', 'type' => 'facebook', 'warehouse_id' => defaultWarehouse($tenant)->ulid])
        ->assertCreated()->assertJsonPath('data.warehouse.code', defaultWarehouse($tenant)->code)->json('data');
    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-channels', ['name' => 'Fanpage', 'type' => 'facebook'])->assertUnprocessable();
    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-channels/{$ch['id']}", ['name' => 'Fanpage', 'type' => 'facebook', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    $variant = makeVariant($tenant);
    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', ['channel_id' => $ch['id'], 'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('channel_id');
    actingInTenant($owner, $tenant)->deleteJson('/api/v1/sales-channels/'.defaultChannel($tenant)->ulid)->assertNoContent();
});

it('requires walk-in counter sales to be paid in full, but lets a known customer owe', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);

    createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 50000]]])
        ->assertUnprocessable()->assertJsonValidationErrors('payments');
    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(5);

    createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['stage' => 'completed', 'customer' => ['name' => 'Khách quen', 'phone' => '0911000111'], 'payments' => [['method' => 'cash', 'amount' => 50000]]])
        ->assertCreated()->assertJsonPath('data.payment_status', 'partial');
});

it('sums the cashier shift by payment method, refunds subtracted', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $cashier = addMember($tenant, Role::ORDER_STAFF);

    createSalesOrder($cashier, $tenant, [[$variant, 2, 100000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 200000]]])->assertCreated();
    $id = createSalesOrder($cashier, $tenant, [[$variant, 1, 150000]], ['stage' => 'completed', 'payments' => [['method' => 'bank_transfer', 'amount' => 150000]]])->json('data.id');
    actingInTenant($cashier, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['kind' => 'refund', 'method' => 'cash', 'amount' => 20000])->assertCreated();
    createSalesOrder($owner, $tenant, [[$variant, 1, 999000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 999000]]])->assertCreated();

    actingInTenant($cashier, $tenant)->getJson('/api/v1/pos/summary')->assertOk()
        ->assertJsonPath('data.orders', 2)
        ->assertJsonPath('data.revenue', '350000.00')
        ->assertJsonPath('data.by_method.cash', '180000.00')
        ->assertJsonPath('data.by_method.bank_transfer', '150000.00')
        ->assertJsonPath('data.collected', '330000.00');

    actingInTenant(addMember($tenant, Role::VIEWER), $tenant)->getJson('/api/v1/pos/summary')->assertForbidden();
});

it('sells a service line next to goods: no SKU, no stock, kept when the order is edited and completed', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'PIN-01', ['ref_price' => 400000]);
    stockIn($tenant, $variant, 5);
    $api = actingInTenant($owner, $tenant);

    $created = $api->postJson('/api/v1/sales-orders', [
        'channel_id' => defaultChannel($tenant)->ulid,
        'lines' => [
            ['variant_id' => $variant->ulid, 'quantity' => 1, 'unit_price' => 400000],
            ['name' => 'Công thay pin', 'quantity' => 1, 'unit_price' => 50000, 'discount' => 10000],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.total', '440000.00')
        ->assertJsonPath('data.flags.unmapped', false);
    $id = $created->json('data.id');
    $api->getJson("/api/v1/orders/{$id}")->assertOk()
        ->assertJsonPath('data.items.1.name', 'Công thay pin')
        ->assertJsonPath('data.items.1.mapping_status', 'service')
        ->assertJsonPath('data.items.1.variant', null)
        ->assertJsonPath('data.items.1.reservation', null);
    expect(balanceOf(defaultWarehouse($tenant), $variant)->reserved)->toBe(1);

    // Editing another field keeps the service line; completing only moves the SKU's stock.
    $api->putJson("/api/v1/sales-orders/{$id}", ['note' => 'Khách lấy chiều'])->assertOk()->assertJsonPath('data.total', '440000.00');
    $api->postJson("/api/v1/sales-orders/{$id}/actions/confirm")->assertOk();
    $api->postJson("/api/v1/sales-orders/{$id}/actions/complete")->assertOk()->assertJsonPath('data.status', OrderStatus::COMPLETED);
    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(4);

    // A line needs a SKU or a name.
    $api->postJson('/api/v1/sales-orders', ['channel_id' => defaultChannel($tenant)->ulid, 'lines' => [['quantity' => 1, 'unit_price' => 1000]]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.variant_id']);
});
