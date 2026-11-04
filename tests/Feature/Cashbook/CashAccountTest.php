<?php

use App\Modules\Access\Models\Role;
use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Cashbook\Models\CashVoucher;

it('starts every business with default funds and puts each payment in the fund of its method', function () {
    [$tenant, $owner] = tenantWithOwner();
    $funds = collect(actingInTenant($owner, $tenant)->getJson('/api/v1/cash-accounts')->assertOk()->json('data'));
    expect($funds->pluck('name')->all())->toBe(['Tiền mặt', 'Tài khoản ngân hàng', 'Ví điện tử', 'Tiền COD (hãng vận chuyển giữ)', 'Quỹ khác'])
        ->and($funds->firstWhere('name', 'Tiền mặt')['methods'])->toBe(['cash']);

    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 5);
    $order = cashSale($owner, $tenant, $variant, 300000, actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Chị Lan', 'phone' => '0901234567'])->json('data.id'));
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order}/payments", ['method' => 'cash', 'amount' => 100000])->assertCreated();
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order}/payments", ['method' => 'bank_transfer', 'amount' => 150000])->assertCreated();
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'NCC A'])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'bank_transfer', 'amount' => 40000])->assertCreated();

    expect(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual(100000)
        ->and(fund($owner, $tenant, 'Tài khoản ngân hàng')['balance'])->toEqual(110000);

    // A fund picked by hand wins over the method's default.
    $ewallet = fund($owner, $tenant, 'Ví điện tử')['id'];
    $income = cashType($owner, $tenant, 'receipt', 'Thu nhập khác');
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => $income, 'party_kind' => 'other', 'party_name' => 'X', 'method' => 'cash', 'amount' => 5000, 'account' => $ewallet,
    ])->assertCreated()->assertJsonPath('data.account.name', 'Ví điện tử');
    expect(fund($owner, $tenant, 'Ví điện tử')['balance'])->toEqual(5000)->and(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual(100000);

    // The list and the cash book filter by fund.
    actingInTenant($owner, $tenant)->getJson("/api/v1/cash-vouchers?account={$ewallet}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('totals.in', 5000);
    $cash = fund($owner, $tenant, 'Tiền mặt')['id'];
    actingInTenant($owner, $tenant)->getJson("/api/v1/reports/cashbook?account={$cash}")->assertOk()
        ->assertJsonPath('data.closing', 100000)->assertJsonPath('data.account.name', 'Tiền mặt');
});

it('moves money between funds without changing the total or counting income, and voids both sides', function () {
    [$tenant, $owner] = tenantWithOwner();
    $cash = fund($owner, $tenant, 'Tiền mặt')['id'];
    $bank = fund($owner, $tenant, 'Tài khoản ngân hàng')['id'];
    actingInTenant($owner, $tenant)->patchJson("/api/v1/cash-accounts/{$cash}", ['opening_balance' => 2000000])->assertOk()->assertJsonPath('data.balance', 2000000);

    $transfer = actingInTenant($owner, $tenant)->postJson('/api/v1/cash-transfers', ['from' => $cash, 'to' => $bank, 'amount' => 1500000, 'fee' => 11000, 'reference' => 'NOP-01'])
        ->assertCreated()->assertJsonPath('data.direction', 'transfer')->assertJsonPath('data.number', 'CQ-000001')->json('data');

    expect(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual(489000)
        ->and(fund($owner, $tenant, 'Tài khoản ngân hàng')['balance'])->toEqual(1500000);
    // Across all funds: only the fee left the business, as an expense voucher.
    $all = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-accounts')->json('meta.total');
    expect($all)->toEqual(1989000);
    $list = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers')->assertOk();
    expect($list->json('totals'))->toMatchArray(['in' => 0, 'out' => 11000]);
    $book = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/cashbook')->assertOk()->json('data');
    expect($book)->toMatchArray(['opening' => 2000000, 'in' => 0, 'out' => 11000, 'closing' => 1989000]);
    inTenant($tenant);
    expect(CashVoucher::query()->where('counts_in_pnl', true)->sum('amount'))->toEqual(11000);

    // One fund's book shows its side of the transfer.
    actingInTenant($owner, $tenant)->getJson("/api/v1/reports/cashbook?account={$bank}")->assertOk()->assertJsonPath('data.in', 1500000)->assertJsonPath('data.rows.0.type', 'transfer_in');
    actingInTenant($owner, $tenant)->getJson("/api/v1/cash-vouchers?account={$cash}")->assertOk()->assertJsonPath('totals.out', 1511000);

    // Same fund on both sides, or an inactive fund: refused.
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-transfers', ['from' => $cash, 'to' => $cash, 'amount' => 1000])->assertUnprocessable();

    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-transfers/{$transfer['id']}/void", ['reason' => 'Nhập nhầm'])->assertOk()->assertJsonPath('data.void_reason', 'Nhập nhầm');
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-transfers/{$transfer['id']}/void", ['reason' => 'Lần nữa'])->assertUnprocessable();
    expect(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual(2000000)->and(fund($owner, $tenant, 'Tài khoản ngân hàng')['balance'])->toEqual(0);
});

it('keeps one default fund per payment method and protects funds in use', function () {
    [$tenant, $owner] = tenantWithOwner();
    $vcb = actingInTenant($owner, $tenant)->postJson('/api/v1/cash-accounts', [
        'kind' => 'bank', 'name' => 'VCB 6789', 'bank_name' => 'VCB', 'account_number' => '0011006789', 'holder' => 'NGUYEN VAN A', 'methods' => ['bank_transfer'],
    ])->assertCreated()->assertJsonPath('data.methods', ['bank_transfer'])->json('data.id');
    expect(fund($owner, $tenant, 'Tài khoản ngân hàng')['methods'])->toBe(['card']);
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-accounts', ['kind' => 'bank', 'name' => 'VCB 6789'])->assertUnprocessable();

    // A fund that receives a method by default cannot be turned off; once the method moves, it can.
    actingInTenant($owner, $tenant)->patchJson("/api/v1/cash-accounts/{$vcb}", ['is_active' => false])->assertUnprocessable();
    $old = fund($owner, $tenant, 'Tài khoản ngân hàng')['id'];
    actingInTenant($owner, $tenant)->patchJson("/api/v1/cash-accounts/{$old}", ['methods' => ['bank_transfer', 'card']])->assertOk();
    actingInTenant($owner, $tenant)->patchJson("/api/v1/cash-accounts/{$vcb}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    // Staff without finance.accounts see balances but only the last digits, and cannot manage funds.
    $cashId = fund($owner, $tenant, 'Tiền mặt')['id'];
    $manager = addMember($tenant, Role::MANAGER);
    $row = collect(actingInTenant($manager, $tenant)->getJson('/api/v1/cash-accounts')->assertOk()->json('data'))->firstWhere('name', 'VCB 6789');
    expect($row['account_number'])->toBe('••••6789');
    actingInTenant($manager, $tenant)->postJson('/api/v1/cash-transfers', ['from' => $old, 'to' => $cashId, 'amount' => 1000])->assertForbidden();
    actingInTenant(addMember($tenant, Role::WAREHOUSE_STAFF), $tenant)->getJson('/api/v1/cash-accounts')->assertForbidden();

    // Another business sees none of it.
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->patchJson("/api/v1/cash-accounts/{$vcb}", ['name' => 'X'])->assertNotFound();
    $otherCash = fund($otherOwner, $other, 'Tiền mặt')['id'];
    actingInTenant($otherOwner, $other)->postJson('/api/v1/cash-transfers', ['from' => $old, 'to' => $otherCash, 'amount' => 1000])->assertUnprocessable();
    inTenant($tenant);
    expect(CashAccount::query()->count())->toBe(6);
});
