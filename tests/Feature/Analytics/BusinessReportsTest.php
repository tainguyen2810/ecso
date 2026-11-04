<?php

use App\Modules\Access\Models\Role;
use App\Modules\Inventory\Notifications\LowStockNotification;
use App\Modules\Sales\Models\SalesChannel;
use Illuminate\Support\Facades\Notification;

// 05:00 UTC is midday in the tenant's timezone, so dates built from now() are the same calendar day there.
beforeEach(fn () => $this->travelTo(now()->setTime(5, 0)));

function reportSale($user, $tenant, array $lines, array $extra = [])
{
    $channel = $extra['channel'] ?? SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', SalesChannel::DEFAULT_NAME)->firstOrFail()->ulid;
    unset($extra['channel']);

    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => $channel,
        'stage' => 'completed',
        'lines' => array_map(fn ($l) => ['variant_id' => $l[0]->ulid, 'quantity' => $l[1], 'unit_price' => $l[2]], $lines),
        ...$extra,
    ])->assertCreated();
}

it('reports sales and gross profit by channel, staff and day', function () {
    [$tenant, $owner] = tenantWithOwner();
    $cashier = addMember($tenant, Role::ORDER_STAFF);
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 20); // cost 50,000
    $facebook = actingInTenant($owner, $tenant)->postJson('/api/v1/sales-channels', ['name' => 'Facebook', 'type' => 'facebook'])->assertCreated()->json('data.id');

    reportSale($owner, $tenant, [[$variant, 2, 120000]], ['payments' => [['method' => 'cash', 'amount' => 240000]]]);
    reportSale($cashier, $tenant, [[$variant, 1, 100000]], ['channel' => $facebook, 'payments' => [['method' => 'cash', 'amount' => 100000]]]);
    $cancelled = reportSale($owner, $tenant, [[$variant, 1, 999000]], ['stage' => 'pending'])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$cancelled}/actions/cancel", ['reason' => 'x'])->assertOk();

    $byChannel = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/sales?by=channel')->assertOk()->json('data');
    expect($byChannel['totals'])->toMatchArray(['orders' => 2, 'units' => 3, 'revenue' => 340000, 'cogs' => 150000, 'gross_profit' => 190000])
        ->and($byChannel['excluded']['orders'])->toBe(1)
        ->and(collect($byChannel['rows'])->pluck('revenue', 'label')->all())->toEqual([SalesChannel::DEFAULT_NAME => 240000, 'Facebook' => 100000]);

    $byStaff = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/sales?by=staff')->assertOk()->json('data.rows');
    expect(collect($byStaff)->pluck('orders', 'label')->all())->toEqual([$owner->name => 1, $cashier->name => 1]);

    $byDay = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/sales?by=day&from='.now()->subDays(6)->toDateString())->assertOk()->json('data.rows');
    expect($byDay)->toHaveCount(7)->and(end($byDay)['gross_profit'])->toEqual(190000);

    // Finance overview now lists own channels next to marketplace shops.
    actingInTenant($owner, $tenant)->getJson('/api/v1/finance/summary')->assertOk()->assertJsonPath('data.by_shop.0.shop', SalesChannel::DEFAULT_NAME);
});

it('builds the stock in-out-balance report with write-offs separated', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 10);
    reportSale($owner, $tenant, [[$variant, 3, 100000]], ['payments' => [['method' => 'cash', 'amount' => 300000]]]);
    $warehouse = defaultWarehouse($tenant)->ulid;
    foreach ([['damaged', -1], ['correction', -2]] as [$reason, $qty]) {
        $id = actingInTenant($owner, $tenant)->postJson('/api/v1/inventory/adjustments', ['warehouse_id' => $warehouse, 'reason' => $reason, 'lines' => [['variant_id' => $variant->ulid, 'mode' => 'delta', 'quantity' => $qty]]])
            ->assertCreated()->json('data.id');
        actingInTenant($owner, $tenant)->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertOk();
    }

    $row = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/stock-ledger')->assertOk()->json('data.rows.0');
    expect($row)->toMatchArray(['opening' => 0, 'purchase_in' => 10, 'sale_out' => 3, 'damage_out' => 1, 'adjust_out' => 2, 'in' => 10, 'out' => 6, 'closing' => 4])
        ->and($row['closing_value'])->toEqual(200000);

    // A later period starts from the closing balance.
    $tomorrow = now()->addDay()->toDateString();
    actingInTenant($owner, $tenant)->getJson("/api/v1/reports/stock-ledger?from={$tomorrow}&to={$tomorrow}")->assertOk()
        ->assertJsonPath('data.rows.0.opening', 4)->assertJsonPath('data.rows.0.in', 0)->assertJsonPath('data.rows.0.closing', 4);
});

it('keeps a cashbook of customer payments, refunds and supplier payments', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 10);
    $order = reportSale($owner, $tenant, [[$variant, 1, 100000]], ['payments' => [['method' => 'cash', 'amount' => 100000]]])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order}/payments", ['kind' => 'refund', 'method' => 'cash', 'amount' => 20000])->assertCreated();
    $supplier = actingInTenant($owner, $tenant)->postJson('/api/v1/suppliers', ['name' => 'NCC A'])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/suppliers/{$supplier}/payments", ['method' => 'bank_transfer', 'amount' => 50000])->assertCreated();

    $book = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/cashbook')->assertOk()->json('data');
    expect($book)->toMatchArray(['opening' => 0, 'in' => 100000, 'out' => 70000, 'closing' => 30000])
        ->and($book['rows'])->toHaveCount(3)
        ->and($book['rows'][0]['type'])->toBe('supplier');

    $cash = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/cashbook?method=cash')->assertOk()->json('data');
    expect($cash['closing'])->toEqual(80000);

    $tomorrow = now()->addDay()->toDateString();
    actingInTenant($owner, $tenant)->getJson("/api/v1/reports/cashbook?from={$tomorrow}&to={$tomorrow}")->assertOk()->assertJsonPath('data.opening', 30000);

    // Cashbook needs finance access; warehouse staff cannot read it.
    actingInTenant(addMember($tenant, Role::WAREHOUSE_STAFF), $tenant)->getJson('/api/v1/reports/cashbook')->assertForbidden();
});

it('alerts on SKUs at or below their safety stock', function () {
    Notification::fake();
    [$tenant, $owner] = tenantWithOwner();
    $low = makeVariant($tenant, 'LOW');
    $fine = makeVariant($tenant, 'FINE');
    makeVariant($tenant, 'NO-SAFETY'); // no stock, no safety stock: no alert
    stockIn($tenant, $low, 3);
    stockIn($tenant, $fine, 30);
    $warehouse = defaultWarehouse($tenant)->ulid;
    foreach ([[$low, 5], [$fine, 5]] as [$v, $safety]) {
        actingInTenant($owner, $tenant)->putJson("/api/v1/inventory/variants/{$v->ulid}/safety-stock", ['warehouse' => $warehouse, 'safety_stock' => $safety])->assertOk();
    }

    actingInTenant($owner, $tenant)->getJson('/api/v1/inventory/low-stock')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku', 'LOW')->assertJsonPath('data.0.available', 3);

    $warehouseStaff = addMember($tenant, Role::WAREHOUSE_STAFF);
    $manager = addMember($tenant, Role::MANAGER);
    $this->artisan('ecso:inventory:low-stock-digest', ['--tenant' => $tenant->id])->assertSuccessful();
    Notification::assertSentTo([$owner, $manager], LowStockNotification::class, fn ($n) => $n->total === 1 && $n->items[0]['sku'] === 'LOW');
    Notification::assertNotSentTo($warehouseStaff, LowStockNotification::class);
});
