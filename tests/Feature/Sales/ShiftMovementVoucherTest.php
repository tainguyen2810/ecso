<?php

use App\Modules\Access\Models\Role;

it('books a real payment from the drawer in the cash fund, and keeps a move to the safe off the books', function () {
    [$tenant, $owner] = tenantWithOwner();
    $cashier = addMember($tenant, Role::ORDER_STAFF);
    $shift = actingInTenant($cashier, $tenant)->postJson('/api/v1/pos/shifts', ['opening_cash' => 500000, 'warehouse_id' => defaultWarehouse($tenant)->ulid])->assertCreated()->json('data.id');
    $before = fund($owner, $tenant, 'Tiền mặt')['balance'];

    $types = collect(actingInTenant($cashier, $tenant)->getJson('/api/v1/pos/shifts/voucher-types')->assertOk()->json('data'));
    $packing = $types->firstWhere('name', 'Vật tư đóng gói');
    expect($packing['direction'])->toBe('payment');

    // A payment type cannot be used for money coming in.
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'in', 'amount' => 1000, 'reason' => 'x', 'voucher_type' => $packing['id']])
        ->assertUnprocessable();

    // Real expense: a payment voucher in the cash fund, and out of the drawer.
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'out', 'amount' => 30000, 'reason' => 'Mua băng keo', 'voucher_type' => $packing['id']])
        ->assertCreated()->assertJsonPath('data.movements.0.voucher.number', fn ($n) => is_string($n) && $n !== '');
    // Move to the safe: no voucher.
    actingInTenant($cashier, $tenant)->postJson("/api/v1/pos/shifts/{$shift}/movements", ['kind' => 'out', 'amount' => 200000, 'reason' => 'Nộp két sắt'])
        ->assertCreated()->assertJsonPath('data.movements.1.voucher', null)
        ->assertJsonPath('data.totals.cash_out', '230000.00')
        ->assertJsonPath('data.totals.expected_cash', '270000.00');

    expect(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual($before - 30000);

    $rows = collect(actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?q=CA-000001')->assertOk()->json('data'));
    $voucher = $rows->firstWhere('source', 'voucher');
    expect($voucher['direction'])->toBe('payment')->and($voucher['amount'])->toEqual(30000)
        ->and($voucher['type']['name'])->toBe('Vật tư đóng gói')->and($voucher['account']['name'])->toBe('Tiền mặt');
    // The booked movement shows once (as its voucher); the move to the safe shows as an internal transfer.
    $moves = $rows->where('source', 'shift_movement')->values();
    expect($moves)->toHaveCount(1)->and($moves[0]['direction'])->toBe('transfer');

    // The voucher belongs to the shift: it cannot be voided on its own.
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$voucher['id']}/void", ['reason' => 'nhầm'])->assertUnprocessable()->assertJsonValidationErrors('voucher');
});
