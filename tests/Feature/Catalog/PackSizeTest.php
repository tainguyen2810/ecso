<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Sales\Models\SalesChannel;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

/** "Nước suối": a bottle (base) and a case of 24 bottles (pack size). */
function waterPayload($test, array $overrides = []): array
{
    return array_replace([
        'name' => 'Nước suối 500ml',
        'unit' => 'Chai',
        'opening_warehouse_id' => $test->warehouse->ulid,
        'variants' => [
            ['sku' => 'NS-CHAI', 'name' => 'Chai', 'ref_price' => 6000, 'opening_qty' => 100, 'opening_cost' => 3500],
            ['sku' => 'NS-THUNG', 'name' => 'Thùng 24 chai', 'ref_price' => 130000, 'conversion' => ['base_sku' => 'ns-chai', 'quantity' => 24]],
        ],
    ], $overrides);
}

function packOrder($user, $tenant, string $variant, int $qty)
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail()->ulid,
        'lines' => [['variant_id' => $variant, 'quantity' => $qty, 'unit_price' => 130000]],
    ]);
}

function variantBySku($tenant, string $sku): ProductVariant
{
    return ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('sku', $sku)->firstOrFail();
}

it('creates a pack size whose stock is counted in base units', function () {
    $res = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', waterPayload($this))
        ->assertCreated()
        ->assertJsonPath('data.variants.1.components.0.variant.sku', 'NS-CHAI')
        ->assertJsonPath('data.variants.1.components.0.quantity', 24);
    $id = $res->json('data.id');

    $stock = collect(actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/inventory/products/{$id}/stock")->assertOk()->json('data'));
    expect($stock->firstWhere('variant.sku', 'NS-CHAI')['available'])->toBe(100)
        ->and($stock->firstWhere('variant.sku', 'NS-THUNG')['available'])->toBe(4)
        ->and($stock->firstWhere('variant.sku', 'NS-THUNG')['cost_price'])->toBe('84000.0000');

    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/variants?q=NS-THUNG&warehouse={$this->warehouse->ulid}")->assertJsonPath('data.0.stock.available', 4);
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/variants?q=NS-&stocked=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'NS-CHAI');
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/stock?q=NS-')->assertJsonCount(1, 'data');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/receipts', [
        'warehouse_id' => $this->warehouse->ulid,
        'lines' => [['variant_id' => variantBySku($this->tenant, 'NS-THUNG')->ulid, 'quantity' => 1, 'unit_cost' => 1]],
    ])->assertUnprocessable();
});

it('sells a case by holding and shipping bottles', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', waterPayload($this))->assertCreated();
    $bottle = variantBySku($this->tenant, 'NS-CHAI');

    $order = packOrder($this->owner, $this->tenant, variantBySku($this->tenant, 'NS-THUNG')->ulid, 2)->assertCreated()->json('data.id');
    expect(balanceOf($this->warehouse, $bottle)->reserved)->toBe(48);

    foreach (['confirm', 'pack'] as $action) {
        actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/sales-orders/{$order}/actions/{$action}")->assertOk();
    }
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/sales-orders/{$order}/actions/handover", ['carrier' => 'GHN', 'tracking_number' => 'T1'])->assertOk();

    expect(balanceOf($this->warehouse, $bottle)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 52, 'reserved' => 0]);
    inTenant($this->tenant);
    expect(OrderItem::query()->sole()->cost_snapshot)->toBe('84000.0000');
});

it('validates pack sizes', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $bad = fn (array $second) => waterPayload($this, ['opening_warehouse_id' => null, 'variants' => [['sku' => 'NS-CHAI'], $second]]);

    $api->postJson('/api/v1/products', $bad(['sku' => 'NS-THUNG', 'conversion' => ['base_sku' => 'KHONG-CO', 'quantity' => 24]]))
        ->assertUnprocessable()->assertJsonValidationErrors('variants.1.conversion.base_sku');
    $api->postJson('/api/v1/products', $bad(['sku' => 'NS-THUNG', 'conversion' => ['base_sku' => 'NS-CHAI', 'quantity' => 1]]))
        ->assertUnprocessable()->assertJsonValidationErrors('variants.1.conversion.quantity');
    // A pack of a pack, and opening stock on a pack.
    $api->postJson('/api/v1/products', waterPayload($this, ['opening_warehouse_id' => null, 'variants' => [
        ['sku' => 'NS-CHAI'],
        ['sku' => 'NS-LOC', 'conversion' => ['base_sku' => 'NS-CHAI', 'quantity' => 6]],
        ['sku' => 'NS-THUNG', 'conversion' => ['base_sku' => 'NS-LOC', 'quantity' => 4]],
    ]]))->assertUnprocessable()->assertJsonValidationErrors('variants.2.conversion.base_sku');
    $api->postJson('/api/v1/products', $bad(['sku' => 'NS-THUNG', 'opening_qty' => 2, 'conversion' => ['base_sku' => 'NS-CHAI', 'quantity' => 24]]))
        ->assertUnprocessable()->assertJsonValidationErrors('variants.1.opening_qty');

    // A combo cannot use a pack size as a component.
    $api->postJson('/api/v1/products', waterPayload($this))->assertCreated();
    $api->postJson('/api/v1/products', [
        'type' => 'combo', 'name' => 'Combo', 'variants' => [['sku' => 'CB-1']],
        'components' => [['variant_id' => variantBySku($this->tenant, 'NS-THUNG')->ulid, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('components.0.variant_id');
});

it('turns a pack back into a normal SKU and deletes a product with packs', function () {
    $res = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', waterPayload($this, ['opening_warehouse_id' => null, 'variants' => [
        ['sku' => 'NS-CHAI'],
        ['sku' => 'NS-THUNG', 'conversion' => ['base_sku' => 'NS-CHAI', 'quantity' => 24]],
    ]]))->assertCreated();
    $id = $res->json('data.id');
    $variants = $res->json('data.variants');

    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/products/{$id}", ['variants' => [
        ['id' => $variants[0]['id'], 'sku' => 'NS-CHAI'],
        ['id' => $variants[1]['id'], 'sku' => 'NS-THUNG'],
    ]])->assertOk()->assertJsonPath('data.variants.1.components', []);

    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/products/{$id}", ['variants' => [
        ['id' => $variants[0]['id'], 'sku' => 'NS-CHAI'],
        ['id' => $variants[1]['id'], 'sku' => 'NS-THUNG', 'conversion' => ['base_sku' => 'NS-CHAI', 'quantity' => 12]],
    ]])->assertOk()->assertJsonPath('data.variants.1.components.0.quantity', 12);

    actingInTenant($this->owner, $this->tenant)->deleteJson("/api/v1/products/{$id}")->assertNoContent();
    inTenant($this->tenant);
    expect(Product::query()->count())->toBe(0);
});
