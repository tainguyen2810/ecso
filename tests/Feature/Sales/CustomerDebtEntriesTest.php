<?php

use App\Modules\Access\Models\Role;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
    $this->variant = makeVariant($this->tenant);
    stockIn($this->tenant, $this->variant, 50);
    $this->customer = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Cửa hàng Minh Phát', 'phone' => '0281234567'])->assertCreated()->json('data.id');
    $this->show = fn () => ($this->as)()->getJson("/api/v1/customers/{$this->customer}")->assertOk()->json('data');
});

it('keeps opening balances and adjustments as documents with a reason, collected before orders', function () {
    ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-entries", ['kind' => 'opening', 'amount' => 500000, 'reason' => 'Nợ cũ chuyển từ sổ tay', 'entry_date' => now()->subMonth()->toDateString(), 'due_date' => now()->subDay()->toDateString()])
        ->assertCreated()->assertJsonPath('data.amount', '500000.00');
    ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-entries", ['kind' => 'opening', 'amount' => 1000, 'reason' => 'Lần hai'])->assertUnprocessable();
    ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-entries", ['kind' => 'adjustment', 'amount' => 100000, 'reason' => ''])->assertUnprocessable();
    $discount = ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-entries", ['kind' => 'adjustment', 'amount' => -50000, 'reason' => 'Giảm trừ hàng lỗi'])->assertCreated()->json('data');
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 100000]], ['customer_id' => $this->customer, 'stage' => 'completed'])->assertCreated();

    expect(($this->show)())->toMatchArray(['debt' => '650000.00', 'overdue' => '500000.00']);
    $ledger = ($this->as)()->getJson("/api/v1/customers/{$this->customer}/ledger")->json('data');
    expect($ledger[0]['balance'])->toBe('650000.00')->and(collect($ledger)->pluck('type')->sort()->values()->all())->toBe(['adjustment', 'opening', 'order']);
    $row = collect(($this->as)()->getJson('/api/v1/reports/receivables')->json('data.rows'))->firstWhere('customer.id', $this->customer);
    expect($row['due'])->toEqual(650000)->and($row['overdue'])->toEqual(500000);

    // 600 000 received: 450 000 settles the old balance (a cash book voucher), 150 000 goes to the order.
    $receipt = ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-receipts", ['method' => 'cash', 'amount' => 600000])->assertCreated();
    expect($receipt->json('voucher.amount'))->toBe('450000.00')->and(collect($receipt->json('data'))->sum('amount'))->toEqual(150000);
    expect(($this->show)()['debt'])->toBe('50000.00');
    $voucher = $receipt->json('voucher.id');
    expect(collect(($this->as)()->getJson('/api/v1/cash-vouchers?direction=receipt')->json('data'))->pluck('number'))->toContain($receipt->json('voucher.number'));

    // Voiding the voucher in the cash book puts the old balance back; an adjustment is voided with a reason.
    ($this->as)()->postJson("/api/v1/cash-vouchers/{$voucher}/void", ['reason' => 'Nhập nhầm'])->assertOk();
    expect(($this->show)()['debt'])->toBe('500000.00');
    ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-entries/{$discount['id']}/void", ['reason' => ''])->assertUnprocessable();
    ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-entries/{$discount['id']}/void", ['reason' => 'Lập nhầm'])->assertOk();
    expect(($this->show)()['debt'])->toBe('550000.00');

    $actions = collect(($this->as)()->getJson("/api/v1/customers/{$this->customer}/activity")->assertOk()->json('data'))->pluck('action');
    expect($actions)->toContain('customer.debt_opening')->toContain('customer.debt_voided')->toContain('customer.debt_collected');
    ($this->as)()->get("/api/v1/customers/{$this->customer}/ledger/export")->assertOk();
});

it('lets only debt_adjust holders write debt documents', function () {
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    ($this->as)($staff)->postJson("/api/v1/customers/{$this->customer}/debt-entries", ['kind' => 'adjustment', 'amount' => 1000, 'reason' => 'x'])->assertForbidden();
    $accountant = addMember($this->tenant, Role::ACCOUNTANT);
    ($this->as)($accountant)->postJson("/api/v1/customers/{$this->customer}/debt-entries", ['kind' => 'adjustment', 'amount' => 1000, 'reason' => 'Phí giao lại'])->assertCreated();

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->postJson("/api/v1/customers/{$this->customer}/debt-entries", ['kind' => 'adjustment', 'amount' => 1000, 'reason' => 'x'])->assertNotFound();
});

it('makes orders overdue after the payment terms of the customer or its group', function () {
    $group = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Khách sỉ', 'price_tier' => 'wholesale', 'payment_terms_days' => 7, 'credit_limit' => 5000000])
        ->assertCreated()->assertJsonPath('data.payment_terms_days', 7)->json('data.id');
    ($this->as)()->putJson("/api/v1/customers/{$this->customer}", ['name' => 'Cửa hàng Minh Phát', 'group_id' => $group])->assertOk();
    $old = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 300000]], ['customer_id' => $this->customer, 'stage' => 'completed'])->json('data.id');
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 200000]], ['customer_id' => $this->customer, 'stage' => 'completed']);
    DB::table('orders')->where('ulid', $old)->update(['placed_at' => now()->subDays(10)]);

    expect(($this->show)())->toMatchArray(['debt' => '500000.00', 'overdue' => '300000.00'])
        ->and(($this->show)()['summary'])->toMatchArray(['terms_days' => 7, 'credit_limit' => '5000000.00', 'average_order' => '250000.00', 'purchase_interval_days' => 10.0]);
    ($this->as)()->getJson('/api/v1/customers?overdue=1')->assertJsonPath('data.0.id', $this->customer);

    // The customer's own terms win over the group's.
    ($this->as)()->putJson("/api/v1/customers/{$this->customer}", ['name' => 'Cửa hàng Minh Phát', 'payment_terms_days' => 30])->assertOk();
    expect(($this->show)()['overdue'])->toBe('0.00');
    ($this->as)()->getJson('/api/v1/customers?overdue=1')->assertJsonCount(0, 'data');
});

it('pages the orders, payments and returns of a customer', function () {
    foreach (range(1, 3) as $i) {
        createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 10000 * $i]], [
            'customer_id' => $this->customer, 'stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 10000 * $i]],
        ])->assertCreated();
    }
    ($this->as)()->getJson("/api/v1/customers/{$this->customer}/orders?per_page=5")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
    ($this->as)()->getJson("/api/v1/customers/{$this->customer}/payments?per_page=5")->assertOk()->assertJsonCount(3, 'data');
    ($this->as)()->getJson("/api/v1/customers/{$this->customer}/returns")->assertOk()->assertJsonCount(0, 'data');
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->getJson("/api/v1/customers/{$this->customer}/orders")->assertNotFound();
});
