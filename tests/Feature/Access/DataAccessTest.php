<?php

use App\Modules\Access\Models\Role;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Support\TenantContext;

/** Audit round C: cost prices need "products.cost.view"; a member can be limited to some warehouses. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->main = defaultWarehouse($this->tenant);
    $this->second = TenantContext::run($this->tenant, fn () => Warehouse::create(['code' => 'K2', 'name' => 'Kho 2']));
    $this->variant = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun']);
    stockIn($this->tenant, $this->variant, 10); // cost 50 000
    $this->staff = addMember($this->tenant, Role::WAREHOUSE_STAFF);
    $this->receipt = fn ($user, Warehouse $w, array $line = []) => actingInTenant($user, $this->tenant)->postJson('/api/v1/inventory/receipts', [
        'warehouse_id' => $w->ulid, 'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 2] + $line],
    ]);
});

it('hides cost prices and stock value from members without the cost permission', function () {
    $owner = actingInTenant($this->owner, $this->tenant);
    expect($owner->getJson('/api/v1/variants?q=AO')->json('data.0.cost_price'))->toBe('50000.0000')
        ->and($owner->getJson('/api/v1/inventory/summary')->json('data.inventory_value'))->not->toBeNull();

    $staff = actingInTenant($this->staff, $this->tenant);
    expect($staff->getJson('/api/v1/variants?q=AO')->json('data.0.cost_price'))->toBeNull()
        ->and($staff->getJson('/api/v1/inventory/stock')->json('data.0.cost_price'))->toBeNull()
        ->and($staff->getJson('/api/v1/inventory/summary')->json('data.inventory_value'))->toBeNull()
        ->and($staff->getJson("/api/v1/inventory/variants/{$this->variant->ulid}/card")->json('data.movements.0.unit_cost') ?? null)->toBeNull();

    // Managers and accountants keep seeing costs; order staff do not.
    expect(collect(Role::system(Role::MANAGER)->permissionKeys()))->toContain('products.cost.view')
        ->and(collect(Role::system(Role::ACCOUNTANT)->permissionKeys()))->toContain('products.cost.view')
        ->and(collect(Role::system(Role::ORDER_STAFF)->permissionKeys()))->not->toContain('products.cost.view');
});

it('takes a manual receipt by a member without the cost permission at the current average cost', function () {
    ($this->receipt)($this->staff, $this->main, ['unit_cost' => 1])->assertCreated()->assertJsonPath('data.lines.0.unit_cost', null);
    expect(TenantContext::run($this->tenant, fn () => (string) $this->variant->fresh()->cost_price))->toBe('50000.0000')
        ->and(balanceOf($this->main, $this->variant)->on_hand)->toBe(12);

    // With the permission the unit cost is required and moves the average.
    ($this->receipt)($this->owner, $this->main)->assertUnprocessable();
    ($this->receipt)($this->owner, $this->main, ['unit_cost' => 110000])->assertCreated();
    expect(TenantContext::run($this->tenant, fn () => (string) $this->variant->fresh()->cost_price))->toBe('58571.4285');
});

it('limits a member to the warehouses assigned to them', function () {
    $member = TenantContext::run($this->tenant, fn () => TenantUser::query()->where('user_id', $this->staff->id)->sole());
    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/members/{$member->ulid}", ['warehouses' => [$this->second->ulid]])
        ->assertOk()->assertJsonPath('data.warehouses', [$this->second->ulid]);
    actingInTenant($this->staff, $this->tenant)->getJson('/api/v1/tenant')->assertJsonPath('data.warehouses', [$this->second->ulid]);

    ($this->receipt)($this->staff, $this->main)->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id' => 'không được phân công']);
    ($this->receipt)($this->staff, $this->second)->assertCreated();

    // A transfer leaves an assigned warehouse for any warehouse, and is received by someone working there.
    $staff = actingInTenant($this->staff, $this->tenant);
    $id = $staff->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $this->second->ulid, 'to_warehouse_id' => $this->main->ulid, 'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 1]],
    ])->assertCreated()->json('data.id');
    $staff->postJson("/api/v1/inventory/transfers/{$id}/dispatch")->assertOk();
    $staff->postJson("/api/v1/inventory/transfers/{$id}/receive")->assertUnprocessable();
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/transfers/{$id}/receive")->assertOk();

    // Stocktakes too; an empty list gives every warehouse back.
    actingInTenant($this->staff, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->main->ulid, 'reason' => 'stocktake', 'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => 9]],
    ])->assertUnprocessable();
    actingInTenant($this->owner, $this->tenant)->patchJson("/api/v1/members/{$member->ulid}", ['warehouses' => []])->assertOk()->assertJsonPath('data.warehouses', []);
    ($this->receipt)($this->staff, $this->main)->assertCreated();
});
