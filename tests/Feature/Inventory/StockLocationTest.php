<?php

use App\Modules\Access\Models\Role;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockVerifier;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->variant = makeVariant($this->tenant, 'AO-1', ['product_name' => 'Áo']);
    stockIn($this->tenant, $this->variant, 8);
    inTenant($this->tenant);
    $this->productId = $this->variant->product->ulid;
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

it('sets bin location and maximum stock without touching quantities', function () {
    $staff = addMember($this->tenant, Role::WAREHOUSE_STAFF);
    $url = "/api/v1/inventory/variants/{$this->variant->ulid}/location";

    // Warehouse staff may set where it is kept, not the maximum.
    actingInTenant($staff, $this->tenant)->putJson($url, ['warehouse' => $this->warehouse->ulid, 'bin_location' => ' A-03-2 '])
        ->assertOk()->assertJsonPath('data.bin_location', 'A-03-2');
    actingInTenant($staff, $this->tenant)->putJson($url, ['warehouse' => $this->warehouse->ulid, 'max_stock' => 5])->assertForbidden();

    actingInTenant($this->owner, $this->tenant)->putJson($url, ['warehouse' => $this->warehouse->ulid, 'max_stock' => 5])
        ->assertOk()->assertJsonPath('data.max_stock', 5)->assertJsonPath('data.bin_location', 'A-03-2');

    $row = collect(actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/inventory/products/{$this->productId}/stock")->json('data'))
        ->firstWhere('warehouse.id', $this->warehouse->ulid);
    expect($row)->toMatchArray(['on_hand' => 8, 'max_stock' => 5, 'bin_location' => 'A-03-2']);

    inTenant($this->tenant);
    expect(StockMovement::query()->count())->toBe(1); // only the stock-in
});
