<?php

use App\Modules\Access\Models\Role;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Services\StockVerifier;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

function detailedProduct(array $overrides = []): array
{
    return array_replace([
        'name' => 'Nước suối 500ml',
        'unit' => ' Chai ',
        'tags' => ['Đồ uống', 'đồ uống', ' Hàng mới '],
        'vat_rate' => 8,
        'variants' => [
            ['sku' => 'NS-500', 'ref_price' => 6000, 'wholesale_price' => 4800, 'barcode' => '8934563138165'],
            ['sku' => 'NS-500-THUNG', 'name' => 'Thùng 24 chai', 'ref_price' => 120000, 'wholesale_price' => 105000],
        ],
    ], $overrides);
}

it('saves unit, tags, VAT rate and wholesale price', function () {
    $res = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', detailedProduct())
        ->assertCreated()
        ->assertJsonPath('data.unit', 'Chai')
        ->assertJsonPath('data.tags', ['Đồ uống', 'Hàng mới'])
        ->assertJsonPath('data.vat_rate', '8.00')
        ->assertJsonPath('data.variants.0.wholesale_price', '4800.00');

    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/products/'.$res->json('data.id'), ['vat_rate' => null, 'tags' => [], 'unit' => ''])
        ->assertOk()->assertJsonPath('data.vat_rate', null)->assertJsonPath('data.tags', [])->assertJsonPath('data.unit', null);

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', detailedProduct(['vat_rate' => 120, 'variants' => [['sku' => 'X-1', 'wholesale_price' => -1]]]))
        ->assertUnprocessable()->assertJsonValidationErrors(['vat_rate', 'variants.0.wholesale_price']);
});

it('records opening stock in one opening receipt when the product is created', function () {
    $res = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', detailedProduct([
        'opening_warehouse_id' => $this->warehouse->ulid,
        'variants' => [
            ['sku' => 'NS-500', 'opening_qty' => 48, 'opening_cost' => 3500],
            ['sku' => 'NS-500-THUNG', 'opening_qty' => 0],
        ],
    ]))->assertCreated();

    inTenant($this->tenant);
    $receipt = StockReceipt::query()->sole();
    expect($receipt->source)->toBe(StockReceipt::SOURCE_OPENING)
        ->and($receipt->lines)->toHaveCount(1)
        ->and(StockMovement::query()->where('type', StockMovement::OPENING)->sum('on_hand_delta'))->toEqual(48);

    $stock = actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/products/'.$res->json('data.id').'/stock')->assertOk();
    expect(collect($stock->json('data'))->firstWhere('variant.sku', 'NS-500'))
        ->toMatchArray(['on_hand' => 48, 'available' => 48, 'cost_price' => '3500.0000']);
});

it('needs a warehouse for opening stock and only accepts it when creating', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', detailedProduct(['variants' => [['sku' => 'NS-1', 'opening_qty' => 5]]]))
        ->assertUnprocessable()->assertJsonValidationErrors('opening_warehouse_id');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', detailedProduct(['opening_warehouse_id' => 'nope', 'variants' => [['sku' => 'NS-1', 'opening_qty' => 5]]]))
        ->assertUnprocessable()->assertJsonValidationErrors('opening_warehouse_id');

    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', detailedProduct())->json('data.id');
    $variant = actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/products/{$id}")->json('data.variants.0');
    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/products/{$id}", ['variants' => [['id' => $variant['id'], 'sku' => 'NS-500', 'opening_qty' => 5]]])
        ->assertUnprocessable()->assertJsonValidationErrors('variants.0.opening_qty');

    inTenant($this->tenant);
    expect(StockReceipt::query()->count())->toBe(0);
});

it('filters the product list by tag, brand and creation date, and sorts by available stock', function () {
    $brand = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/brands', ['name' => 'Lavie'])->json('data.id');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', detailedProduct(['brand_id' => $brand]))->assertCreated();
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', ['name' => 'Bánh quy', 'tags' => ['Đồ ăn'], 'variants' => [['sku' => 'BQ-1']]])->assertCreated();
    stockIn($this->tenant, makeVariant($this->tenant, 'KEO-1', ['product_name' => 'Kẹo']), 30);
    inTenant($this->tenant);
    Product::query()->where('name', 'Kẹo')->update(['created_at' => now()->subDays(10)]);

    $names = fn (string $query) => collect(actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/products?{$query}")->assertOk()->json('data'))->pluck('name')->all();

    expect($names('tag=Đồ uống'))->toBe(['Nước suối 500ml'])
        ->and($names("brand={$brand}"))->toBe(['Nước suối 500ml'])
        ->and($names('created_to='.now()->subDays(5)->toDateString()))->toBe(['Kẹo'])
        ->and($names('created_from='.now()->toDateString()))->toHaveCount(2)
        ->and($names('sort=-available')[0])->toBe('Kẹo')
        ->and($names('sort=name'))->toBe(['Bánh quy', 'Kẹo', 'Nước suối 500ml']);

    expect(actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/products/tags')->assertOk()->json('data'))->toEqualCanonicalizing(['Đồ ăn', 'Đồ uống', 'Hàng mới']);
});

it('bulk deletes products and keeps the ones that still have stock', function () {
    $keep = makeVariant($this->tenant, 'CON-HANG', ['product_name' => 'Còn hàng']);
    stockIn($this->tenant, $keep, 3);
    $gone = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', ['name' => 'Hết hàng', 'variants' => [['sku' => 'HET-1']]])->json('data.id');
    inTenant($this->tenant);
    $keepId = $keep->product->ulid;

    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/products/bulk-delete', ['ids' => [$gone]])->assertForbidden();

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/bulk-delete', ['ids' => [$gone, $keepId]])
        ->assertOk()
        ->assertJsonPath('deleted', 1)
        ->assertJsonPath('skipped.0.name', 'Còn hàng');

    inTenant($this->tenant);
    expect(Product::query()->pluck('name')->all())->toBe(['Còn hàng']);
});

it('shows stock per warehouse with goods on order, and the product stock history', function () {
    $variant = makeVariant($this->tenant, 'AO-1', ['product_name' => 'Áo']);
    stockIn($this->tenant, $variant, 10);
    $supplier = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/suppliers', ['name' => 'NCC A'])->json('data.id');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier,
        'warehouse_id' => $this->warehouse->ulid,
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 7, 'unit_cost' => 50000]],
        'place' => true,
    ])->assertCreated();
    // A draft order is not on its way yet.
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier,
        'warehouse_id' => $this->warehouse->ulid,
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 100, 'unit_cost' => 50000]],
    ])->assertCreated();
    inTenant($this->tenant);
    $productId = $variant->product->ulid;

    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/inventory/products/{$productId}/stock")
        ->assertOk()
        ->assertJsonPath('data.0.warehouse.id', $this->warehouse->ulid)
        ->assertJsonPath('data.0.on_hand', 10)
        ->assertJsonPath('data.0.incoming', 7);

    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/inventory/products/{$productId}/movements")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.variant.sku', 'AO-1')
        ->assertJsonPath('data.0.on_hand_delta', 10);

    // Another business cannot read it.
    [$otherTenant, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $otherTenant)->getJson("/api/v1/inventory/products/{$productId}/stock")->assertNotFound();
    actingInTenant($otherOwner, $otherTenant)->getJson("/api/v1/inventory/products/{$productId}/movements")->assertNotFound();
});

it('returns exact SKUs for label printing by variant or product', function () {
    $a = makeVariant($this->tenant, 'A-1', ['product_name' => 'Sản phẩm A']);
    $b = makeVariant($this->tenant, 'B-1', ['product_name' => 'Sản phẩm B']);
    inTenant($this->tenant);
    $b->product->update(['status' => Product::STATUS_ARCHIVED]);
    $productA = $a->product->ulid;

    $skus = fn (string $query) => collect(actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/variants?{$query}")->assertOk()->json('data'))->pluck('sku')->all();

    expect($skus("ids[]={$a->ulid}&ids[]={$b->ulid}"))->toBe(['A-1', 'B-1'])
        ->and($skus("products[]={$productA}"))->toBe(['A-1'])
        // Search still hides archived products.
        ->and($skus('q=B-1'))->toBe([]);
});
