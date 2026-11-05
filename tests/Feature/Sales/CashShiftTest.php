<?php

use App\Modules\Access\Models\Role;
use App\Modules\Sales\Models\CashShift;

function openShift($user, $tenant, int $opening = 500000)
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/pos/shifts', ['opening_cash' => $opening, 'warehouse_id' => defaultWarehouse($tenant)->ulid]);
}

it('counts the drawer of a shift: float, cash sales, refunds, cash in and out', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 20);
    $cashier = addMember($tenant, Role::ORDER_STAFF);

    // Money taken before the shift opened is not in it.
    createSalesOrder($cashier, $tenant, [[$variant, 1, 70000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 70000]]])->assertCreated();
    $this->travel(1)->minutes();

    $shift = openShift($cashier, $tenant)->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.number', 'CA-000001')->json('data.id');
    openShift($cashier, $tenant)->assertUnprocessable()->assertJsonValidationErrors('shift');

    createSalesOrder($cashier, $tenant, [[$variant, 2, 100000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 200000]]])->assertCreated();
    $id = createSalesOrder($cashier, $tenant, [[$variant, 1, 150000]], ['stage' => 'completed', 'payments' => [['method' => 'bank_transfer', 'amount' => 150000]]])->json('data.id');
    actingInTenant($cashier, $tenant)->postJson("/api/v1/orders/{$id}/payments", ['kind' => 'refund', 'method' => 'cash', 'amount' => 20000])->assertCreated();
    // Another cashier's sale is not in this drawer.
    createSalesOrder($owner, $tenant, [[$variant, 1, 999000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 999000]]])->assertCreated();

    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'in', 'amount' => 100000, 'reason' => 'Đổi tiền lẻ'])->assertCreated();
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'out', 'amount' => 5000000, 'reason' => 'Nộp két'])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'out', 'amount' => 300000, 'reason' => 'Nộp két sắt'])->assertCreated();

    // 500 000 + 200 000 − 20 000 + 100 000 − 300 000
    actingInTenant($cashier, $tenant)->getJson('/api/v1/pos/shifts/current')->assertOk()
        ->assertJsonPath('data.id', $shift)
        ->assertJsonPath('data.totals.orders', 2)
        ->assertJsonPath('data.totals.revenue', '350000.00')
        ->assertJsonPath('data.totals.by_method.bank_transfer', '150000.00')
        ->assertJsonPath('data.totals.cash_sales', '200000.00')
        ->assertJsonPath('data.totals.cash_refunds', '20000.00')
        ->assertJsonPath('data.totals.expected_cash', '480000.00')
        ->assertJsonCount(2, 'data.movements');

    // Short by 10 000: a reason is required.
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/close", ['counted_cash' => 470000])
        ->assertUnprocessable()->assertJsonValidationErrors('closing_note');
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/close", [
        'denominations' => ['200000' => 2, '50000' => 1, '20000' => 1],
        'handed_over' => 400000,
        'closing_note' => 'Thối nhầm',
    ])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.counted_cash', '470000.00')
        ->assertJsonPath('data.expected_cash', '480000.00')
        ->assertJsonPath('data.difference', '-10000.00')
        ->assertJsonPath('data.kept_in_drawer', '70000.00');

    // The shortage is booked in the cash fund as a "Chênh lệch kiểm quỹ" payment, so the fund matches the drawer.
    $diff = collect(actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?q=CA-000001')->assertOk()->json('data'))->firstWhere('source', 'voucher');
    expect($diff)->not->toBeNull()
        ->and($diff['direction'])->toBe('payment')->and($diff['amount'])->toEqual(10000)
        ->and($diff['type']['name'])->toBe('Chênh lệch kiểm quỹ')->and($diff['account']['name'])->toBe('Tiền mặt');

    // Closed: figures are frozen and the drawer takes no more movements.
    $closed = CashShift::withoutGlobalScopes()->where('ulid', $shift)->sole();
    expect($closed->open_user_id)->toBeNull();
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'in', 'amount' => 1000, 'reason' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('shift');

    // The next shift starts from what was left in the drawer.
    actingInTenant($cashier, $tenant)->getJson('/api/v1/pos/shifts/current?warehouse='.defaultWarehouse($tenant)->ulid)
        ->assertOk()->assertJsonPath('data', null)->assertJsonPath('meta.suggested_opening', '70000.00');
});

it('lets cashiers see only their own shifts and managers close a forgotten one', function () {
    [$tenant, $owner] = tenantWithOwner();
    $cashier = addMember($tenant, Role::ORDER_STAFF);
    $other = addMember($tenant, Role::ORDER_STAFF);
    $manager = addMember($tenant, Role::MANAGER);

    $shift = openShift($cashier, $tenant, 200000)->assertCreated()->json('data.id');
    openShift($other, $tenant, 0)->assertCreated();

    actingInTenant($cashier, $tenant)->getJson('/api/v1/pos/shifts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.can_view_all', false);
    actingInTenant($other, $tenant)->getJson("/api/v1/pos/shifts/{$shift}")->assertForbidden();
    actingInTenant($other, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/close", ['counted_cash' => 200000])->assertForbidden();

    actingInTenant($manager, $tenant)->getJson('/api/v1/pos/shifts?status=open')->assertOk()->assertJsonCount(2, 'data');
    actingInTenant($manager, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/close", ['counted_cash' => 200000])
        ->assertOk()->assertJsonPath('data.difference', '0.00')->assertJsonPath('data.closed_by', $manager->name);

    // Closed: the cashier can open a new one.
    openShift($cashier, $tenant)->assertCreated()->assertJsonPath('data.number', 'CA-000003');

    actingInTenant(addMember($tenant, Role::VIEWER), $tenant)->getJson('/api/v1/pos/shifts')->assertForbidden();
    actingInTenant(addMember($tenant, Role::WAREHOUSE_STAFF), $tenant)->postJson('/api/v1/pos/shifts', ['opening_cash' => 0])->assertForbidden();
});

it('keeps shifts per business', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other, $otherOwner] = tenantWithOwner();
    $shift = openShift($owner, $tenant)->json('data.id');

    actingInTenant($otherOwner, $other)->getJson("/api/v1/pos/shifts/{$shift}")->assertNotFound();
    actingInTenant($otherOwner, $other)->getJson('/api/v1/pos/shifts')->assertOk()->assertJsonCount(0, 'data');
});
