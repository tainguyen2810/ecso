<?php

use App\Modules\Access\Models\Role;

beforeEach(fn () => $this->travelTo(now()->setTime(5, 0)));

it('shows what is overdue to a supplier from its payment terms, oldest goods paid first', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-T1');
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'Xưởng Hạn 30', 'payment_terms_days' => 30])
        ->assertCreated()->assertJsonPath('data.payment_terms_days', 30)->json('data.id');
    $receive = function (int $qty) use ($owner, $tenant, $supplier, $a) {
        $po = actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier, 'warehouse_id' => defaultWarehouse($tenant)->ulid, 'place' => true,
            'lines' => [['variant_id' => $a->ulid, 'quantity' => $qty, 'unit_cost' => 10000]],
        ])->assertCreated()->json('data.id');
        actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$po}/receive", ['lines' => [['variant_id' => $a->ulid, 'quantity' => $qty]]])->assertCreated();
    };

    // 40 days ago: 500,000 of goods. Today: 300,000.
    $this->travel(-40)->days();
    $receive(50);
    $this->travelBack();
    $this->travelTo(now()->setTime(5, 0));
    $receive(30);

    $get = fn () => actingInTenant($owner, $tenant)->getJson("/api/v1/suppliers/{$supplier}")->json('data');
    expect($get())->toMatchArray(['debt' => '800000.00', 'overdue' => '500000.00']);

    // A payment settles the oldest goods first.
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'cash', 'amount' => 200000])->assertCreated();
    expect($get())->toMatchArray(['debt' => '600000.00', 'overdue' => '300000.00']);

    $payables = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/payables')->json('data');
    expect($payables['rows'][0])->toMatchArray(['overdue' => 300000, 'terms_days' => 30])->and($payables['totals']['overdue'])->toEqual(300000);

    // Without terms nothing is overdue.
    actingInTenant($owner, $tenant)->patchJson("/api/v1/suppliers/{$supplier}", ['payment_terms_days' => null])->assertOk();
    expect($get()['overdue'])->toBe('0.00');
    actingInTenant($owner, $tenant)->patchJson("/api/v1/suppliers/{$supplier}", ['payment_terms_days' => 999])->assertUnprocessable();
});

it('spreads money paid on account over the orders still owed, oldest first, and uses an order due date', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-T2');
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'Xưởng Trả Trước'])->assertCreated()->json('data.id');
    $receive = function (int $qty) use ($owner, $tenant, $supplier, $a) {
        $po = actingInTenant($owner, $tenant)->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier, 'warehouse_id' => defaultWarehouse($tenant)->ulid, 'place' => true,
            'lines' => [['variant_id' => $a->ulid, 'quantity' => $qty, 'unit_cost' => 10000]],
        ])->assertCreated()->json('data.id');
        actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$po}/receive", ['lines' => [['variant_id' => $a->ulid, 'quantity' => $qty]]])->assertCreated();

        return $po;
    };

    // A prepayment of 700,000 before any goods.
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'bank_transfer', 'amount' => 700000])->assertCreated();
    $this->travel(-10)->days();
    $first = $receive(50);   // 500,000
    $this->travelBack();
    $this->travelTo(now()->setTime(5, 0));
    $second = $receive(40);  // 400,000

    $data = actingInTenant($owner, $tenant)->getJson("/api/v1/suppliers/{$supplier}")->json('data');
    $orders = collect($data['orders'])->keyBy('id');
    expect($data['debt'])->toBe('200000.00')
        ->and($orders[$first]['allocation'])->toMatchArray(['open' => 0, 'credit' => 500000])
        ->and($orders[$second]['allocation'])->toMatchArray(['open' => 200000, 'credit' => 200000, 'due_date' => null, 'overdue' => false])
        ->and($data['overdue'])->toBe('0.00');

    // A due date set after the goods arrived makes the rest overdue.
    actingInTenant($owner, $tenant)->patchJson("/api/v1/purchase-orders/{$second}/due-date", ['due_date' => now()->subDay()->toDateString()])->assertOk()
        ->assertJsonPath('data.allocation.overdue', true)->assertJsonPath('data.allocation.open', 200000);
    expect(actingInTenant($owner, $tenant)->getJson("/api/v1/suppliers/{$supplier}")->json('data.overdue'))->toBe('200000.00');
    actingInTenant(addMember($tenant, Role::WAREHOUSE_STAFF), $tenant)->patchJson("/api/v1/purchase-orders/{$second}/due-date", ['due_date' => null])->assertForbidden();
});
