<?php

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;

it('separates ordered and completed revenue from money received, and leaves marketplace fees UNKNOWN', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-F');
    stockIn($tenant, $variant, 20); // unit cost 50,000
    $shop = fakeShop($tenant);

    $delivered = fakeOrder($shop, [[$variant, 2]], 'fake_delivered');
    fakeOrder($shop, [[$variant, 1]], 'fake_ready');
    fakeOrder($shop, [[$variant, 1]], 'fake_cancelled');

    $data = actingInTenant($owner, $tenant)->getJson('/api/v1/finance/summary')->assertOk()->json('data');

    expect($data['ordered']['orders'])->toBe(2)
        ->and($data['completed']['orders'])->toBe(1)
        ->and($data['completed']['revenue'])->toEqual((float) $delivered->total)
        ->and($data['cancelled']['orders'])->toBe(1)
        ->and($data['cogs'])->toEqual(100000.0)
        ->and($data['estimated_gross_profit'])->toEqual(round((float) $delivered->subtotal - (float) $delivered->discount - 100000, 2))
        ->and($data['marketplace_fees'])->toBeNull()
        ->and($data['payout_received'])->toBeNull()
        ->and($data['by_shop'][0]['orders'])->toBe(2)
        ->and(collect($data['daily'])->sum('orders'))->toBe(2);
});

it('requires finance.view for the finance dashboard', function () {
    [$tenant] = tenantWithOwner();

    actingInTenant(addMember($tenant, Role::WAREHOUSE_STAFF), $tenant)->getJson('/api/v1/finance/summary')->assertForbidden();
    actingInTenant(addMember($tenant, Role::ACCOUNTANT), $tenant)->getJson('/api/v1/finance/summary')->assertOk();
});

it('ranks best sellers by shipped units and gates slow movers by the reports.advanced entitlement', function () {
    [$tenant, $owner] = tenantWithOwner();
    $fast = makeVariant($tenant, 'SKU-FAST');
    $slow = makeVariant($tenant, 'SKU-SLOW');
    stockIn($tenant, $fast, 50);
    stockIn($tenant, $slow, 50);
    $shop = fakeShop($tenant);
    fakeOrder($shop, [[$fast, 3]], 'fake_shipped');
    fakeOrder($shop, [[$fast, 2], [$slow, 1]], 'fake_ready'); // not shipped yet: not counted

    $best = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/best-sellers')->assertOk()->json('data');
    expect($best)->toHaveCount(1)
        ->and($best[0]['variant']['sku'])->toBe('SKU-FAST')
        ->and($best[0]['units'])->toBe(3);

    // Free plan: no advanced reports (by entitlement, not by plan name).
    actingInTenant($owner, $tenant)->getJson('/api/v1/reports/slow-movers')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');

    Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]);
    $slowRows = actingInTenant($owner, $tenant)->getJson('/api/v1/reports/slow-movers')->assertOk()->json('data');
    expect(collect($slowRows)->pluck('variant.sku')->all())->toBe(['SKU-SLOW']);
});
