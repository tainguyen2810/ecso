<?php

use App\Modules\Sales\Models\Customer;
use Carbon\CarbonImmutable;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn () => actingInTenant($this->owner, $this->tenant);
    $this->ids = fn (string $query) => collect(($this->as)()->getJson("/api/v1/customers?{$query}")->assertOk()->json('data'))->pluck('id')->all();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('finds a customer by the full phone or its last four digits, never by fewer digits', function () {
    $an = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Anh An', 'phone' => '0912 345 678'])->assertCreated()->json('data.id');
    ($this->as)()->postJson('/api/v1/customers', ['name' => 'Chị Bình', 'phone' => '0987654321'])->assertCreated();

    expect(($this->ids)('q=5678'))->toBe([$an])
        ->and(($this->ids)('q=0912345678'))->toBe([$an])
        ->and(($this->ids)('q=%2B84912345678'))->toBe([$an])
        ->and(($this->ids)('q=678'))->toBe([]);
});

it('reads the date filters as days on the shop clock, not UTC', function () {
    $variant = makeVariant($this->tenant);
    stockIn($this->tenant, $variant, 5);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'));
    $late = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Khách mua khuya'])->assertCreated()->json('data.id');

    // 2/11 00:30 in Ho Chi Minh City is still 1/11 17:30 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 17:30:00', 'UTC'));
    cashSale($this->owner, $this->tenant, $variant, 100000, $late);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 18:00:00', 'UTC'));
    $created = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Khách tạo khuya'])->assertCreated()->json('data.id');

    expect(($this->ids)('last_order_from=2026-11-02&last_order_to=2026-11-02'))->toBe([$late])
        ->and(($this->ids)('last_order_to=2026-11-01'))->toBe([])
        ->and(($this->ids)('created_from=2026-11-02'))->toBe([$created])
        ->and(($this->ids)('created_to=2026-11-01'))->toContain($late)->not->toContain($created);

    // 3/11 00:10 local: one day since the last purchase, not two.
    $this->travelTo(CarbonImmutable::parse('2026-11-02 17:10:00', 'UTC'));
    ($this->as)()->getJson("/api/v1/customers/{$late}")->assertOk()->assertJsonPath('data.summary.days_since_last_order', 1);
});

it('refuses to delete a customer that carries money or history, one by one and in bulk', function () {
    $fresh = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Khách mới'])->assertCreated()->json('data.id');
    $opening = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Khách nợ cũ'])->assertCreated()->json('data.id');
    ($this->as)()->postJson("/api/v1/customers/{$opening}/debt-entries", ['kind' => 'opening', 'amount' => 200000, 'reason' => 'Nợ sổ tay'])->assertCreated();
    $voucher = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Khách có phiếu'])->assertCreated()->json('data.id');
    ($this->as)()->postJson('/api/v1/cash-vouchers', [
        'direction' => 'receipt', 'type' => cashType($this->owner, $this->tenant, 'receipt', 'Thu nhập khác'),
        'party_kind' => 'customer', 'party_id' => $voucher, 'method' => 'cash', 'amount' => 50000,
    ])->assertCreated();

    ($this->as)()->deleteJson("/api/v1/customers/{$opening}")->assertUnprocessable()->assertJsonValidationErrors('customer');
    ($this->as)()->deleteJson("/api/v1/customers/{$voucher}")->assertUnprocessable()->assertJsonValidationErrors('customer');

    ($this->as)()->postJson('/api/v1/customers/bulk', ['ids' => [$fresh, $opening, $voucher], 'action' => 'delete'])->assertOk()
        ->assertJsonPath('updated', 1)->assertJsonCount(2, 'skipped');

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->pluck('ulid')->all())
        ->toEqualCanonicalizing([$opening, $voucher]);
    $this->assertDatabaseHas('cash_vouchers', ['customer_id' => Customer::withoutGlobalScopes()->where('ulid', $voucher)->value('id')]);
});
