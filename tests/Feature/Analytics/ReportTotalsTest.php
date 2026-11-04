<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Support\TenantContext;

function totalsSale($user, $tenant, array $lines, array $extra = [])
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', SalesChannel::DEFAULT_NAME)->firstOrFail()->ulid,
        'stage' => 'completed',
        'lines' => array_map(fn ($l) => ['variant_id' => $l[0]->ulid, 'quantity' => $l[1], 'unit_price' => $l[2]], $lines),
        ...$extra,
    ])->assertCreated()->json('data');
}

it('keeps report totals over every row when the table is cut at the row limit', function () {
    config(['ecso.reports.row_limit' => 2, 'ecso.reports.customer_row_limit' => 1]);
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'AO-01');
    $b = makeVariant($tenant, 'QUAN-01');
    $c = makeVariant($tenant, 'MU-01');
    foreach ([$a, $b, $c] as $v) {
        stockIn($tenant, $v, 20); // cost 50,000
    }
    $api = actingInTenant($owner, $tenant);

    totalsSale($owner, $tenant, [[$a, 1, 100000]], ['customer' => ['name' => 'Khách 1', 'phone' => '0900000011']]);
    totalsSale($owner, $tenant, [[$b, 2, 100000]], ['customer' => ['name' => 'Khách 2', 'phone' => '0900000012']]);
    totalsSale($owner, $tenant, [[$c, 3, 100000], [$a, 1, 100000]], ['customer' => ['name' => 'Khách 3', 'phone' => '0900000013']]);

    $orders = $api->getJson('/api/v1/reports/orders')->assertOk()->json('data');
    expect($orders['rows'])->toHaveCount(2)
        ->and($orders)->toMatchArray(['truncated' => true, 'total_rows' => 3, 'limit' => 2])
        ->and($orders['totals'])->toMatchArray(['orders' => 3, 'units' => 7])
        ->and((float) $orders['totals']['goods'])->toEqual(700000.0)
        ->and((float) $orders['totals']['cogs'])->toEqual(350000.0)
        ->and((float) $orders['totals']['gross_profit'])->toEqual(350000.0);

    $lines = $api->getJson('/api/v1/reports/sales-lines')->assertOk()->json('data');
    expect($lines['rows'])->toHaveCount(2)
        ->and($lines['truncated'])->toBeTrue()
        ->and($lines['totals'])->toMatchArray(['lines' => 4, 'orders' => 3, 'quantity' => 7])
        ->and((float) $lines['totals']['amount'])->toEqual(700000.0);

    $products = $api->getJson('/api/v1/reports/product-sales')->assertOk()->json('data');
    expect($products['rows'])->toHaveCount(2)
        ->and($products)->toMatchArray(['truncated' => true, 'total_rows' => 3])
        ->and($products['totals'])->toMatchArray(['units' => 7, 'orders' => 4])
        ->and((float) $products['totals']['gross_profit'])->toEqual(350000.0);

    $customers = $api->getJson('/api/v1/reports/customers')->assertOk()->json('data');
    expect($customers['rows'])->toHaveCount(1)
        ->and($customers['truncated'])->toBeTrue()
        ->and($customers['summary'])->toMatchArray(['customers' => 3, 'new' => 3, 'returning' => 0])
        ->and((float) $customers['summary']['revenue'])->toEqual(700000.0);

    $stock = $api->getJson('/api/v1/reports/stock-detail')->assertOk()->json('data');
    expect($stock['rows'])->toHaveCount(2)
        ->and($stock['truncated'])->toBeTrue()
        ->and($stock['totals'])->toMatchArray(['skus' => 3, 'on_hand' => 53, 'available' => 53]);

    $ledger = $api->getJson('/api/v1/reports/stock-ledger')->assertOk()->json('data');
    expect($ledger['rows'])->toHaveCount(2)
        ->and($ledger['truncated'])->toBeTrue()
        ->and($ledger['totals'])->toMatchArray(['in' => 60, 'out' => 7, 'closing' => 53]);

    // Under the limit nothing is flagged.
    config(['ecso.reports.row_limit' => 2000]);
    $api->getJson('/api/v1/reports/orders')->assertJsonPath('data.truncated', false)->assertJsonCount(3, 'data.rows');
});

it('does not count internal transfers as goods in or out of the whole business', function () {
    [$tenant, $owner] = tenantWithOwner();
    // A plan with more than one warehouse.
    TenantContext::run($tenant, fn () => Subscription::query()->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]));
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 10);
    $api = actingInTenant($owner, $tenant);
    $hn = $api->postJson('/api/v1/warehouses', ['code' => 'HN', 'name' => 'Kho HN'])->assertCreated()->json('data.id');

    $transfer = fn (int $qty) => $api->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => defaultWarehouse($tenant)->ulid, 'to_warehouse_id' => $hn,
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => $qty]],
    ])->assertCreated()->json('data.id');
    $done = $transfer(4);
    $api->postJson("/api/v1/inventory/transfers/{$done}/dispatch")->assertOk();
    $api->postJson("/api/v1/inventory/transfers/{$done}/receive")->assertOk();
    $road = $transfer(1);
    $api->postJson("/api/v1/inventory/transfers/{$road}/dispatch")->assertOk(); // still on the road

    // Whole business: the received transfer cancels out; the parcel on the road is the only stock change.
    $all = $api->getJson('/api/v1/reports/stock-ledger')->assertOk()->json('data.rows.0');
    expect($all)->toMatchArray(['purchase_in' => 10, 'transfer_in' => 0, 'transfer_out' => 1, 'in' => 10, 'out' => 1, 'closing' => 9]);

    // One warehouse: transfers are real goods in and out of it.
    $main = $api->getJson('/api/v1/reports/stock-ledger?warehouse='.defaultWarehouse($tenant)->ulid)->assertOk()->json('data.rows.0');
    expect($main)->toMatchArray(['transfer_out' => 5, 'in' => 10, 'out' => 5, 'closing' => 5]);
    $branch = $api->getJson("/api/v1/reports/stock-ledger?warehouse={$hn}")->assertOk()->json('data.rows.0');
    expect($branch)->toMatchArray(['transfer_in' => 4, 'in' => 4, 'out' => 0, 'closing' => 4]);
});

it('matches % and _ typed in a report search literally', function () {
    [$tenant, $owner] = tenantWithOwner();
    $plain = makeVariant($tenant, 'AO01');
    $odd = makeVariant($tenant, 'AO_01');
    foreach ([$plain, $odd] as $v) {
        stockIn($tenant, $v, 5);
    }
    totalsSale($owner, $tenant, [[$plain, 1, 100000], [$odd, 1, 100000]], ['payments' => [['method' => 'cash', 'amount' => 200000]]]);
    $api = actingInTenant($owner, $tenant);

    $api->getJson('/api/v1/reports/product-sales?q=AO_')->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.variant.sku', 'AO_01');
    $api->getJson('/api/v1/reports/sales-lines?q=%25')->assertOk()->assertJsonPath('data.totals.lines', 0);
    $api->getJson('/api/v1/reports/stock-detail?q=AO_')->assertOk()->assertJsonCount(1, 'data.rows');
});

it('exports every row of a cut report and records the export in the audit log', function () {
    config(['ecso.reports.row_limit' => 1]);
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 10);
    foreach ([1, 2] as $qty) {
        totalsSale($owner, $tenant, [[$variant, $qty, 100000]], ['payments' => [['method' => 'cash', 'amount' => $qty * 100000]]]);
    }
    $api = actingInTenant($owner, $tenant);

    $api->getJson('/api/v1/reports/orders')->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.truncated', true);
    $api->getJson('/api/v1/reports/orders?export=1')->assertOk()->assertJsonCount(2, 'data.rows')->assertJsonPath('data.truncated', false);

    $log = AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'report.exported')->sole();
    expect($log->actor_user_id)->toBe($owner->id)->and($log->changes['report'])->toBe('orders');
});

it('splits delivery status into groups that add up to every order, and reports shipping apart from net revenue', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 10);
    totalsSale($owner, $tenant, [[$variant, 1, 100000]], ['shipping_fee' => 30000, 'payments' => [['method' => 'cash', 'amount' => 130000]]]);
    totalsSale($owner, $tenant, [[$variant, 1, 100000]], ['stage' => 'pending']);
    $odd = totalsSale($owner, $tenant, [[$variant, 1, 100000]], ['stage' => 'pending']);
    Order::withoutGlobalScopes()->where('ulid', $odd['id'])->update(['status' => OrderStatus::UNKNOWN_STATUS]);

    $overview = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/overview')->assertOk()->json('data');
    $delivery = collect($overview['delivery'])->pluck('orders', 'key');
    expect($delivery->all())->toMatchArray(['delivered' => 1, 'waiting' => 1, 'return_requested' => 0, 'unknown' => 1])
        ->and($delivery->sum())->toBe(3);

    expect((float) $overview['sales']['shipping'])->toEqual(30000.0)
        ->and($overview['sales']['basis'])->toBe('placed');
});
