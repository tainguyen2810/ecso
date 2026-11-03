<?php

use App\Modules\Access\Models\Role;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\OrderPayment;

it('tags orders, sets the delivery date, and filters by both', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);

    $a = createSalesOrder($owner, $tenant, [[$variant, 1]], ['tags' => [' Khách VIP ', 'khách vip', 'Giao gấp', ''], 'deliver_on' => '2026-11-10'])
        ->assertCreated()->assertJsonPath('data.tags', ['Khách VIP', 'Giao gấp'])->assertJsonPath('data.deliver_on', '2026-11-10')->json('data.id');
    $b = createSalesOrder($owner, $tenant, [[$variant, 1]])->json('data.id');
    $shop = fakeOrder(fakeShop($tenant), [[$variant, 1]])->ulid;

    // Tags and date can be set on any order, marketplace ones included.
    actingInTenant($owner, $tenant)->putJson("/api/v1/orders/{$shop}/meta", ['tags' => ['Giao gấp'], 'deliver_on' => '2026-11-12'])
        ->assertOk()->assertJsonPath('data.tags', ['Giao gấp']);
    actingInTenant($owner, $tenant)->putJson("/api/v1/orders/{$b}/meta", ['deliver_on' => '2026-11-20'])->assertOk()->assertJsonPath('data.tags', []);

    $ids = fn (string $query) => collect(actingInTenant($owner, $tenant)->getJson("/api/v1/orders?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    expect($ids('tag=Giao gấp'))->toEqualCanonicalizing([$a, $shop])
        ->and($ids('deliver_from=2026-11-11&deliver_to=2026-11-15'))->toBe([$shop]);

    actingInTenant($owner, $tenant)->getJson('/api/v1/orders/tags')->assertOk()->assertJsonPath('data', ['Giao gấp', 'Khách VIP']);
    actingInTenant($otherOwner, $other)->getJson('/api/v1/orders/tags')->assertOk()->assertJsonPath('data', []);
    actingInTenant($otherOwner, $other)->putJson("/api/v1/orders/{$a}/meta", ['tags' => ['x']])->assertNotFound();

    $viewer = addMember($tenant, Role::VIEWER);
    actingInTenant($viewer, $tenant)->putJson("/api/v1/orders/{$a}/meta", ['tags' => []])->assertForbidden();

    actingInTenant($owner, $tenant)->putJson("/api/v1/orders/{$a}/meta", ['tags' => array_map(fn ($i) => "t{$i}", range(1, 11))])->assertUnprocessable();
});

it('sets the returned goods off against an exchange order', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-M');
    $other = makeVariant($tenant, 'AO-L');
    stockIn($tenant, $variant, 10);
    stockIn($tenant, $other, 10);
    $buyer = ['name' => 'Chị Lan', 'phone' => '0900000010'];

    // Bought 2 × 150.000, paid 280.000 so far.
    $original = createSalesOrder($owner, $tenant, [[$variant, 2, 150000]], ['stage' => 'completed', 'customer' => $buyer, 'payments' => [['method' => 'cash', 'amount' => 280000]]])->json('data.id');
    $itemId = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$original}")->json('data.items.0.id');
    $return = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$original}/returns", ['type' => 'return', 'reason' => 'Đổi size', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->json('data.id');

    // New order of 200.000: the returned item (150.000) pays for most of it, the buyer adds 50.000 in cash.
    $exchange = createSalesOrder($owner, $tenant, [[$other, 1, 200000]], [
        'customer' => $buyer,
        'exchange_return_id' => $return,
        'stage' => 'completed',
        'payments' => [['method' => 'cash', 'amount' => 50000]],
    ])->assertCreated()->assertJsonPath('data.payment_status', 'paid')->json('data.id');

    actingInTenant($owner, $tenant)->getJson("/api/v1/returns/{$return}")->assertOk()
        ->assertJsonPath('data.exchange_credit', '150000.00')->assertJsonPath('data.exchange_order.id', $exchange);
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$original}")
        ->assertJsonPath('data.refunded_amount', '150000.00')->assertJsonPath('data.payments.1.method', OrderPayment::EXCHANGE);
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$exchange}")
        ->assertJsonPath('data.exchanged_from.0.id', $return)->assertJsonPath('data.paid_amount', '200000.00');

    // Cash of the shift: 280.000 + 50.000, the set-off is not cash.
    actingInTenant($owner, $tenant)->getJson('/api/v1/pos/summary')->assertOk()->assertJsonPath('data.by_method.cash', '330000.00');
    // Nor is it a cash book entry: only the two cash receipts are listed.
    $vouchers = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers')->assertOk()->json('data');
    expect(collect($vouchers)->pluck('method')->all())->not->toContain(OrderPayment::EXCHANGE)->toHaveCount(2);

    // The set-off cannot be voided on one side only.
    $setOff = collect(actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$exchange}")->json('data.payments'))->firstWhere('method', OrderPayment::EXCHANGE);
    actingInTenant($owner, $tenant)->postJson("/api/v1/payments/{$setOff['id']}/void", ['reason' => 'x'])->assertUnprocessable();

    // A return is used once.
    createSalesOrder($owner, $tenant, [[$other, 1, 100000]], ['customer' => $buyer, 'exchange_return_id' => $return])
        ->assertUnprocessable()->assertJsonValidationErrors('exchange_return_id');
    expect(OrderReturn::withoutGlobalScopes()->where('ulid', $return)->value('exchange_order_id'))->not->toBeNull();
});

it('never sets off more than the buyer paid or the new order costs', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $buyer = ['name' => 'Anh Nam', 'phone' => '0900000011'];

    // Paid only 100.000 of 300.000.
    $original = createSalesOrder($owner, $tenant, [[$variant, 3, 100000]], ['stage' => 'completed', 'customer' => $buyer, 'payments' => [['method' => 'cash', 'amount' => 100000]]])->json('data.id');
    $itemId = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$original}")->json('data.items.0.id');
    $return = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$original}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 3]]])->json('data.id');

    $exchange = createSalesOrder($owner, $tenant, [[$variant, 1, 60000]], ['customer' => $buyer, 'exchange_return_id' => $return])->assertCreated()->json('data.id');

    actingInTenant($owner, $tenant)->getJson("/api/v1/returns/{$return}")->assertJsonPath('data.exchange_credit', '60000.00');
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$exchange}")->assertJsonPath('data.payment_status', 'paid');
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$original}")->assertJsonPath('data.refunded_amount', '60000.00');
});
