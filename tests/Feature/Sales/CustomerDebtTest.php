<?php

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\OrderReturn;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
    $this->variant = makeVariant($this->tenant);
    stockIn($this->tenant, $this->variant, 50);
    $this->customer = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Chị Lan', 'phone' => '0901234567'])->assertCreated()->json('data.id');
    $this->sale = fn (int $qty, int $price, array $extra = []) => createSalesOrder($this->owner, $this->tenant, [[$this->variant, $qty, $price]], [
        'customer_id' => $this->customer, 'stage' => 'completed', ...$extra,
    ])->assertCreated()->json('data.id');
    $this->returnOne = function (string $order, string $status = OrderReturn::APPROVED): string {
        $item = ($this->as)()->getJson("/api/v1/orders/{$order}")->json('data.items.0.id');
        $return = ($this->as)()->postJson("/api/v1/orders/{$order}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $item, 'quantity' => 1]]])->assertCreated()->json('data.id');
        if ($status !== OrderReturn::REQUESTED) {
            ($this->as)()->postJson("/api/v1/returns/{$return}/status", ['status' => $status])->assertOk();
        }

        return $return;
    };
    $this->debt = fn () => ($this->as)()->getJson("/api/v1/customers/{$this->customer}")->json('data.debt');
});

it('shows the same debt on the customer page, the debt book, the receivables report and the debt receipt', function () {
    // 2 × 100 000 on credit, one unit returned: 100 000 still owed.
    $credit = ($this->sale)(2, 100000);
    ($this->returnOne)($credit);
    // Paid 50 000, the goods came back and the money was refunded: nothing owed.
    $paid = ($this->sale)(1, 50000, ['payments' => [['method' => 'cash', 'amount' => 50000]]]);
    ($this->returnOne)($paid);
    ($this->as)()->postJson("/api/v1/orders/{$paid}/payments", ['kind' => 'refund', 'method' => 'cash', 'amount' => 50000])->assertCreated();
    // A return only requested does not count yet.
    ($this->returnOne)($credit, OrderReturn::REQUESTED);

    expect(($this->debt)())->toBe('100000.00');

    $ledger = ($this->as)()->getJson("/api/v1/customers/{$this->customer}/ledger")->assertOk()->json('data');
    expect($ledger[0]['balance'])->toBe('100000.00')
        ->and(collect($ledger)->pluck('type')->unique()->sort()->values()->all())->toBe(['order', 'payment', 'refund', 'return'])
        ->and(collect($ledger)->where('type', 'return')->pluck('change')->sort()->values()->all())->toBe(['-100000.00', '-50000.00']);

    $row = collect(($this->as)()->getJson('/api/v1/reports/receivables')->assertOk()->json('data.rows'))->firstWhere('customer.id', $this->customer);
    expect($row['due'])->toEqual(100000)->and($row['orders'])->toBe(1);

    ($this->as)()->getJson("/api/v1/customers/{$this->customer}/debt")->assertOk()
        ->assertJsonPath('data.debt', 100000)->assertJsonPath('data.orders.0.id', $credit)->assertJsonCount(1, 'data.orders');
    ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-receipts", ['method' => 'cash', 'amount' => 150000])->assertUnprocessable();
    ($this->as)()->postJson("/api/v1/customers/{$this->customer}/debt-receipts", ['method' => 'cash', 'amount' => 100000])->assertCreated();

    expect(($this->debt)())->toBe('0.00');
    expect(($this->as)()->getJson("/api/v1/customers/{$this->customer}/ledger")->json('data.0.balance'))->toBe('0.00');
});

it('keeps marketplace, COD and failed-delivery orders out of the debt', function () {
    $marketplace = ($this->sale)(1, 100000);
    $failed = ($this->sale)(1, 200000);
    ($this->sale)(1, 300000, ['is_cod' => true]);
    DB::table('orders')->where('ulid', $marketplace)->update(['marketplace' => 'woocommerce']);
    DB::table('orders')->where('ulid', $failed)->update(['status' => OrderStatus::FAILED_DELIVERY]);

    expect(($this->debt)())->toBe('0.00')
        ->and(($this->as)()->getJson("/api/v1/customers/{$this->customer}/ledger")->json('data'))->toBe([]);
    expect(collect(($this->as)()->getJson('/api/v1/reports/receivables')->json('data.rows'))->firstWhere('customer.id', $this->customer))->toBeNull();

    $owed = ($this->sale)(1, 40000);
    expect(($this->debt)())->toBe('40000.00');
    // The last purchase ignores cancelled orders, like the other figures.
    $cancelled = ($this->sale)(1, 10000);
    DB::table('orders')->where('ulid', $cancelled)->update(['status' => OrderStatus::CANCELLED, 'placed_at' => now()->addDay()]);
    expect(($this->as)()->getJson("/api/v1/customers/{$this->customer}")->json('data.last_order_at'))
        ->toBe(($this->as)()->getJson("/api/v1/orders/{$owed}")->json('data.placed_at'));
});

it('moves the payments with the order when its buyer changes', function () {
    $other = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Anh Minh'])->json('data.id');
    $order = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 100000]], [
        'customer_id' => $this->customer, 'payments' => [['method' => 'cash', 'amount' => 30000]],
    ])->assertCreated()->json('data.id');

    ($this->as)()->putJson("/api/v1/sales-orders/{$order}", ['customer_id' => $other])->assertOk();

    expect(($this->as)()->getJson("/api/v1/customers/{$this->customer}")->json('data.payments'))->toBe([]);
    expect(($this->as)()->getJson("/api/v1/customers/{$other}")->json('data.payments'))->toHaveCount(1);
    expect(($this->as)()->getJson("/api/v1/customers/{$other}")->json('data.debt'))->toBe('70000.00');
});

it('refuses new orders for a customer who stopped trading, but keeps their open orders editable', function () {
    $order = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['customer_id' => $this->customer])->assertCreated()->json('data.id');
    ($this->as)()->putJson("/api/v1/customers/{$this->customer}", ['name' => 'Chị Lan', 'status' => 'inactive'])->assertOk();

    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['customer_id' => $this->customer])
        ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['customer' => ['name' => 'Lan', 'phone' => '0901 234 567']])
        ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    ($this->as)()->putJson("/api/v1/sales-orders/{$order}", ['customer_id' => $this->customer, 'shipping_fee' => 15000])->assertOk();
});

it('has separate permissions for importing, exporting and deleting customers', function () {
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    $spare = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Xóa được'])->json('data.id');
    // Roles that could manage customers keep these rights.
    ($this->as)($staff)->get('/api/v1/customers/export?format=csv')->assertOk();

    $role = Role::query()->whereNull('tenant_id')->where('key', Role::ORDER_STAFF)->sole();
    $role->permissions()->detach(Permission::query()->whereIn('key', ['customers.export', 'customers.delete', 'customers.import'])->pluck('id'));

    ($this->as)($staff)->get('/api/v1/customers/export?format=csv')->assertForbidden();
    ($this->as)($staff)->get('/api/v1/customers/import-template')->assertForbidden();
    ($this->as)($staff)->deleteJson("/api/v1/customers/{$spare}")->assertForbidden();
    ($this->as)($staff)->postJson('/api/v1/customers/bulk', ['ids' => [$spare], 'action' => 'delete'])->assertForbidden();
    ($this->as)($staff)->postJson('/api/v1/customers/bulk', ['ids' => [$spare], 'action' => 'set_status', 'value' => 'inactive'])->assertOk();
    ($this->as)()->deleteJson("/api/v1/customers/{$spare}")->assertNoContent();
});

it('logs what changed on a customer without writing personal data, and every export', function () {
    ($this->as)()->putJson("/api/v1/customers/{$this->customer}", ['name' => 'Chị Lan Anh', 'phone' => '0907777777'])->assertOk();
    ($this->as)()->get('/api/v1/customers/export?format=csv')->assertOk();

    $update = AuditLog::query()->where('action', 'customer.updated')->latest('id')->firstOrFail();
    expect($update->changes['fields'])->toEqual(['name' => ['from' => 'Chị Lan', 'to' => 'Chị Lan Anh'], 'phone' => 'changed'])
        ->and(json_encode($update->changes))->not->toContain('0907777777');
    expect(AuditLog::query()->where('action', 'customer.exported')->where('actor_user_id', $this->owner->id)->exists())->toBeTrue();
});
