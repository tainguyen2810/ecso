<?php

use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-20 05:00:00'))); // midday in Vietnam

function voucherOn($user, $tenant, string $direction, string $type, string $method, int $amount, string $day)
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => $direction, 'type' => cashType($user, $tenant, $direction, $type), 'party_kind' => 'other', 'party_name' => 'X',
        'method' => $method, 'amount' => $amount, 'paid_at' => "{$day} 10:00:00",
    ])->assertCreated();
}

it('sums the voucher list by direction, method and type, and compares with the period just before', function () {
    [$tenant, $owner] = tenantWithOwner();
    voucherOn($owner, $tenant, 'receipt', 'Thu nhập khác', 'bank_transfer', 300000, '2026-11-05');
    voucherOn($owner, $tenant, 'receipt', 'Thu nhập khác', 'cash', 100000, '2026-11-06');
    voucherOn($owner, $tenant, 'payment', 'Thuê mặt bằng', 'cash', 50000, '2026-11-07');
    voucherOn($owner, $tenant, 'payment', 'Điện, nước, internet', 'cash', 20000, '2026-11-08');
    // October, the 10 days just before 1–10/11: 22/10 to 31/10.
    voucherOn($owner, $tenant, 'receipt', 'Thu nhập khác', 'cash', 200000, '2026-10-25');
    voucherOn($owner, $tenant, 'payment', 'Thuê mặt bằng', 'cash', 70000, '2026-10-15'); // outside both periods
    $cash = fund($owner, $tenant, 'Tiền mặt')['id'];
    $bank = fund($owner, $tenant, 'Tài khoản ngân hàng')['id'];
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-transfers', ['from' => $cash, 'to' => $bank, 'amount' => 10000, 'transferred_at' => '2026-11-09 10:00:00'])->assertCreated();

    $totals = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?from=2026-11-01&to=2026-11-10')->assertOk()->json('totals');
    expect($totals)->toMatchArray(['in' => 400000, 'out' => 70000, 'count_in' => 2, 'count_out' => 2, 'transfers' => 1])
        ->and($totals['in_by_method'])->toBe([['method' => 'bank_transfer', 'amount' => 300000], ['method' => 'cash', 'amount' => 100000]])
        ->and($totals['out_by_type'][0])->toEqual(['type' => 'Thuê mặt bằng', 'amount' => 50000])
        ->and($totals['previous'])->toMatchArray(['from' => '2026-10-22', 'to' => '2026-10-31', 'in' => 200000, 'out' => 0]);

    // Without a period there is nothing to compare with.
    expect(actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers')->json('totals.previous'))->toBeNull();
});

it('lists funds with their last cash count, this month\'s change and the last COD reconciliation', function () {
    [$tenant, $owner] = tenantWithOwner();
    $cash = fund($owner, $tenant, 'Tiền mặt')['id'];
    actingInTenant($owner, $tenant)->patchJson("/api/v1/cash-accounts/{$cash}", ['opening_balance' => 1000000])->assertOk();
    voucherOn($owner, $tenant, 'receipt', 'Thu nhập khác', 'cash', 300000, '2026-10-20');
    voucherOn($owner, $tenant, 'payment', 'Điện, nước, internet', 'cash', 50000, '2026-11-10');
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-accounts/{$cash}/counts", ['counted_amount' => 1240000])->assertCreated();

    $res = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-accounts')->assertOk();
    $row = collect($res->json('data'))->firstWhere('id', $cash);
    expect($row['balance'])->toEqual(1240000)
        ->and($row['last_count']['difference'])->toEqual(-10000)
        ->and(collect($res->json('data'))->firstWhere('name', 'Ví điện tử')['last_count'])->toBeNull()
        // Since the end of October: −50.000 paid, −10.000 counted short.
        ->and($res->json('meta.month_change'))->toEqual(-60000)
        ->and($res->json('meta.last_cod_reconciliation'))->toBeNull();
});
