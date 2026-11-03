<?php

use App\Modules\Access\Models\Role;
use App\Modules\Inventory\Services\StockVerifier;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->variant = makeVariant($this->tenant, 'AO-1', ['product_name' => 'Áo thun']);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

it('sets a new average cost, keeps the old one and averages the next receipt from it', function () {
    stockIn($this->tenant, $this->variant, 10); // at 50,000
    $other = makeVariant($this->tenant, 'AO-2');

    $res = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/cost-adjustments', [
        'note' => 'Giá nhập đầu kỳ ghi sai',
        'lines' => [['variant_id' => $this->variant->ulid, 'new_cost' => 42000], ['variant_id' => $other->ulid, 'new_cost' => 1000]],
    ])->assertCreated()
        ->assertJsonPath('data.number', 'CAD-000001')
        ->assertJsonPath('data.lines.0.old_cost', '50000.0000')
        ->assertJsonPath('data.lines.0.new_cost', '42000.0000')
        ->assertJsonPath('data.lines.0.on_hand', 10)
        ->assertJsonPath('data.lines.0.value_change', '-80000.00');

    expect($this->variant->refresh()->cost_price)->toBe('42000.0000')
        ->and(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(10);

    // 10 × 42,000 + 10 × 52,000 = 940,000 / 20
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/receipts', [
        'warehouse_id' => $this->warehouse->ulid,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 10, 'unit_cost' => 52000]],
    ])->assertCreated();
    expect($this->variant->refresh()->cost_price)->toBe('47000.0000');

    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/cost-adjustments?q=AO-2')->assertOk()->assertJsonPath('meta.total', 1);
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/cost-adjustments/'.$res->json('data.id'))->assertOk()->assertJsonCount(2, 'data.lines');
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'inventory.cost_adjusted']);
});

it('lets only approvers change cost and validates lines', function () {
    $staff = addMember($this->tenant, Role::WAREHOUSE_STAFF);
    actingInTenant($staff, $this->tenant)->getJson('/api/v1/inventory/cost-adjustments')->assertOk();
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/inventory/cost-adjustments', ['lines' => [['variant_id' => $this->variant->ulid, 'new_cost' => 1]]])
        ->assertForbidden();

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/cost-adjustments', ['lines' => [['variant_id' => $this->variant->ulid, 'new_cost' => -1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.new_cost');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/cost-adjustments', ['lines' => [
        ['variant_id' => $this->variant->ulid, 'new_cost' => 1], ['variant_id' => $this->variant->ulid, 'new_cost' => 2],
    ]])->assertUnprocessable();

    [$otherTenant] = tenantWithOwner('Shop B');
    $foreign = makeVariant($otherTenant, 'B-1');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/cost-adjustments', ['lines' => [['variant_id' => $foreign->ulid, 'new_cost' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.variant_id');
    expect($foreign->refresh()->cost_price)->toBe('0.0000');
});
