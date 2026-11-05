<?php

use App\Modules\Access\Models\Role;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\CashShift;
use App\Modules\Sales\Models\OrderPayment;

function startShift($user, $tenant, int $opening = 500000)
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/pos/shifts', ['opening_cash' => $opening, 'warehouse_id' => defaultWarehouse($tenant)->ulid]);
}

function tillSale($user, $tenant, $variant, int $price, array $extra = [])
{
    return createSalesOrder($user, $tenant, [[$variant, 1, $price]], [
        'stage' => 'completed', 'counter' => true,
        'payments' => [['method' => 'cash', 'amount' => $price]], ...$extra,
    ]);
}

it('refuses a till sale without an open shift, unless the shop turned the rule off', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $cashier = addMember($tenant, Role::ORDER_STAFF);

    tillSale($cashier, $tenant, $variant, 50000)->assertUnprocessable()->assertJsonValidationErrors('shift');
    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(10);

    // An order typed in the back office never needs a shift.
    createSalesOrder($cashier, $tenant, [[$variant, 1, 50000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 50000]]])->assertCreated()->assertJsonPath('data.status', 'COMPLETED');

    actingInTenant($owner, $tenant)->putJson('/api/v1/tenant/settings/sales', ['pos_require_shift' => false])->assertOk();
    tillSale($cashier, $tenant, $variant, 50000)->assertCreated();
});

it('ties the sale and its payments to the open shift', function () {
    [$tenant] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $cashier = addMember($tenant, Role::ORDER_STAFF);
    $shift = startShift($cashier, $tenant)->assertCreated()->json('data.id');

    $id = tillSale($cashier, $tenant, $variant, 80000)->assertCreated()->json('data.id');
    actingInTenant($cashier, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['kind' => 'refund', 'method' => 'cash', 'amount' => 10000])->assertCreated();

    $order = Order::withoutGlobalScopes()->where('ulid', $id)->firstOrFail();
    $shiftId = CashShift::withoutGlobalScopes()->where('ulid', $shift)->value('id');
    expect($order->cash_shift_id)->toBe($shiftId)
        ->and(OrderPayment::withoutGlobalScopes()->where('order_id', $order->id)->pluck('cash_shift_id')->unique()->all())->toBe([$shiftId]);

    actingInTenant($cashier, $tenant)->getJson('/api/v1/pos/shifts/current')->assertOk()
        ->assertJsonPath('data.totals.orders', 1)
        ->assertJsonPath('data.totals.expected_cash', '570000.00');
});

it('reopens only the latest closed shift, for a supervisor, and keeps the previous close in the log', function () {
    [$tenant, $owner] = tenantWithOwner();
    $cashier = addMember($tenant, Role::ORDER_STAFF);

    $first = startShift($cashier, $tenant)->json('data.id');
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$first}/close", ['counted_cash' => 500000])->assertOk();
    $this->travel(1)->minutes();
    $second = startShift($cashier, $tenant)->json('data.id');

    actingInTenant($owner, $tenant)->postJson("/api/v1/pos/shifts/{$second}/reopen", ['reason' => 'Đếm nhầm'])
        ->assertUnprocessable()->assertJsonValidationErrors('shift'); // still open
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$second}/close", ['counted_cash' => 500000])->assertOk();

    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$second}/reopen", ['reason' => 'Đếm nhầm'])->assertForbidden();
    actingInTenant($owner, $tenant)->postJson("/api/v1/pos/shifts/{$second}/reopen", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    actingInTenant($owner, $tenant)->postJson("/api/v1/pos/shifts/{$first}/reopen", ['reason' => 'Đếm nhầm'])
        ->assertUnprocessable()->assertJsonValidationErrors('shift');

    actingInTenant($owner, $tenant)->postJson("/api/v1/pos/shifts/{$second}/reopen", ['reason' => 'Đếm nhầm'])->assertOk()
        ->assertJsonPath('data.status', 'open');
    actingInTenant($cashier, $tenant)->getJson('/api/v1/pos/shifts/current')->assertOk()->assertJsonPath('data.id', $second);
    $this->assertDatabaseHas('audit_logs', ['action' => 'shift.reopened']);
});

it('needs the shift supervisor to void a payment of a closed shift', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $cashier = addMember($tenant, Role::ORDER_STAFF);
    $shift = startShift($cashier, $tenant)->json('data.id');
    $id = tillSale($cashier, $tenant, $variant, 80000)->json('data.id');
    $payment = actingInTenant($cashier, $tenant)->getJson("/api/v1/orders/{$id}")->json('data.payments.0.id');
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/close", ['counted_cash' => 580000])->assertOk();

    actingInTenant($cashier, $tenant)->postJson("/api/v1/payments/{$payment}/void", ['reason' => 'Nhầm'])
        ->assertUnprocessable()->assertJsonValidationErrors('payment');
    actingInTenant($owner, $tenant)->postJson("/api/v1/payments/{$payment}/void", ['reason' => 'Nhầm'])->assertOk();
});

it('shows cash moved between a shift drawer and the safe in the cash book as internal transfers', function () {
    [$tenant, $owner] = tenantWithOwner();
    $cashier = addMember($tenant, Role::ORDER_STAFF);
    $shift = startShift($cashier, $tenant)->json('data.id');
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'out', 'amount' => 200000, 'reason' => 'Nộp két'])->assertCreated();
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'in', 'amount' => 50000, 'reason' => 'Đổi tiền lẻ'])->assertCreated();

    $rows = collect(actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?source=shift_movement')->assertOk()->json('data'));
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('direction')->unique()->values()->all())->toBe(['transfer']);
});
