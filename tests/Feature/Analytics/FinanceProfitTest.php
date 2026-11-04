<?php

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A shop-level fee table: commission + payment + service percentages and a fixed fee per order. */
function shopFees($shop, float $pct, float $fixed = 0): void
{
    DB::table('channel_price_rules')->insert([
        'ulid' => (string) Str::ulid(), 'tenant_id' => $shop->tenant_id, 'marketplace_account_id' => $shop->id, 'category_id' => null,
        'commission_pct' => $pct, 'payment_pct' => 0, 'service_pct' => 0, 'fixed_fee' => $fixed, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Plans above Free allow more than one shop. */
function multiShop($tenant): void
{
    Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]);
}

function settle(Order $order, float $fees, float $net): void
{
    DB::table('marketplace_settlements')->insert([
        'tenant_id' => $order->tenant_id, 'marketplace_account_id' => $order->marketplace_account_id, 'order_id' => $order->id,
        'external_order_id' => $order->external_order_id, 'settled_at' => now(), 'order_amount' => $order->subtotal - $order->discount,
        'commission_fee' => $fees, 'net_amount' => $net, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('counts marketplace fees as confirmed, estimated or unknown, and never counts a payout as revenue', function () {
    [$tenant, $owner] = tenantWithOwner();
    multiShop($tenant);
    $variant = makeVariant($tenant, 'SKU-FEE');
    stockIn($tenant, $variant, 20);
    $withTable = fakeShop($tenant, name: 'Shop có biểu phí');
    $noTable = fakeShop($tenant, name: 'Shop chưa có phí');
    shopFees($withTable, 10, 1000);

    $settled = fakeOrder($withTable, [[$variant, 1]], 'fake_completed');
    $estimated = fakeOrder($withTable, [[$variant, 2]], 'fake_completed');
    fakeOrder($noTable, [[$variant, 1]], 'fake_completed');
    settle($settled, 12345, 50000);

    $api = actingInTenant($owner, $tenant);
    $data = $api->getJson('/api/v1/finance/summary')->assertOk()->json('data');
    $goods = fn (Order $o) => (float) $o->subtotal - (float) $o->discount;
    $estimate = round($goods($estimated) * 0.10 + 1000, 2);

    expect($data['fees'])->toMatchArray([
        'confirmed' => 12345.0, 'confirmed_orders' => 1, 'estimated' => $estimate, 'estimated_orders' => 1, 'unknown_orders' => 1,
    ])->and($data['fees']['total'])->toEqual(round(12345 + $estimate, 2))
        ->and($data['payout']['received'])->toEqual(50000.0)
        ->and($data['payout']['received_orders'])->toBe(1)
        // One pending order of the shop without a fee table: the expected payout is unknown, not a made-up figure.
        ->and($data['payout']['pending_orders'])->toBe(2)
        ->and($data['payout']['pending_expected'])->toBeNull()
        // The payout is money for revenue already counted: net revenue is the goods, not goods + payout.
        ->and($data['net_revenue'])->toEqual($data['completed']['goods'])
        ->and($data['contribution_profit'])->toEqual(round($data['estimated_gross_profit'] - $data['fees']['total'] + $data['completed']['shipping'], 2));

    // The drill-down rows add up to the cards.
    $fees = $api->getJson('/api/v1/finance/details?metric=fees')->assertOk()->json('data');
    expect($fees['total']['fee'])->toEqual($data['fees']['total'])->and($fees['total']['unknown'])->toBe(1)
        ->and(collect($fees['rows'])->pluck('fee_status')->sort()->values()->all())->toBe(['confirmed', 'estimated', 'unknown']);
    $revenue = $api->getJson('/api/v1/finance/details?metric=revenue')->json('data');
    expect($revenue['total']['goods'])->toEqual($data['completed']['goods'])->and($revenue['total']['cogs'])->toEqual($data['cogs']);
    expect($api->getJson('/api/v1/finance/details?metric=payouts')->json('data.total.payout'))->toEqual(50000.0);

    // The P&L report shows the same fees.
    $pl = collect($api->getJson('/api/v1/reports/profit-loss')->json('data.lines'))->keyBy('key');
    expect($pl['marketplace_fees']['current'])->toEqual($data['fees']['total'])->and($pl['contribution_profit']['current'])->toEqual($data['contribution_profit']);
});

it('scopes the report to one shop, leaves business-wide figures out, and compares with the previous period', function () {
    [$tenant, $owner] = tenantWithOwner();
    multiShop($tenant);
    $variant = makeVariant($tenant, 'SKU-SCOPE');
    stockIn($tenant, $variant, 20);
    $a = fakeShop($tenant, name: 'Shop A');
    $b = fakeShop($tenant, name: 'Shop B');
    $orderA = fakeOrder($a, [[$variant, 1]], 'fake_completed');
    fakeOrder($b, [[$variant, 3]], 'fake_completed');
    $old = fakeOrder($a, [[$variant, 2]], 'fake_completed');
    // The older order was completed in the previous month.
    Order::withoutGlobalScopes()->whereKey($old->id)->update(['completed_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2), 'placed_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()]);

    $api = actingInTenant($owner, $tenant);
    $all = $api->getJson('/api/v1/finance/summary')->json('data');
    $scoped = $api->getJson("/api/v1/finance/summary?shop={$a->ulid}")->assertOk()->json('data');

    expect($all['completed']['orders'])->toBe(2)->and($all['by_shop'])->toHaveCount(2)
        ->and($scoped['scoped'])->toBeTrue()
        ->and($scoped['completed']['orders'])->toBe(1)
        ->and($scoped['completed']['goods'])->toEqual((float) $orderA->subtotal - (float) $orderA->discount)
        ->and($scoped['estimated_profit'])->toBeNull()->and($scoped['other_expenses'])->toBeNull()
        ->and($scoped['by_shop'])->toBe([])
        ->and(collect($scoped['shops'])->pluck('name')->all())->toContain('Shop A', 'Shop B');

    // Comparing a period with the one just before it: the older order is in the previous period.
    $completedOld = Order::withoutGlobalScopes()->find($old->id)->completed_at;
    $day = $completedOld->copy()->addDay()->setTimezone('Asia/Ho_Chi_Minh')->toDateString();
    $compare = $api->getJson("/api/v1/finance/summary?shop={$a->ulid}&from={$day}&to={$day}")->json('data');
    expect($compare['previous_range']['to'])->toBe($completedOld->copy()->setTimezone('Asia/Ho_Chi_Minh')->toDateString())
        ->and($compare['previous']['orders'])->toBe(1)
        ->and($compare['previous']['net_revenue'])->toEqual((float) $old->subtotal - (float) $old->discount);
    $prevRange = $api->getJson('/api/v1/finance/summary?from='.now()->subMonthNoOverflow()->startOfMonth()->toDateString().'&to='.now()->subMonthNoOverflow()->endOfMonth()->toDateString()."&shop={$a->ulid}")->json('data');
    expect($prevRange['completed']['orders'])->toBe(1);
});

it('does not let a shop of another business be used as a filter', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other] = tenantWithOwner();
    $foreign = fakeShop($other, name: 'Shop người khác');

    actingInTenant($owner, $tenant)->getJson("/api/v1/finance/summary?shop={$foreign->ulid}")->assertNotFound();
    actingInTenant($owner, $tenant)->getJson("/api/v1/finance/details?metric=revenue&shop={$foreign->ulid}")->assertNotFound();
});

it('hides cost and profit in the summary and the drill-down from members without finance.profit', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-HIDE');
    stockIn($tenant, $variant, 5);
    fakeOrder(fakeShop($tenant), [[$variant, 1]], 'fake_completed');
    $member = addMember($tenant, Role::ACCOUNTANT);
    // A business that took "see cost and profit" away from its accountants.
    DB::table('role_permissions')->where('role_id', Role::system(Role::ACCOUNTANT)->id)
        ->where('permission_id', DB::table('permissions')->where('key', 'finance.profit')->value('id'))->delete();

    $api = actingInTenant($member, $tenant);
    $data = $api->getJson('/api/v1/finance/summary')->assertOk()->json('data');
    expect($data['can_view_profit'])->toBeFalse()
        ->and($data['cogs'])->toBeNull()->and($data['contribution_profit'])->toBeNull()->and($data['estimated_profit'])->toBeNull()
        ->and($data['daily'][0]['gross_profit'])->toBeNull()->and($data['by_shop'][0]['profit'])->toBeNull()
        ->and($data['completed']['orders'])->toBe(1);
    $rows = $api->getJson('/api/v1/finance/details?metric=revenue')->assertOk()->json('data');
    expect($rows['rows'][0])->not->toHaveKey('cogs')->and($rows['total'])->not->toHaveKey('cogs');
});

it('keeps VAT out of net revenue, whether prices include it or it is added on top, and out of returns', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-VAT');
    stockIn($tenant, $variant, 10); // unit cost 50,000
    $api = actingInTenant($owner, $tenant);

    // Prices include VAT 10%: 110,000 is 100,000 of goods and 10,000 of VAT.
    $api->putJson('/api/v1/tenant/settings/tax', ['sale_rate' => 10, 'purchase_rate' => 0, 'prices_include_tax' => true])->assertOk();
    createSalesOrder($owner, $tenant, [[$variant, 1, 110000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 110000]]])->assertCreated();
    // Prices before VAT 10%: 200,000 of goods, 20,000 of VAT added on top.
    $api->putJson('/api/v1/tenant/settings/tax', ['sale_rate' => 10, 'purchase_rate' => 0, 'prices_include_tax' => false])->assertOk();
    $onTop = createSalesOrder($owner, $tenant, [[$variant, 2, 100000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 220000]]])->assertCreated()->json('data');

    $data = $api->getJson('/api/v1/finance/summary')->json('data');
    expect($data['completed']['goods'])->toEqual(300000.0)
        ->and($data['net_revenue'])->toEqual(300000.0)
        ->and($data['estimated_gross_profit'])->toEqual(150000.0);
    $pl = collect($api->getJson('/api/v1/reports/profit-loss')->json('data.lines'))->keyBy('key');
    expect($pl['sales_tax']['current'])->toEqual(10000)->and($pl['revenue']['current'])->toEqual(300000);

    // Voiding the sale with VAT on top refunds 220,000: 200,000 comes off revenue, not the VAT given back.
    $api->postJson("/api/v1/sales-orders/{$onTop['id']}/void-sale", ['reason' => 'Bấm nhầm'])->assertCreated();
    $after = $api->getJson('/api/v1/finance/summary')->json('data');
    expect($after['refunds'])->toEqual(200000.0)
        ->and($after['net_revenue'])->toEqual(100000.0)
        ->and($api->getJson('/api/v1/finance/details?metric=returns')->json('data.total.value'))->toEqual(200000.0);
    $revenue = $api->getJson('/api/v1/finance/details?metric=revenue')->json('data');
    expect($revenue['total']['goods'])->toEqual(300000.0)->and(collect($revenue['rows'])->sum('tax'))->toEqual(30000);
});
