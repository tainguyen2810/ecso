<?php

it('records money a supplier pays back, up to what they owe, into a fund and closes the negative debt', function () {
    [$tenant, $owner] = tenantWithOwner();
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'NCC A'])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'cash', 'amount' => 100000])->assertCreated();
    actingInTenant($owner, $tenant)->getJson("/api/v1/suppliers/{$supplier}")->assertOk()->assertJsonPath('data.debt', '-100000.00');

    // More than they owe back is refused; a nothing-owed supplier cannot refund at all.
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/refunds", ['method' => 'cash', 'amount' => 150000])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $refund = actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/refunds", ['method' => 'bank_transfer', 'amount' => 60000, 'reference' => 'HOAN-01'])
        ->assertCreated()->assertJsonPath('data.kind', 'refund')->json('data.id');

    $detail = actingInTenant($owner, $tenant)->getJson("/api/v1/suppliers/{$supplier}")->assertOk();
    expect((float) $detail->json('data.debt'))->toEqual(-40000.0)->and((float) $detail->json('data.paid'))->toEqual(40000.0);
    expect(fund($owner, $tenant, 'Tài khoản ngân hàng')['balance'])->toEqual(60000)->and(fund($owner, $tenant, 'Tiền mặt')['balance'])->toEqual(-100000);

    // In the voucher list it is a receipt; the supplier payments report leaves it out.
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?type=supplier_payment')->assertOk()->assertJsonPath('totals.in', 60000)->assertJsonPath('totals.out', 100000);
    $report = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/supplier-payments')->assertOk();
    expect((float) $report->json('data.totals.paid'))->toEqual(100000.0);

    // Voiding the refund puts the negative debt back.
    actingInTenant($owner, $tenant)->postJson("/api/v1/supplier-payments/{$refund}/void", ['reason' => 'Nhầm'])->assertOk();
    expect((float) actingInTenant($owner, $tenant)->getJson("/api/v1/suppliers/{$supplier}")->json('data.debt'))->toEqual(-100000.0);

    $other = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'NCC B'])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$other}/refunds", ['method' => 'cash', 'amount' => 1000])->assertUnprocessable()->assertJsonValidationErrors('amount');
});
