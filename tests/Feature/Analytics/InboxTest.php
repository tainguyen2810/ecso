<?php

use App\Modules\Access\Models\Role;
use App\Modules\Sales\Models\SalesChannel;
use Illuminate\Support\Facades\DB;

it('lists what waits for the member, grouped by kind, with links to the filtered lists', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 3);
    DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('product_variant_id', $variant->id)->update(['safety_stock' => 5]);

    $channel = SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', SalesChannel::DEFAULT_NAME)->firstOrFail()->ulid;
    actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => $channel,
        'stage' => 'pending',
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1, 'unit_price' => 100000]],
    ])->assertCreated();

    $items = collect(actingInTenant($owner, $tenant)->getJson('/api/v1/inbox')->assertOk()->json('data'));

    $low = $items->firstWhere('key', 'stock.low');
    expect($low)->not->toBeNull()
        ->and($low['count'])->toBe(1)
        ->and($low['to'])->toBe('/purchasing/reorder')
        ->and($items->where('group', 'orders')->sum('count'))->toBeGreaterThanOrEqual(1)
        // Nothing with a zero count is listed.
        ->and($items->every(fn ($i) => $i['count'] > 0))->toBeTrue();
});

it('shows a member only the kinds their permissions cover', function () {
    [$tenant] = tenantWithOwner();
    $staff = addMember($tenant, Role::WAREHOUSE_STAFF);

    $items = collect(actingInTenant($staff, $tenant)->getJson('/api/v1/inbox')->assertOk()->json('data'));

    expect($items->where('group', 'money'))->toBeEmpty()
        ->and($items->pluck('key')->filter(fn ($k) => str_starts_with($k, 'sync.')))->toBeEmpty();
});

it('keeps one tenant out of another tenant\'s inbox', function () {
    [$tenantA, $ownerA] = tenantWithOwner('Shop A');
    [$tenantB] = tenantWithOwner('Shop B');
    $variant = makeVariant($tenantB, 'B-01');
    stockIn($tenantB, $variant, 1);
    DB::table('stock_balances')->where('tenant_id', $tenantB->id)->update(['safety_stock' => 5]);

    $items = collect(actingInTenant($ownerA, $tenantA)->getJson('/api/v1/inbox')->assertOk()->json('data'));

    expect($items->firstWhere('key', 'stock.low'))->toBeNull();
});
