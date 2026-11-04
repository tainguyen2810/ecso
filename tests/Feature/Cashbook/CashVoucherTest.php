<?php

use App\Modules\Access\Models\Role;
use App\Modules\Sales\Models\SalesChannel;

/** A completed counter sale: paid in cash for a walk-in buyer, on credit for a customer. */
function voucherSale($user, $tenant, $variant, int $price, ?string $customer = null)
{
    $channel = SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', SalesChannel::DEFAULT_NAME)->firstOrFail()->ulid;

    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => $channel,
        'stage' => 'completed',
        'customer_id' => $customer,
        'payments' => $customer ? [] : [['method' => 'cash', 'amount' => $price]],
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1, 'unit_price' => $price]],
    ])->assertCreated()->json('data.id');
}

function voucherType($user, $tenant, string $direction, string $name): string
{
    $types = actingInTenant($user, $tenant)->getJson("/api/v1/cash-voucher-types?direction={$direction}")->assertOk()->json('data');

    return collect($types)->firstWhere('name', $name)['id'];
}

it('starts every business with editable receipt and payment types', function () {
    [$tenant, $owner] = tenantWithOwner();

    $types = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-voucher-types')->assertOk()->json('data');
    expect(collect($types)->where('direction', 'receipt')->pluck('name'))->toContain('Thu nhập khác')
        ->and(collect($types)->where('direction', 'payment')->pluck('name'))->toContain('Chi phí quảng cáo')
        ->and(collect($types)->firstWhere('name', 'Chủ rút vốn')['counts_in_pnl'])->toBeFalse();

    // Opening the list again does not duplicate them.
    expect(actingInTenant($owner, $tenant)->getJson('/api/v1/cash-voucher-types')->json('data'))->toHaveCount(count($types));

    $hosting = actingInTenant($owner, $tenant)->postJson('/api/v1/cash-voucher-types', ['direction' => 'payment', 'name' => 'Hosting'])
        ->assertCreated()->assertJsonPath('data.counts_in_pnl', true)->json('data.id');
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-voucher-types', ['direction' => 'payment', 'name' => 'Hosting'])->assertUnprocessable();
    actingInTenant($owner, $tenant)->patchJson("/api/v1/cash-voucher-types/{$hosting}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    // An inactive type cannot be used on a new voucher.
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'payment', 'type' => $hosting, 'party_kind' => 'other', 'party_name' => 'Nhà cung cấp hosting', 'method' => 'bank_transfer', 'amount' => 300000,
    ])->assertUnprocessable()->assertJsonValidationErrors('type');
});

it('records receipt and payment vouchers, lists them with automatic ones and voids them', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 5);
    voucherSale($owner, $tenant, $variant, 200000);
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'NCC A'])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'bank_transfer', 'amount' => 50000])->assertCreated();

    $ads = voucherType($owner, $tenant, 'payment', 'Chi phí quảng cáo');
    $first = actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'payment', 'type' => $ads, 'party_kind' => 'supplier', 'party_id' => $supplier, 'method' => 'bank_transfer', 'amount' => 1000000, 'reference' => 'GOOGLE_ADS_T10',
    ])->assertCreated()->assertJsonPath('data.number', 'PC-000001')->assertJsonPath('data.party.name', 'NCC A')->assertJsonPath('data.counts_in_pnl', true)->json('data');
    $income = voucherType($owner, $tenant, 'receipt', 'Thu nhập khác');
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => $income, 'party_kind' => 'other', 'party_name' => 'Bán thùng carton cũ', 'method' => 'cash', 'amount' => 30000,
    ])->assertCreated()->assertJsonPath('data.number', 'PT-000001');
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => $ads, 'party_kind' => 'other', 'party_name' => 'X', 'method' => 'cash', 'amount' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('type');
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => $income, 'party_kind' => 'other', 'method' => 'cash', 'amount' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('party_name');

    $payments = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?direction=payment')->assertOk();
    expect($payments->json('meta.total'))->toBe(2)
        ->and($payments->json('totals.out'))->toEqual(1050000)
        ->and($payments->json('totals.in'))->toEqual(0)
        ->and(collect($payments->json('data'))->pluck('source')->sort()->values()->all())->toBe(['supplier_payment', 'voucher']);
    $receipts = actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?direction=receipt')->json('data');
    expect(collect($receipts)->firstWhere('source', 'order_payment')['type']['name'])->toBe('Thu tiền đơn hàng');
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?q=GOOGLE_ADS')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first['id']);
    actingInTenant($owner, $tenant)->getJson("/api/v1/cash-vouchers?type={$ads}")->assertJsonPath('meta.total', 1);
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?type=supplier_payment')->assertJsonPath('meta.total', 1);
    actingInTenant($owner, $tenant)->getJson("/api/v1/cash-vouchers/voucher/{$first['id']}")->assertOk()->assertJsonPath('data.number', 'PC-000001');

    // Vouchers show up in the cashbook, and in profit and loss when their type counts there.
    $book = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/cashbook')->assertOk()->json('data');
    expect($book)->toMatchArray(['in' => 230000, 'out' => 1050000, 'closing' => -820000])
        ->and(collect($book['rows'])->firstWhere('type', 'voucher')['document']['number'])->not->toBeNull();
    $finance = actingInTenant($owner, $tenant)->getJson('/api/v1/finance/summary')->assertOk()->json('data');
    expect($finance['other_income'])->toEqual(30000)->and($finance['other_expenses'])->toEqual(1000000);
    $pl = collect(actingInTenant($owner, $tenant)->getJson('/api/v1/reports/profit-loss')->assertOk()->json('data.lines'))->keyBy('key');
    expect($pl['other_income']['current'])->toEqual(30000)
        // Advertising is a selling expense: it lowers contribution profit.
        ->and($pl['selling_expenses']['current'])->toEqual(1000000)
        ->and($pl['operating_expenses']['current'])->toEqual(0)
        ->and($pl['contribution_profit']['current'])->toEqual($pl['gross_profit']['current'] - $pl['marketplace_fees']['current'] + $pl['shipping']['current'] - 1000000)
        ->and($pl['profit']['current'])->toEqual($pl['contribution_profit']['current'] - $pl['stock_loss']['current'] + 30000);

    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$first['id']}/void", [])->assertUnprocessable();
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$first['id']}/void", ['reason' => 'Nhập nhầm'])
        ->assertOk()->assertJsonPath('data.void_reason', 'Nhập nhầm');
    actingInTenant($owner, $tenant)->postJson("/api/v1/cash-vouchers/{$first['id']}/void", ['reason' => 'Lần nữa'])->assertUnprocessable();
    actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers?status=voided')->assertJsonPath('meta.total', 1);
    expect(actingInTenant($owner, $tenant)->getJson('/api/v1/reports/cashbook')->json('data.out'))->toEqual(50000);
});

it('collects a customer debt over their unpaid orders, oldest first', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 5);
    $customer = actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Chị Lan', 'phone' => '0901234567'])->assertCreated()->json('data.id');
    $older = voucherSale($owner, $tenant, $variant, 100000, $customer);
    $newer = voucherSale($owner, $tenant, $variant, 150000, $customer);

    actingInTenant($owner, $tenant)->getJson("/api/v1/customers/{$customer}/debt")->assertOk()
        ->assertJsonPath('data.debt', 250000)->assertJsonPath('data.orders.0.id', $older);
    actingInTenant($owner, $tenant)->postJson("/api/v1/customers/{$customer}/debt-receipts", ['method' => 'cash', 'amount' => 300000])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');

    $payments = actingInTenant($owner, $tenant)->postJson("/api/v1/customers/{$customer}/debt-receipts", ['method' => 'cash', 'amount' => 180000])
        ->assertCreated()->json('data');
    expect(collect($payments)->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([100000.0, 80000.0]);

    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$older}")->assertJsonPath('data.payment_status', 'paid');
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$newer}")->assertJsonPath('data.payment_status', 'partial');
    actingInTenant($owner, $tenant)->getJson("/api/v1/customers/{$customer}/debt")->assertJsonPath('data.debt', 70000);
});

it('lets finance staff read vouchers and only finance.vouchers write them, per business', function () {
    [$tenant, $owner] = tenantWithOwner();
    $income = voucherType($owner, $tenant, 'receipt', 'Thu nhập khác');
    $voucher = actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => $income, 'party_kind' => 'staff', 'party_id' => actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers/staff')->json('data.0.id'),
        'method' => 'cash', 'amount' => 50000,
    ])->assertCreated()->assertJsonPath('data.party.name', $owner->name)->json('data.id');

    $accountant = addMember($tenant, Role::ACCOUNTANT);
    actingInTenant($accountant, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => $income, 'party_kind' => 'other', 'party_name' => 'Khách vãng lai', 'method' => 'cash', 'amount' => 10000,
    ])->assertCreated();

    $viewer = addMember($tenant, Role::VIEWER);
    actingInTenant($viewer, $tenant)->getJson('/api/v1/cash-vouchers')->assertForbidden();
    $warehouse = addMember($tenant, Role::WAREHOUSE_STAFF);
    actingInTenant($warehouse, $tenant)->postJson("/api/v1/cash-vouchers/{$voucher}/void", ['reason' => 'x'])->assertForbidden();

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->getJson('/api/v1/cash-vouchers')->assertOk()->assertJsonPath('meta.total', 0);
    actingInTenant($otherOwner, $other)->postJson("/api/v1/cash-vouchers/{$voucher}/void", ['reason' => 'x'])->assertNotFound();
    actingInTenant($otherOwner, $other)->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => $income, 'party_kind' => 'other', 'party_name' => 'X', 'method' => 'cash', 'amount' => 1,
    ])->assertUnprocessable();
});
