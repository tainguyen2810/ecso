<?php

use App\Modules\Access\Models\Role;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesChannel;
use Illuminate\Testing\TestResponse;

function createSupplier($user, $tenant, string $name = 'Xưởng May Minh Long'): string
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/suppliers', ['name' => $name, 'phone' => '0281234567'])
        ->assertCreated()->json('data.id');
}

function createPo($user, $tenant, string $supplier, array $lines, array $extra = []): TestResponse
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier,
        'warehouse_id' => defaultWarehouse($tenant)->ulid,
        'lines' => array_map(fn ($l) => ['variant_id' => $l[0]->ulid, 'quantity' => $l[1], 'unit_cost' => $l[2]], $lines),
        ...$extra,
    ]);
}

it('orders goods, receives them in two deliveries and updates the average cost', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-01');
    $b = makeVariant($tenant, 'QUAN-01');
    stockIn($tenant, $a, 10); // at 50,000 (stockIn helper)
    $supplier = createSupplier($owner, $tenant);

    $po = createPo($owner, $tenant, $supplier, [[$a, 10, 50000], [$b, 5, 80000]], ['place' => true])->assertCreated();
    expect($po->json('data.number'))->toBe('PO-000001')
        ->and($po->json('data.status'))->toBe('ordered')
        ->and($po->json('data.total'))->toBe('900000.00')
        ->and($po->json('data.balance_due'))->toBe('0.00'); // nothing owed before goods arrive
    $id = $po->json('data.id');

    // First delivery: 6 shirts, the supplier raised the price to 55,000.
    $res = actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$id}/receive", [
        'lines' => [['variant_id' => $a->ulid, 'quantity' => 6, 'unit_cost' => 55000], ['variant_id' => $b->ulid, 'quantity' => 0]],
    ])->assertCreated();
    expect($res->json('data.status'))->toBe('partial')
        ->and($res->json('data.received_value'))->toBe('330000.00')
        ->and($res->json('receipt.source'))->toBe('purchase');
    expect(balanceOf(defaultWarehouse($tenant), $a)->on_hand)->toBe(16);
    $cost = ProductVariant::withoutGlobalScopes()->find($a->id)->cost_price;
    expect(round((float) $cost))->toBe(round((10 * 50000 + 6 * 55000) / 16));

    // Cannot receive more than is outstanding.
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$id}/receive", ['lines' => [['variant_id' => $a->ulid, 'quantity' => 5]]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
    // Header is locked once goods arrived.
    actingInTenant($owner, $tenant)->putJson("/api/v1/purchase-orders/{$id}", ['note' => 'x'])->assertUnprocessable();

    // Second delivery completes the order at the ordered cost.
    $res = actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$id}/receive", [
        'lines' => [['variant_id' => $a->ulid, 'quantity' => 4], ['variant_id' => $b->ulid, 'quantity' => 5]],
    ])->assertCreated();
    expect($res->json('data.status'))->toBe('received')
        ->and($res->json('data.received_value'))->toBe('930000.00')
        ->and($res->json('data.balance_due'))->toBe('930000.00');
    expect(StockReceipt::withoutGlobalScopes()->where('purchase_order_id', PurchaseOrder::withoutGlobalScopes()->where('ulid', $id)->value('id'))->count())->toBe(2);

    actingInTenant($owner, $tenant)->getJson("/api/v1/purchase-orders/{$id}")->assertOk()
        ->assertJsonCount(2, 'data.receipts')->assertJsonPath('data.lines.0.received_qty', 10);
});

it('closes a partly received order and cancels one that never arrived', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-01');
    $supplier = createSupplier($owner, $tenant);

    $draft = createPo($owner, $tenant, $supplier, [[$a, 10, 1000]])->assertCreated()->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$draft}/actions/close")->assertUnprocessable();
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$draft}/actions/cancel", ['reason' => 'Đặt nhầm'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $po = createPo($owner, $tenant, $supplier, [[$a, 10, 1000]], ['place' => true])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$po}/receive", ['lines' => [['variant_id' => $a->ulid, 'quantity' => 3]]])->assertCreated();
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$po}/actions/cancel")->assertUnprocessable();
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$po}/actions/close", ['reason' => 'NCC hết hàng'])
        ->assertOk()->assertJsonPath('data.status', 'closed');
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$po}/receive", ['lines' => [['variant_id' => $a->ulid, 'quantity' => 1]]])->assertUnprocessable();
});

it('tracks supplier debt through payments, returns and voids', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-01');
    $supplier = createSupplier($owner, $tenant);
    $po = createPo($owner, $tenant, $supplier, [[$a, 10, 50000]], ['place' => true])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/purchase-orders/{$po}/receive", ['lines' => [['variant_id' => $a->ulid, 'quantity' => 10]]])->assertCreated();

    // Pay part of it.
    $pay = actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", [
        'purchase_order_id' => $po, 'method' => 'bank_transfer', 'amount' => 200000, 'reference' => 'UNC 001',
    ])->assertCreated()->json('data.id');
    // Cannot pay more than is owed on the order.
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['purchase_order_id' => $po, 'method' => 'cash', 'amount' => 400000])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');

    // Return 2 damaged shirts: stock goes out, debt goes down by 100,000.
    $ret = actingInTenant($owner, $tenant)->postJson('/api/v1/supplier-returns', [
        'purchase_order_id' => $po, 'reason' => 'Lỗi đường may', 'lines' => [['variant_id' => $a->ulid, 'quantity' => 2]],
    ])->assertCreated();
    expect($ret->json('data.number'))->toBe('TNC-000001')->and($ret->json('data.total'))->toBe('100000.00');
    expect(balanceOf(defaultWarehouse($tenant), $a)->on_hand)->toBe(8);
    expect(StockMovement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('type', StockMovement::SUPPLIER_RETURN)->sum('on_hand_delta'))->toEqual(-2);
    // Cannot return more than was received less already returned.
    actingInTenant($owner, $tenant)->postJson('/api/v1/supplier-returns', ['purchase_order_id' => $po, 'lines' => [['variant_id' => $a->ulid, 'quantity' => 9]]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');

    actingInTenant($owner, $tenant)->getJson("/api/v1/purchase-orders/{$po}")->assertJsonPath('data.balance_due', '200000.00');
    actingInTenant($owner, $tenant)->getJson('/api/v1/suppliers?debt=1')->assertOk()
        ->assertJsonPath('data.0.purchased', '400000.00')->assertJsonPath('data.0.paid', '200000.00')->assertJsonPath('data.0.debt', '200000.00');

    // Voiding the payment brings the debt back.
    actingInTenant($owner, $tenant)->postJson("/api/v1/supplier-payments/{$pay}/void", ['reason' => 'Nhập sai'])->assertOk();
    actingInTenant($owner, $tenant)->getJson("/api/v1/suppliers/{$supplier}")->assertOk()
        ->assertJsonPath('data.debt', '400000.00')->assertJsonCount(1, 'data.returns');
});

it('suggests what to reorder from sales velocity, stock and goods on order', function () {
    [$tenant, $owner] = tenantWithOwner();
    $fast = makeVariant($tenant, 'FAST');
    $slow = makeVariant($tenant, 'SLOW');
    stockIn($tenant, $fast, 40);
    stockIn($tenant, $slow, 40);
    $channel = SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
    // 30 FAST and 1 SLOW sold in the last 30 days.
    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => $channel->ulid, 'stage' => 'completed',
        'lines' => [['variant_id' => $fast->ulid, 'quantity' => 30, 'unit_price' => 1000], ['variant_id' => $slow->ulid, 'quantity' => 1, 'unit_price' => 1000]],
        'payments' => [['method' => 'cash', 'amount' => 31000]],
    ])->assertCreated();

    // FAST: 1/day × 14 days = 14 needed, 10 available → buy 4. SLOW has plenty.
    $rows = actingInTenant($owner, $tenant)->getJson('/api/v1/purchase-orders/reorder?cover_days=14')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['variant']['sku'])->toBe('FAST')->and($rows[0]['suggested'])->toBe(4);

    // Goods already on order count.
    $supplier = createSupplier($owner, $tenant);
    createPo($owner, $tenant, $supplier, [[$fast, 4, 1000]], ['place' => true])->assertCreated();
    actingInTenant($owner, $tenant)->getJson('/api/v1/purchase-orders/reorder?cover_days=14')->assertOk()->assertJsonCount(0, 'data');
});

it('lets warehouse staff receive but not create orders or pay, and accountants pay', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-01');
    $supplier = createSupplier($owner, $tenant);
    $po = createPo($owner, $tenant, $supplier, [[$a, 5, 1000]], ['place' => true])->json('data.id');

    $staff = addMember($tenant, Role::WAREHOUSE_STAFF);
    createPo($staff, $tenant, $supplier, [[$a, 1, 1000]])->assertForbidden();
    // Staff cannot change the price on arrival: the ordered cost is used.
    actingInTenant($staff, $tenant)->postJson("/api/v1/purchase-orders/{$po}/receive", ['lines' => [['variant_id' => $a->ulid, 'quantity' => 5, 'unit_cost' => 1]]])
        ->assertCreated()->assertJsonPath('data.received_value', '5000.00');
    actingInTenant($staff, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'cash', 'amount' => 1000])->assertForbidden();

    $accountant = addMember($tenant, Role::ACCOUNTANT);
    actingInTenant($accountant, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['purchase_order_id' => $po, 'method' => 'cash', 'amount' => 5000])->assertCreated();
    actingInTenant($accountant, $tenant)->getJson("/api/v1/purchase-orders/{$po}")->assertJsonPath('data.balance_due', '0.00');
});

it('keeps suppliers and purchase orders inside their tenant', function () {
    [$tenantA, $ownerA] = tenantWithOwner('A');
    [$tenantB, $ownerB] = tenantWithOwner('B');
    $a = makeVariant($tenantA, 'AO-01');
    $supplier = createSupplier($ownerA, $tenantA);
    $po = createPo($ownerA, $tenantA, $supplier, [[$a, 5, 1000]])->json('data.id');

    actingInTenant($ownerB, $tenantB)->getJson("/api/v1/suppliers/{$supplier}")->assertNotFound();
    actingInTenant($ownerB, $tenantB)->getJson("/api/v1/purchase-orders/{$po}")->assertNotFound();
    actingInTenant($ownerB, $tenantB)->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(0, 'data');
    // Another tenant's supplier cannot be used.
    createPo($ownerB, $tenantB, $supplier, [[makeVariant($tenantB, 'B-1'), 1, 1]])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
});

it('refuses to delete a supplier with history', function () {
    [$tenant, $owner] = tenantWithOwner();
    $supplier = createSupplier($owner, $tenant);
    $unused = createSupplier($owner, $tenant, 'NCC thử');
    createPo($owner, $tenant, $supplier, [[makeVariant($tenant, 'X'), 1, 1]])->assertCreated();

    actingInTenant($owner, $tenant)->deleteJson("/api/v1/suppliers/{$supplier}")->assertUnprocessable();
    actingInTenant($owner, $tenant)->deleteJson("/api/v1/suppliers/{$unused}")->assertNoContent();
});
