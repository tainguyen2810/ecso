<?php

use App\Modules\Access\Models\Role;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Sales\Models\SalesChannel;

function centreSale($user, $tenant, array $lines, array $extra = [])
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', SalesChannel::DEFAULT_NAME)->firstOrFail()->ulid,
        'stage' => 'completed',
        'lines' => array_map(fn ($l) => ['variant_id' => $l[0]->ulid, 'quantity' => $l[1], 'unit_price' => $l[2]], $lines),
        ...$extra,
    ])->assertCreated()->json('data');
}

it('breaks sales down by product, customer and warehouse, with returns, payments and customers', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 20); // cost 50,000
    $api = actingInTenant($owner, $tenant);

    $order = centreSale($owner, $tenant, [[$variant, 2, 120000]], ['customer' => ['name' => 'Khách X', 'phone' => '0900000001'], 'payments' => [['method' => 'cash', 'amount' => 240000]]]);
    centreSale($owner, $tenant, [[$variant, 1, 100000]], ['payments' => [['method' => 'bank_transfer', 'amount' => 100000]]]); // walk-in
    centreSale($owner, $tenant, [[$variant, 1, 100000]], ['customer' => ['name' => 'Khách Y', 'phone' => '0900000002'], 'payments' => [['method' => 'cash', 'amount' => 40000]]]);

    $products = $api->getJson('/api/v1/reports/product-sales')->assertOk()->json('data');
    expect($products['rows'])->toHaveCount(1)
        ->and($products['rows'][0])->toMatchArray(['orders' => 3, 'units' => 4, 'goods' => 440000, 'cogs' => 200000, 'gross_profit' => 240000])
        ->and($products['rows'][0]['variant']['sku'])->toBe('AO-01');
    $api->getJson('/api/v1/reports/product-sales?q=KHONG-CO')->assertOk()->assertJsonCount(0, 'data.rows');

    $byCustomer = $api->getJson('/api/v1/reports/sales?by=customer')->assertOk()->json('data.rows');
    expect(collect($byCustomer)->pluck('revenue', 'label')->all())->toEqual(['Khách X' => 240000, 'Khách Y' => 100000, 'Khách lẻ / khách sàn' => 100000]);
    $byWarehouse = $api->getJson('/api/v1/reports/sales?by=warehouse')->assertOk()->json('data.rows');
    expect($byWarehouse)->toHaveCount(1)->and($byWarehouse[0]['orders'])->toBe(3);

    $payments = $api->getJson('/api/v1/reports/payments')->assertOk()->json('data');
    expect(collect($payments['rows'])->pluck('received', 'label')->all())->toEqual(['cash' => 280000, 'bank_transfer' => 100000])
        ->and($payments['totals']['net'])->toEqual(380000);
    expect($api->getJson('/api/v1/reports/payments?by=staff')->json('data.rows.0.label'))->toBe($owner->name);

    // Khách Y still owes 60,000.
    $receivables = $api->getJson('/api/v1/reports/receivables')->assertOk()->json('data');
    expect($receivables['totals'])->toMatchArray(['customers' => 1, 'orders' => 1, 'due' => 60000])
        ->and($receivables['rows'][0]['customer']['name'])->toBe('Khách Y');

    $customers = $api->getJson('/api/v1/reports/customers')->assertOk()->json('data');
    expect($customers['summary'])->toMatchArray(['customers' => 2, 'new' => 2, 'returning' => 0, 'anonymous_orders' => 1])
        ->and($customers['rows'][0]['customer']['name'])->toBe('Khách X');

    // One shirt comes back and goes back on the shelf.
    $itemId = $api->getJson("/api/v1/orders/{$order['id']}")->json('data.items.0.id');
    $return = $api->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertCreated()->json('data');
    foreach ([OrderReturn::APPROVED, OrderReturn::RECEIVED] as $status) {
        $api->postJson("/api/v1/returns/{$return['id']}/status", ['status' => $status])->assertOk();
    }
    $lineId = $api->getJson("/api/v1/returns/{$return['id']}")->json('data.lines.0.id');
    $api->postJson("/api/v1/returns/{$return['id']}/inspect", [
        'warehouse_id' => defaultWarehouse($tenant)->ulid,
        'lines' => [['line_id' => $lineId, 'received_qty' => 1, 'sellable_qty' => 1, 'damaged_qty' => 0]],
    ])->assertOk();

    expect($api->getJson('/api/v1/reports/returns')->assertOk()->json('data.totals'))->toMatchArray(['returns' => 1, 'quantity' => 1]);
    expect($api->getJson('/api/v1/reports/returns?by=product')->assertOk()->json('data.rows.0'))->toMatchArray(['quantity' => 1, 'sellable' => 1, 'value' => 120000]);

    $pl = collect($api->getJson('/api/v1/reports/profit-loss')->assertOk()->json('data.lines'))->keyBy('key');
    expect($pl['goods']['current'])->toEqual(440000)
        ->and($pl['cogs_out']['current'])->toEqual(200000)
        ->and($pl['cogs_back']['current'])->toEqual(50000)
        ->and($pl['cogs']['current'])->toEqual(150000)
        ->and($pl['gross_profit']['current'])->toEqual($pl['revenue']['current'] - 150000)
        ->and($pl['profit']['previous'])->toEqual(0)
        ->and($pl['profit']['change'])->toBeNull();
});

it('reports purchases, supplier debts, stock on any date and SKUs below safety stock', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'QUAN-01');
    $api = actingInTenant($owner, $tenant);
    $supplier = $api->postJson('/api/v1/suppliers', ['name' => 'Xưởng Minh Long'])->assertCreated()->json('data.id');
    $po = $api->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier,
        'warehouse_id' => defaultWarehouse($tenant)->ulid,
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 10, 'unit_cost' => 60000]],
        'place' => true,
    ])->assertCreated()->json('data.id');
    $api->postJson("/api/v1/purchase-orders/{$po}/receive", ['lines' => [['variant_id' => $variant->ulid, 'quantity' => 10]]])->assertCreated();

    $bySupplier = $api->getJson('/api/v1/reports/purchases')->assertOk()->json('data');
    expect($bySupplier['rows'][0])->toMatchArray(['label' => 'Xưởng Minh Long', 'receipts' => 1, 'quantity' => 10, 'value' => 600000, 'net' => 600000]);
    expect($api->getJson('/api/v1/reports/purchases?by=product')->json('data.rows.0.sku'))->toBe('QUAN-01');
    expect($api->getJson('/api/v1/reports/purchases?by=day')->json('data.rows'))->toHaveCount(1);

    $api->getJson('/api/v1/reports/payables')->assertOk()
        ->assertJsonPath('data.totals.debt', 600000)->assertJsonPath('data.rows.0.supplier.name', 'Xưởng Minh Long');

    $api->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'bank_transfer', 'amount' => 200000])->assertCreated();
    expect($api->getJson('/api/v1/reports/supplier-payments')->assertOk()->json('data.rows.0'))->toMatchArray(['label' => 'bank_transfer', 'entries' => 1, 'paid' => 200000]);
    expect($api->getJson('/api/v1/reports/supplier-payments?by=supplier')->json('data.rows.0'))->toMatchArray(['label' => 'Xưởng Minh Long', 'id' => $supplier]);
    $api->getJson('/api/v1/reports/payables')->assertJsonPath('data.totals.debt', 400000);

    $today = $api->getJson('/api/v1/reports/stock-on-hand')->assertOk()->json('data');
    expect($today['totals'])->toMatchArray(['skus' => 1, 'quantity' => 10, 'value' => 600000])
        ->and($today['rows'][0]['by_warehouse'][defaultWarehouse($tenant)->ulid])->toBe(10)
        ->and($today['rows'][0]['share'])->toEqual(100)
        ->and($today['warehouses'][0]['value'])->toEqual(600000);
    $yesterday = now()->subDay()->toDateString();
    $api->getJson("/api/v1/reports/stock-on-hand?date={$yesterday}")->assertOk()->assertJsonCount(0, 'data.rows');

    $api->putJson("/api/v1/inventory/variants/{$variant->ulid}/safety-stock", ['warehouse' => defaultWarehouse($tenant)->ulid, 'safety_stock' => 15])->assertOk();
    $low = $api->getJson('/api/v1/reports/low-stock')->assertOk()->json('data');
    expect($low['rows'][0])->toMatchArray(['available' => 10, 'safety_stock' => 15, 'shortfall' => 5, 'sold_30d' => 0, 'days_left' => null, 'out_of_stock' => false]);

    $api->getJson('/api/v1/reports/over-stock')->assertOk()->assertJsonCount(0, 'data.rows');
    $api->putJson("/api/v1/inventory/variants/{$variant->ulid}/location", ['warehouse' => defaultWarehouse($tenant)->ulid, 'max_stock' => 4])->assertOk();
    expect($api->getJson('/api/v1/reports/over-stock')->assertOk()->json('data.rows.0'))->toMatchArray(['on_hand' => 10, 'max_stock' => 4, 'excess' => 6, 'excess_value' => 360000]);

    // Two damaged pieces written off.
    $adjustment = $api->postJson('/api/v1/inventory/adjustments', ['warehouse_id' => defaultWarehouse($tenant)->ulid, 'reason' => 'damaged', 'lines' => [['variant_id' => $variant->ulid, 'mode' => 'delta', 'quantity' => -2]]])
        ->assertCreated()->json('data.id');
    $api->postJson("/api/v1/inventory/adjustments/{$adjustment}/approve")->assertOk();
    expect($api->getJson('/api/v1/reports/adjustments?by=reason')->assertOk()->json('data.rows.0'))->toMatchArray(['label' => 'damaged', 'documents' => 1, 'increase' => 0, 'decrease' => 2, 'value' => -120000]);
    expect($api->getJson('/api/v1/reports/adjustments')->json('data.rows.0.id'))->toBe($adjustment);
    expect($api->getJson('/api/v1/reports/adjustments?by=product')->json('data.totals'))->toMatchArray(['documents' => 1, 'decrease' => 2]);

    $overview = $api->getJson('/api/v1/reports/overview')->assertOk()->json('data');
    expect($overview['purchases']['value'])->toEqual(600000)
        ->and($overview['payable']['debt'])->toEqual(400000)
        ->and($overview['stock']['value'])->toEqual(480000)
        ->and($overview['stock']['low']['skus'])->toBe(1);
});

it('shows each report group only to members allowed to see it, inside their own shop', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 5);
    centreSale($owner, $tenant, [[$variant, 1, 100000]], ['payments' => [['method' => 'cash', 'amount' => 100000]]]);

    $staff = addMember($tenant, Role::VIEWER);
    foreach (['profit-loss', 'payments', 'receivables'] as $report) {
        actingInTenant($staff, $tenant)->getJson("/api/v1/reports/{$report}")->assertForbidden();
    }
    $overview = actingInTenant($staff, $tenant)->getJson('/api/v1/reports/overview')->assertOk()->json('data');
    expect($overview)->not->toHaveKey('finance')->and($overview['sales']['orders'])->toBe(1);

    $theirs = actingInTenant($otherOwner, $other)->getJson('/api/v1/reports/overview')->assertOk()->json('data');
    expect($theirs['sales']['orders'])->toBe(0)
        ->and($theirs['finance']['payments']['received'])->toEqual(0)
        ->and($theirs['stock']['skus'])->toBe(0);
    actingInTenant($otherOwner, $other)->getJson('/api/v1/reports/product-sales')->assertOk()->assertJsonCount(0, 'data.rows');
});
