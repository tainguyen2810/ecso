<?php

use App\Modules\Access\Models\Role;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Shared\Support\ReportRange;

/** A hand-entered receipt of $amount dated $at (Y-m-d H:i, shop time). */
function receiptAt($user, $tenant, int $amount, string $at): string
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => cashType($user, $tenant, 'receipt', 'Thu nhập khác'), 'party_kind' => 'other', 'party_name' => 'Khách',
        'method' => 'cash', 'amount' => $amount, 'paid_at' => ReportRange::date(substr($at, 0, 10))->setTimeFromTimeString(substr($at, 11))->toIso8601String(),
    ])->assertCreated()->json('data.id');
}

beforeEach(fn () => $this->travelTo(now()->setTime(5, 0))); // midday in Vietnam

it('closes the books: nothing on or before the closed day can be recorded, voided or backdated, until reopened', function () {
    [$tenant, $owner] = tenantWithOwner();
    $threeDaysAgo = ReportRange::now()->subDays(3)->format('Y-m-d');
    $yesterday = ReportRange::now()->subDay()->format('Y-m-d');
    $old = receiptAt($owner, $tenant, 100000, "{$threeDaysAgo} 09:00");
    $cash = fund($owner, $tenant, 'Tiền mặt')['id'];

    // Today cannot be closed; yesterday can, with the balances at its end kept.
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-closings', ['closed_until' => ReportRange::now()->format('Y-m-d')])->assertUnprocessable()->assertJsonValidationErrors('closed_until');
    $closing = actingInTenant($owner, $tenant)->postJson('/api/v1/cash-closings', ['closed_until' => $yesterday, 'note' => 'Chốt tuần'])
        ->assertCreated()->assertJsonPath('data.total', 100000)->json('data');
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-accounts')->assertJsonPath('meta.closed_until', $yesterday);

    // Inside the closed period: no new voucher, no void, no transfer, no payment, no opening balance change.
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => cashType($owner, $tenant, 'receipt', 'Thu nhập khác'), 'party_kind' => 'other', 'party_name' => 'X',
        'method' => 'cash', 'amount' => 5000, 'paid_at' => ReportRange::date($yesterday)->setTime(20, 0)->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors('paid_at');
    $voucher = CashVoucher::withoutGlobalScopes()->where('ulid', $old)->firstOrFail();
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$old}/void", ['reason' => 'Nhầm'])->assertUnprocessable()->assertJsonValidationErrors('voucher');
    $bank = fund($owner, $tenant, 'Tài khoản ngân hàng')['id'];
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-transfers', ['from' => $cash, 'to' => $bank, 'amount' => 1000, 'transferred_at' => ReportRange::date($yesterday)->setTime(10, 0)->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('transferred_at');
    actingInTenant($owner, $tenant)->patchJson("/api/v1/cash-accounts/{$cash}", ['opening_balance' => 50000])->assertUnprocessable()->assertJsonValidationErrors('opening_balance');

    // Today is open, and a voucher made today with the closed day's mistake is the way to fix it.
    receiptAt($owner, $tenant, 7000, ReportRange::now()->format('Y-m-d').' 08:00');
    expect($voucher->fresh()->voided_at)->toBeNull();

    // Closing again must move forward; reopening opens the period again.
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-closings', ['closed_until' => $threeDaysAgo])->assertUnprocessable();
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-closings/{$closing['id']}/reopen", ['reason' => 'Sửa phiếu nhập nhầm'])->assertOk()->assertJsonPath('data.reopen_reason', 'Sửa phiếu nhập nhầm');
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$old}/void", ['reason' => 'Nhầm'])->assertOk();
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-closings')->assertOk()->assertJsonPath('meta.closed_until', null)->assertJsonPath('data.0.reopened_by', $owner->name);
});

it('blocks voiding an order payment or a supplier payment of a closed day', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 5);
    $order = cashSale($owner, $tenant, $variant, 300000, actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Chị Lan', 'phone' => '0901234567'])->json('data.id'));
    $twoDaysAgo = ReportRange::now()->subDays(2)->setTime(9, 0)->toIso8601String();
    $payment = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order}/payments", ['method' => 'cash', 'amount' => 100000, 'paid_at' => $twoDaysAgo])->assertCreated()->json('data.id');
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'NCC A'])->json('data.id');
    $sp = actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'cash', 'amount' => 40000, 'paid_at' => $twoDaysAgo])->assertCreated()->json('data.id');

    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-closings', ['closed_until' => ReportRange::now()->subDay()->format('Y-m-d')])->assertCreated();
    actingInTenant($owner, $tenant)->postJson("/api/v1/payments/{$payment}/void", ['reason' => 'Nhầm'])->assertUnprocessable()->assertJsonValidationErrors('payment');
    actingInTenant($owner, $tenant)->postJson("/api/v1/supplier-payments/{$sp}/void", ['reason' => 'Nhầm'])->assertUnprocessable()->assertJsonValidationErrors('payment');
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order}/payments", ['method' => 'cash', 'amount' => 1000, 'paid_at' => $twoDaysAgo])->assertUnprocessable()->assertJsonValidationErrors('paid_at');
});

it('counts a fund and books the difference so the balance equals what was counted', function () {
    [$tenant, $owner] = tenantWithOwner();
    receiptAt($owner, $tenant, 500000, ReportRange::now()->format('Y-m-d').' 08:00');
    $cash = fund($owner, $tenant, 'Tiền mặt')['id'];

    $short = actingInTenant($owner, $tenant)->postJson("/api/v1/cash-accounts/{$cash}/counts", ['counted_amount' => 480000, 'note' => 'Cuối ngày'])
        ->assertCreated()->assertJsonPath('data.system_balance', 500000)->assertJsonPath('data.difference', -20000)->json('data');
    expect($short['voucher']['number'])->toStartWith('PC-')
        ->and(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual(480000);
    $voucher = CashVoucher::withoutGlobalScopes()->where('ulid', $short['voucher']['id'])->firstOrFail();
    expect($voucher->counts_in_pnl)->toBeFalse()->and((float) $voucher->amount)->toEqual(20000.0);

    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-accounts/{$cash}/counts", ['counted_amount' => 490000])->assertCreated()->assertJsonPath('data.difference', 10000);
    expect(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual(490000);
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-accounts/{$cash}/counts", ['counted_amount' => 490000])->assertCreated()->assertJsonPath('data.voucher', null);

    actingInTenant($owner, $tenant)->getJson("/api/v1/cash-accounts/{$cash}/counts")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.difference', 0);
});

it('needs finance.vouchers.void to void and finance.close to count or close, and keeps closings per business', function () {
    [$tenant, $owner] = tenantWithOwner();
    $accountant = addMember($tenant, Role::ACCOUNTANT);
    $voucher = receiptAt($owner, $tenant, 1000, ReportRange::now()->format('Y-m-d').' 08:00');
    $cash = fund($owner, $tenant, 'Tiền mặt')['id'];

    revoke(Role::ACCOUNTANT, 'finance.vouchers.void');
    revoke(Role::ACCOUNTANT, 'finance.close');
    actingInTenant($accountant, $tenant)->postJson("/api/v1/cash-vouchers/{$voucher}/void", ['reason' => 'x'])->assertForbidden();
    actingInTenant($accountant, $tenant)->postJson('/api/v1/cash-closings', ['closed_until' => ReportRange::now()->subDay()->format('Y-m-d')])->assertForbidden();
    actingInTenant($accountant, $tenant)->postJson("/api/v1/cash-accounts/{$cash}/counts", ['counted_amount' => 0])->assertForbidden();
    actingInTenant($accountant, $tenant)->getJson('/api/v1/cash-closings')->assertOk();

    // Another business is not closed by this one's closing.
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-closings', ['closed_until' => ReportRange::now()->subDay()->format('Y-m-d')])->assertCreated();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->getJson('/api/v1/cash-closings')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.closed_until', null);
    receiptAt($otherOwner, $other, 1000, ReportRange::now()->subDays(2)->format('Y-m-d').' 08:00');
});
