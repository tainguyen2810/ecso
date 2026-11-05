<?php

use App\Modules\Access\Models\Role;
use App\Shared\Support\ReportRange;

function payVoucher($user, $tenant, int $amount, array $extra = [])
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'payment', 'type' => cashType($user, $tenant, 'payment', 'Thuê mặt bằng'), 'party_kind' => 'other', 'party_name' => 'Chủ nhà',
        'method' => 'cash', 'amount' => $amount, ...$extra,
    ]);
}

beforeEach(fn () => $this->travelTo(now()->setTime(5, 0))); // midday in Vietnam

it('holds large payments for approval: they count nowhere until approved, and a refused one stays voided', function () {
    [$tenant, $owner] = tenantWithOwner();
    $manager = addMember($tenant, Role::MANAGER);
    $cash = fn () => fund($owner, $tenant, 'Tiền mặt')['balance'];

    // Off by default.
    payVoucher($manager, $tenant, 9000000)->assertCreated()->assertJsonPath('data.status', 'completed');
    actingInTenant($owner, $tenant)->putJson('/api/v1/tenant/settings/payments', ['approval_threshold' => 5000000])->assertOk();
    $before = $cash();

    payVoucher($manager, $tenant, 1000000)->assertCreated()->assertJsonPath('data.status', 'completed');
    $big = payVoucher($manager, $tenant, 6000000)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    $other = payVoucher($manager, $tenant, 5000000)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    // Whoever may approve does not wait for themselves.
    payVoucher($owner, $tenant, 7000000)->assertCreated()->assertJsonPath('data.status', 'completed');

    expect($cash())->toEqual($before - 8000000);
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?status=pending')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('pending', 2)->assertJsonPath('approval_threshold', '5000000')
        ->assertJsonPath('totals.out', 0);

    // A pending one cannot be voided, only refused; the manager cannot approve.
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$big}/void", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('voucher');
    actingInTenant($manager, $tenant)->postJson("/api/v1/cash-vouchers/{$big}/approve")->assertForbidden();

    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$big}/approve")->assertOk()->assertJsonPath('data.status', 'completed');
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$big}/approve")->assertUnprocessable();
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$other}/reject", ['reason' => 'Chưa có hóa đơn'])->assertOk()
        ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.void_reason', 'Chưa có hóa đơn');

    expect($cash())->toEqual($before - 14000000);
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?status=pending')->assertJsonCount(0, 'data');
});

it('voids a voucher of a closed day with a reversal today: the closed day keeps its figures', function () {
    [$tenant, $owner] = tenantWithOwner();
    $threeDaysAgo = ReportRange::now()->subDays(3);
    $yesterday = ReportRange::now()->subDay()->format('Y-m-d');
    $old = actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => cashType($owner, $tenant, 'receipt', 'Thu nhập khác'), 'party_kind' => 'other', 'party_name' => 'Khách',
        'method' => 'cash', 'amount' => 100000, 'paid_at' => $threeDaysAgo->setTime(9, 0)->toIso8601String(),
    ])->assertCreated()->json('data.id');
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-closings', ['closed_until' => $yesterday])->assertCreated();

    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$old}/void", ['reason' => 'Ghi nhầm'])->assertOk()->assertJsonPath('data.status', 'reversed');
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$old}/void", ['reason' => 'Ghi nhầm'])->assertUnprocessable();

    // The closed day still holds the money; today it goes out again.
    expect(collect(actingInTenant($owner, $tenant)->getJson("/api/v1/cash-accounts?at={$yesterday}")->json('data'))->firstWhere('name', 'Tiền mặt')['balance'])->toEqual(100000)
        ->and(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual(0);
    $day = $threeDaysAgo->format('Y-m-d');
    actingInTenant($owner, $tenant)->getJson("/api/v1/cash-vouchers?from={$day}&to={$day}")->assertJsonPath('totals.in', 100000);
    $today = ReportRange::now()->format('Y-m-d');
    $rows = actingInTenant($owner, $tenant)->getJson("/api/v1/cash-vouchers?from={$today}&to={$today}")->assertJsonPath('totals.out', 100000)->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['source'])->toBe('voucher_reversal')->and($rows[0]['direction'])->toBe('payment')
        ->and($rows[0]['note'])->toBe('Ghi nhầm');
    actingInTenant($owner, $tenant)->getJson("/api/v1/cash-vouchers/voucher_reversal/{$old}")->assertOk()->assertJsonPath('data.document.number', $rows[0]['number']);
});

it('shows fund balances only to members with finance.balances', function () {
    [$tenant, $owner] = tenantWithOwner();
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-accounts')->assertOk()->assertJsonPath('meta.total', 0);
    $accountant = addMember($tenant, Role::ACCOUNTANT);
    revoke(Role::ACCOUNTANT, 'finance.balances');
    actingInTenant($accountant, $tenant)->getJson('/api/v1/cash-accounts')->assertOk()
        ->assertJsonPath('meta.total', null)->assertJsonPath('data.0.balance', null);
    actingInTenant($accountant, $tenant)->getJson('/api/v1/reports/cashbook')->assertOk()
        ->assertJsonPath('data.opening', null)->assertJsonPath('data.closing', null);
});
