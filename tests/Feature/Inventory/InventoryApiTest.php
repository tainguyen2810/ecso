<?php

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Tenancy\Support\TenantContext;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->variant = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun']);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

function receiveVia($test, $user, $tenant, $warehouse, $variant, int $qty, $cost = 100)
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/inventory/receipts', [
        'warehouse_id' => $warehouse->ulid,
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => $qty, 'unit_cost' => $cost]],
    ]);
}

function upgradePlan($tenant, string $plan = 'pro'): void
{
    TenantContext::run($tenant, fn () => Subscription::query()->update(['plan_id' => Plan::query()->where('key', $plan)->value('id')]));
}

it('lists warehouses with plan usage and enforces the warehouse limit', function () {
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/warehouses')
        ->assertOk()->assertJsonPath('data.0.code', 'MAIN')->assertJsonPath('meta.limit', 1)->assertJsonPath('meta.used', 1);

    // Free plan: 1 warehouse.
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'hn', 'name' => 'Kho Hà Nội'])
        ->assertForbidden()->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');

    upgradePlan($this->tenant);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'hn', 'name' => 'Kho Hà Nội'])
        ->assertCreated()->assertJsonPath('data.code', 'HN')->assertJsonPath('data.is_default', false);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'HN', 'name' => 'Trùng'])
        ->assertUnprocessable();
});

it('protects the default warehouse and warehouses with stock', function () {
    upgradePlan($this->tenant);
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 5)->assertCreated();

    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/warehouses/{$this->warehouse->ulid}", ['status' => 'inactive'])
        ->assertUnprocessable()->assertJsonPath('code', 'INVALID_STOCK_OPERATION');
    actingInTenant($this->owner, $this->tenant)->deleteJson("/api/v1/warehouses/{$this->warehouse->ulid}")->assertUnprocessable();

    $hn = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'HN', 'name' => 'Kho HN'])->json('data.id');
    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/warehouses/{$hn}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
    expect($this->warehouse->refresh()->is_default)->toBeFalse();
    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/warehouses/{$this->warehouse->ulid}", ['status' => 'inactive'])
        ->assertUnprocessable(); // still has stock

    // Inactive warehouses refuse new documents.
    $empty = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'X', 'name' => 'Trống'])->json('data.id');
    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/warehouses/{$empty}", ['status' => 'inactive'])->assertOk();
    receiveVia($this, $this->owner, $this->tenant, Warehouse::withoutGlobalScopes()->where('ulid', $empty)->first(), $this->variant, 1)
        ->assertUnprocessable();
    actingInTenant($this->owner, $this->tenant)->deleteJson("/api/v1/warehouses/{$empty}")->assertNoContent();
});

it('receives goods through a numbered receipt and shows them on the stock list and stock card', function () {
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 12, 150)
        ->assertCreated()
        ->assertJsonPath('data.number', 'RCV-000001')
        ->assertJsonPath('data.total_quantity', 12)
        ->assertJsonPath('data.lines.0.variant.sku', 'AO-001');
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 3, 200)->assertJsonPath('data.number', 'RCV-000002');

    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/stock')
        ->assertOk()
        ->assertJsonPath('data.0.variant.sku', 'AO-001')
        ->assertJsonPath('data.0.on_hand', 15)
        ->assertJsonPath('data.0.available', 15)
        ->assertJsonPath('data.0.cost_price', '160.0000');

    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/inventory/variants/{$this->variant->ulid}/card")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'RECEIPT')
        ->assertJsonPath('data.0.on_hand_after', 15)
        ->assertJsonPath('data.0.reference.number', 'RCV-000002')
        ->assertJsonPath('data.0.actor', $this->owner->name)
        ->assertJsonPath('balances.0.on_hand', 15);

    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/summary')
        ->assertOk()->assertJsonPath('data.on_hand', 15)->assertJsonPath('data.inventory_value', '2400');
});

it('filters stock by state and supports safety stock', function () {
    $low = makeVariant($this->tenant, 'LOW-1');
    makeVariant($this->tenant, 'OUT-1');
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 50);
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $low, 2);

    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/inventory/variants/{$low->ulid}/safety-stock", [
        'warehouse' => $this->warehouse->ulid, 'safety_stock' => 5,
    ])->assertOk()->assertJsonPath('data.safety_stock', 5);

    $skus = fn (string $state) => collect(actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/inventory/stock?state={$state}")->json('data'))->pluck('variant.sku')->all();
    expect($skus('low'))->toBe(['LOW-1'])
        ->and($skus('out'))->toBe(['OUT-1'])
        ->and($skus('in'))->toBe(['AO-001', 'LOW-1']);

    $row = collect(actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/stock?q=LOW')->json('data'))->first();
    expect($row['sellable'])->toBe(0)->and($row['available'])->toBe(2);

    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/summary')
        ->assertJsonPath('data.low_stock', 1)->assertJsonPath('data.out_of_stock', 1)->assertJsonPath('data.skus', 3);
});

it('runs the adjustment approval workflow', function () {
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 10);
    $staff = addMember($this->tenant, Role::WAREHOUSE_STAFF);
    $manager = addMember($this->tenant, Role::MANAGER);

    $id = actingInTenant($staff, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid,
        'reason' => 'stocktake',
        'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => 8]],
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.number', 'ADJ-000001')->json('data.id');

    // Pending adjustments do not touch stock.
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(10);

    actingInTenant($staff, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertForbidden();

    actingInTenant($manager, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$id}/approve", ['note' => 'OK'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decided_by', $manager->name)
        ->assertJsonPath('data.lines.0.on_hand_before', 10)
        ->assertJsonPath('data.lines.0.applied_delta', -2);
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(8);

    // Approving twice is refused and changes nothing.
    actingInTenant($manager, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertUnprocessable();
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(8);

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'inventory.adjustment.approved']);
});

it('rejects and cancels adjustments without moving stock', function () {
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 10);
    $staff = addMember($this->tenant, Role::WAREHOUSE_STAFF);
    $other = addMember($this->tenant, Role::WAREHOUSE_STAFF);
    $create = fn ($user) => actingInTenant($user, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid, 'reason' => 'damaged',
        'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'delta', 'quantity' => -3]],
    ])->json('data.id');

    $a = $create($staff);
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$a}/reject")->assertUnprocessable(); // note required
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$a}/reject", ['note' => 'Sai số lượng'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    $b = $create($staff);
    actingInTenant($other, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$b}/cancel")->assertForbidden();
    actingInTenant($staff, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$b}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(10);
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/adjustments?status=rejected')->assertJsonCount(1, 'data');
});

it('refuses an approval that would make stock negative', function () {
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 2);
    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid, 'reason' => 'lost',
        'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'delta', 'quantity' => -5]],
    ])->json('data.id');

    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$id}/approve")
        ->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    expect(InventoryAdjustment::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe('pending');
});

it('validates adjustment lines', function () {
    $post = fn (array $lines) => actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid, 'reason' => 'stocktake', 'lines' => $lines,
    ]);

    $post([['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => -1]])->assertUnprocessable();
    $post([['variant_id' => $this->variant->ulid, 'mode' => 'delta', 'quantity' => 0]])->assertUnprocessable();
    $post([['variant_id' => $this->variant->ulid, 'mode' => 'delta', 'quantity' => 1], ['variant_id' => $this->variant->ulid, 'mode' => 'delta', 'quantity' => 2]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.1.variant_id');
    $post([['variant_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'mode' => 'delta', 'quantity' => 1]])->assertJsonValidationErrors('lines.0.variant_id');
});

it('transfers stock between warehouses: draft, in transit, received', function () {
    upgradePlan($this->tenant);
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 10, 80);
    $hn = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'HN', 'name' => 'Kho HN'])->json('data.id');
    $hnModel = Warehouse::withoutGlobalScopes()->where('ulid', $hn)->first();

    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $this->warehouse->ulid, 'to_warehouse_id' => $hn,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 4]],
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.number', 'TRF-000001')->json('data.id');

    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(10);

    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/dispatch")->assertOk()->assertJsonPath('data.status', 'in_transit');
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(6)
        ->and(balanceOf($hnModel, $this->variant)?->on_hand ?? 0)->toBe(0);

    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/dispatch")->assertUnprocessable();
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/receive")->assertOk()->assertJsonPath('data.status', 'received');
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/receive")->assertUnprocessable();

    expect(balanceOf($hnModel, $this->variant)->on_hand)->toBe(4)
        ->and($this->variant->refresh()->cost_price)->toBe('80.0000');
});

it('returns goods to the source when an in-transit transfer is cancelled', function () {
    upgradePlan($this->tenant);
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 10);
    $hn = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'HN', 'name' => 'Kho HN'])->json('data.id');
    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $this->warehouse->ulid, 'to_warehouse_id' => $hn,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 4]],
    ])->json('data.id');

    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/dispatch")->assertOk();
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/cancel")->assertUnprocessable();

    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(10)
        ->and(StockTransfer::withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe('cancelled');
});

it('refuses to dispatch more than available and transfers to the same warehouse', function () {
    upgradePlan($this->tenant);
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 1);
    $hn = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'HN', 'name' => 'Kho HN'])->json('data.id');

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $this->warehouse->ulid, 'to_warehouse_id' => $this->warehouse->ulid,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 1]],
    ])->assertUnprocessable();

    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $this->warehouse->ulid, 'to_warehouse_id' => $hn,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 5]],
    ])->json('data.id');
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/dispatch")
        ->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_STOCK')->assertJsonPath('available', 1);
});

it('enforces inventory permissions per role', function (string $role, array $expect) {
    $user = addMember($this->tenant, $role);
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $this->variant, 5);

    $status = fn ($response) => $response->status();
    $actual = [
        'view' => $status(actingInTenant($user, $this->tenant)->getJson('/api/v1/inventory/stock')),
        'receive' => $status(receiveVia($this, $user, $this->tenant, $this->warehouse, $this->variant, 1)),
        'adjust' => $status(actingInTenant($user, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse->ulid, 'reason' => 'other',
            'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'delta', 'quantity' => 1]],
        ])),
        'safety' => $status(actingInTenant($user, $this->tenant)->putJson("/api/v1/inventory/variants/{$this->variant->ulid}/safety-stock", [
            'warehouse' => $this->warehouse->ulid, 'safety_stock' => 1,
        ])),
        'warehouse' => $status(actingInTenant($user, $this->tenant)->postJson('/api/v1/warehouses', ['code' => 'Z', 'name' => 'Z'])),
    ];

    expect($actual)->toBe($expect);
})->with([
    // Creating warehouses needs settings.manage (owner/admin only).
    'manager' => [Role::MANAGER, ['view' => 200, 'receive' => 201, 'adjust' => 201, 'safety' => 200, 'warehouse' => 403]],
    'warehouse staff' => [Role::WAREHOUSE_STAFF, ['view' => 200, 'receive' => 201, 'adjust' => 201, 'safety' => 403, 'warehouse' => 403]],
    'order staff' => [Role::ORDER_STAFF, ['view' => 200, 'receive' => 403, 'adjust' => 403, 'safety' => 403, 'warehouse' => 403]],
    'accountant' => [Role::ACCOUNTANT, ['view' => 200, 'receive' => 403, 'adjust' => 403, 'safety' => 403, 'warehouse' => 403]],
    'viewer' => [Role::VIEWER, ['view' => 200, 'receive' => 403, 'adjust' => 403, 'safety' => 403, 'warehouse' => 403]],
]);

it('isolates inventory between tenants', function () {
    [$otherTenant, $otherOwner] = tenantWithOwner('Shop B');
    $otherVariant = makeVariant($otherTenant, 'B-SKU');
    $otherWarehouse = defaultWarehouse($otherTenant);
    receiveVia($this, $otherOwner, $otherTenant, $otherWarehouse, $otherVariant, 9)->assertCreated();
    $adjustment = actingInTenant($otherOwner, $otherTenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $otherWarehouse->ulid, 'reason' => 'other',
        'lines' => [['variant_id' => $otherVariant->ulid, 'mode' => 'delta', 'quantity' => 1]],
    ])->json('data.id');
    $receipt = actingInTenant($otherOwner, $otherTenant)->getJson('/api/v1/inventory/receipts')->json('data.0.id');

    $me = fn () => actingInTenant($this->owner, $this->tenant);

    // Lists never contain the other tenant's rows.
    expect(collect($me()->getJson('/api/v1/inventory/stock')->json('data'))->pluck('variant.sku')->all())->toBe(['AO-001']);
    expect(collect($me()->getJson('/api/v1/warehouses')->json('data'))->pluck('id')->all())->toBe([$this->warehouse->ulid]);
    $me()->getJson('/api/v1/inventory/receipts')->assertJsonCount(0, 'data');

    // Direct access by ULID is 404.
    $me()->getJson("/api/v1/inventory/variants/{$otherVariant->ulid}/card")->assertNotFound();
    $me()->getJson("/api/v1/inventory/adjustments/{$adjustment}")->assertNotFound();
    $me()->postJson("/api/v1/inventory/adjustments/{$adjustment}/approve")->assertNotFound();
    $me()->getJson("/api/v1/inventory/receipts/{$receipt}")->assertNotFound();
    $me()->patchJson("/api/v1/warehouses/{$otherWarehouse->ulid}", ['name' => 'x'])->assertNotFound();
    $me()->getJson("/api/v1/inventory/stock?warehouse={$otherWarehouse->ulid}")->assertNotFound();

    // Foreign ids inside a document are validation errors, never accepted.
    receiveVia($this, $this->owner, $this->tenant, $this->warehouse, $otherVariant, 1)->assertJsonValidationErrors('lines.0.variant_id');
    receiveVia($this, $this->owner, $this->tenant, $otherWarehouse, $this->variant, 1)->assertJsonValidationErrors('warehouse_id');

    expect(balanceOf($otherWarehouse, $otherVariant)->on_hand)->toBe(9);
    expect(app(StockVerifier::class)->verify($otherTenant->id))->toBe([]);
});
