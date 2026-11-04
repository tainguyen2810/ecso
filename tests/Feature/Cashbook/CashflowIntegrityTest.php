<?php

use App\Modules\Analytics\Services\BusinessReports;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Sales\Models\OrderPayment;
use App\Shared\Support\ReportRange;
use Illuminate\Support\Str;

it('records a receipt voucher once per idempotency key, and refuses the key for another body', function () {
    [$tenant, $owner] = tenantWithOwner();
    $income = cashType($owner, $tenant, 'receipt', 'Thu nhập khác');
    $body = ['direction' => 'receipt', 'type' => $income, 'party_kind' => 'other', 'party_name' => 'Khách vãng lai', 'method' => 'cash', 'amount' => 150000];
    $key = (string) Str::uuid();

    $first = postOnce($owner, $tenant, '/api/v1/cash-vouchers', $body, $key)->assertCreated()->json('data');
    // A retry after a lost response returns the same voucher, not a second one.
    postOnce($owner, $tenant, '/api/v1/cash-vouchers', $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first['id'])->assertJsonPath('data.number', $first['number']);
    postOnce($owner, $tenant, '/api/v1/cash-vouchers', [...$body, 'amount' => 200000], $key)->assertUnprocessable();
    // Without a key, or with a new one, a second voucher is a second voucher.
    postOnce($owner, $tenant, '/api/v1/cash-vouchers', $body, (string) Str::uuid())->assertCreated()->assertJsonPath('data.number', 'PT-000002');
    actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [...$body, 'amount' => 1000])->assertCreated();

    inTenant($tenant);
    expect(CashVoucher::query()->count())->toBe(3)
        ->and(actingInTenant($owner, $tenant)->getJson('/api/v1/cash-vouchers')->json('totals.in'))->toEqual(301000);

    // The same key in another business is another request.
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $otherIncome = cashType($otherOwner, $other, 'receipt', 'Thu nhập khác');
    postOnce($otherOwner, $other, '/api/v1/cash-vouchers', [...$body, 'type' => $otherIncome], $key)->assertCreated()->assertJsonPath('data.number', 'PT-000001');
});

it('records an order payment and a supplier payment once per key', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 5);
    $customer = actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Chị Lan', 'phone' => '0901234567'])->assertCreated()->json('data.id');
    $order = cashSale($owner, $tenant, $variant, 300000, $customer);
    $key = (string) Str::uuid();

    $pay = ['method' => 'cash', 'amount' => 100000];
    $id = postOnce($owner, $tenant, "/api/v1/orders/{$order}/payments", $pay, $key)->assertCreated()->json('data.id');
    postOnce($owner, $tenant, "/api/v1/orders/{$order}/payments", $pay, $key)->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $id);
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order}")->assertJsonPath('data.paid_amount', '100000.00');

    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'NCC A'])->json('data.id');
    $sKey = (string) Str::uuid();
    $sid = postOnce($owner, $tenant, "/api/v1/suppliers/{$supplier}/payments", ['method' => 'bank_transfer', 'amount' => 50000], $sKey)->assertCreated()->json('data.id');
    postOnce($owner, $tenant, "/api/v1/suppliers/{$supplier}/payments", ['method' => 'bank_transfer', 'amount' => 50000], $sKey)->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $sid);

    inTenant($tenant);
    expect(OrderPayment::query()->where('kind', 'payment')->count())->toBe(1)
        ->and(SupplierPayment::query()->count())->toBe(1);
});

it('collects a customer debt once per key even when it is split over several orders', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 5);
    $customer = actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Anh Minh', 'phone' => '0912345678'])->assertCreated()->json('data.id');
    cashSale($owner, $tenant, $variant, 100000, $customer);
    cashSale($owner, $tenant, $variant, 100000, $customer);
    $key = (string) Str::uuid();

    $body = ['method' => 'cash', 'amount' => 150000];
    $ids = collect(postOnce($owner, $tenant, "/api/v1/customers/{$customer}/debt-receipts", $body, $key)->assertCreated()->json('data'))->pluck('id');
    expect($ids)->toHaveCount(2);
    $again = collect(postOnce($owner, $tenant, "/api/v1/customers/{$customer}/debt-receipts", $body, $key)->assertHeader('Idempotent-Replayed', 'true')->json('data'))->pluck('id');
    expect($again->all())->toEqual($ids->all());
    postOnce($owner, $tenant, "/api/v1/customers/{$customer}/debt-receipts", ['method' => 'cash', 'amount' => 50000], $key)->assertUnprocessable();

    actingInTenant($owner, $tenant)->getJson("/api/v1/customers/{$customer}/debt")->assertJsonPath('data.debt', 50000);
});

it('adds up the whole period in the cash book even when only the newest rows are listed', function () {
    [$tenant, $owner] = tenantWithOwner();
    $income = cashType($owner, $tenant, 'receipt', 'Thu nhập khác');
    $ads = cashType($owner, $tenant, 'payment', 'Chi phí quảng cáo');
    $at = fn (int $hours) => now()->subHours($hours)->toIso8601String();
    $voucher = fn (string $direction, string $type, int $amount, int $hoursAgo) => actingInTenant($owner, $tenant)->postJson('/api/v1/cash-vouchers', [
        'direction' => $direction, 'type' => $type, 'party_kind' => 'other', 'party_name' => 'X', 'method' => 'cash', 'amount' => $amount, 'paid_at' => $at($hoursAgo),
    ])->assertCreated();
    $voucher('receipt', $income, 100000, 4);
    $voucher('receipt', $income, 50000, 3);
    $voucher('payment', $ads, 30000, 2);
    $voucher('receipt', $income, 10000, 1);

    inTenant($tenant);
    $today = ReportRange::now();
    $book = app(BusinessReports::class)->cashbook($today->subDay()->startOfDay(), $today->endOfDay(), null, 2);

    expect($book)->toMatchArray(['opening' => 0, 'in' => 160000, 'out' => 30000, 'closing' => 130000, 'count' => 4, 'truncated' => true])
        ->and($book['rows'])->toHaveCount(2)
        // Newest first, balances worked back from the closing balance.
        ->and($book['rows'][0]['balance'])->toEqual(130000)
        ->and($book['rows'][1]['balance'])->toEqual(120000)
        ->and($book['by_method'])->toEqual([['method' => 'cash', 'in' => 160000, 'out' => 30000]]);

    $full = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/cashbook?from='.$today->subDay()->toDateString())->assertOk()->json('data');
    expect($full['truncated'])->toBeFalse()->and($full['rows'])->toHaveCount(4)->and(end($full['rows'])['balance'])->toEqual(100000);
});
