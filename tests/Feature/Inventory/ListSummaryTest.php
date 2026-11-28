<?php

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Support\TenantContext;

/** Figures above the Kiểm hàng, Chuyển hàng and Điều chỉnh giá vốn lists (mẫu 28/11). */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->variant = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun']);
    stockIn($this->tenant, $this->variant, 10);
});

it('sums what this month\'s balanced stocktakes found and counts sheets per status', function () {
    $api = fn () => actingInTenant($this->owner, $this->tenant);
    $id = $api()->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid, 'reason' => 'stocktake', 'draft' => true,
        'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => 7]],
    ])->json('data.id');
    $api()->postJson("/api/v1/inventory/adjustments/{$id}/balance")->assertOk();
    $api()->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid, 'reason' => 'stocktake', 'draft' => true,
        'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => 7]],
    ])->assertCreated();

    $s = $api()->getJson('/api/v1/inventory/adjustments/summary')->assertOk()->json('data');
    expect($s['counts'])->toMatchArray(['all' => 2, 'draft' => 1, 'approved' => 1])
        ->and($s['month'])->toMatchArray(['sheets' => 1, 'skus' => 1, 'short_qty' => 3, 'surplus_qty' => 0])
        ->and($s['stale']['count'])->toBe(0); // counted just now

    $api()->getJson('/api/v1/inventory/adjustments?reason=stocktake&status=approved')->assertJsonPath('data.0.short_qty', -3)->assertJsonPath('data.0.surplus_qty', 0);
});

it('counts transfers on the road and received short', function () {
    TenantContext::run($this->tenant, fn () => Subscription::query()->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]));
    $api = fn () => actingInTenant($this->owner, $this->tenant);
    $hn = $api()->postJson('/api/v1/warehouses', ['code' => 'HN', 'name' => 'Kho HN'])->json('data.id');
    $id = $api()->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $this->warehouse->ulid, 'to_warehouse_id' => $hn,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 4]],
    ])->json('data.id');
    $api()->postJson("/api/v1/inventory/transfers/{$id}/dispatch")->assertOk();
    expect($api()->getJson('/api/v1/inventory/transfers/summary')->json('data.in_transit'))->toBe(['count' => 1, 'quantity' => 4]);

    $line = $api()->getJson("/api/v1/inventory/transfers/{$id}")->json('data.lines.0.line_id');
    $api()->postJson("/api/v1/inventory/transfers/{$id}/receive", ['lines' => [['line_id' => $line, 'received_qty' => 3]]])->assertOk();

    $s = $api()->getJson('/api/v1/inventory/transfers/summary')->json('data');
    expect($s['counts'])->toMatchArray(['all' => 1, 'received' => 1, 'in_transit' => 0])
        ->and($s['month'])->toBe(['count' => 1, 'quantity' => 4])
        ->and($s['short'])->toBe(['count' => 1, 'pending' => 1, 'quantity' => 1]);
    $api()->getJson('/api/v1/inventory/transfers')->assertJsonPath('data.0.total_qty', 4)->assertJsonPath('data.0.received_qty', 3);
    expect(Warehouse::withoutGlobalScopes()->where('ulid', $hn)->exists())->toBeTrue();
});

it('splits this month\'s cost adjustments into stock value lost and gained', function () {
    $other = makeVariant($this->tenant, 'AO-002');
    stockIn($this->tenant, $other, 5);
    $cost = (float) $this->variant->refresh()->cost_price;
    $api = fn () => actingInTenant($this->owner, $this->tenant);
    $api()->postJson('/api/v1/inventory/cost-adjustments', ['lines' => [
        ['variant_id' => $this->variant->ulid, 'new_cost' => $cost - 1000],
        ['variant_id' => $other->ulid, 'new_cost' => (float) $other->refresh()->cost_price + 2000],
    ]])->assertCreated();

    $s = $api()->getJson('/api/v1/inventory/cost-adjustments/summary')->assertOk()->json('data');
    expect($s['all'])->toBe(1)
        ->and($s['month'])->toMatchArray(['count' => 1, 'skus' => 2, 'down_skus' => 1, 'up_skus' => 1])
        ->and((float) $s['month']['decrease'])->toBe(-10000.0)
        ->and((float) $s['month']['increase'])->toBe(10000.0);
});
