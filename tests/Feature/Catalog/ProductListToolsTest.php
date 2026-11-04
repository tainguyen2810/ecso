<?php

use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Tenancy\Support\TenantContext;

/** Audit round E: stock and price filters, the recycle bin, internal barcodes, usual supplier and package size. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->plenty = makeVariant($this->tenant, 'TEA-01', ['product_name' => 'Trà xanh', 'ref_price' => 30000]);
    $this->low = makeVariant($this->tenant, 'TEA-02', ['product_name' => 'Trà đen', 'ref_price' => 50000]);
    $this->none = makeVariant($this->tenant, 'TEA_03', ['product_name' => 'Trà ô long', 'ref_price' => 90000]);
    stockIn($this->tenant, $this->plenty, 50);
    stockIn($this->tenant, $this->low, 3);
    TenantContext::run($this->tenant, fn () => StockBalance::query()->where('product_variant_id', $this->low->id)->update(['safety_stock' => 5]));
    $this->api = actingInTenant($this->owner, $this->tenant);
});

it('filters products by stock state and sorts them by price', function () {
    $names = fn (string $query) => collect($this->api->getJson("/api/v1/products?{$query}")->assertOk()->json('data'))->pluck('name')->all();

    expect($names('stock=out'))->toBe(['Trà ô long'])
        ->and($names('stock=low'))->toBe(['Trà đen'])
        ->and($names('stock=in&sort=name'))->toEqualCanonicalizing(['Trà xanh', 'Trà đen'])
        ->and($names('sort=price'))->toBe(['Trà xanh', 'Trà đen', 'Trà ô long'])
        ->and($names('sort=-price'))->toBe(['Trà ô long', 'Trà đen', 'Trà xanh']);
});

it('treats % and _ in a search as plain characters', function () {
    expect($this->api->getJson('/api/v1/products?q=TEA_')->json('data'))->toHaveCount(1)
        ->and($this->api->getJson('/api/v1/products?q=%25')->json('data'))->toBe([])
        ->and($this->api->getJson('/api/v1/variants?q=TEA_')->json('data'))->toHaveCount(1);
});

it('lists deleted products in the recycle bin and restores them with their SKUs', function () {
    $id = TenantContext::run($this->tenant, fn () => $this->none->product->ulid);
    $this->api->deleteJson("/api/v1/products/{$id}")->assertNoContent();

    expect(collect($this->api->getJson('/api/v1/products')->json('data'))->pluck('id'))->not->toContain($id);
    $bin = $this->api->getJson('/api/v1/products?trashed=1')->assertOk()->json('data');
    expect($bin)->toHaveCount(1)->and($bin[0]['id'])->toBe($id)->and($bin[0]['variants'][0]['sku'])->toBe('TEA_03');
    // Searching the bin finds deleted SKUs too.
    expect($this->api->getJson('/api/v1/products?trashed=1&q=TEA_03')->json('data'))->toHaveCount(1);

    $this->api->postJson("/api/v1/products/{$id}/restore")->assertOk()->assertJsonPath('data.variants.0.sku', 'TEA_03');
    expect($this->api->getJson('/api/v1/products?trashed=1')->json('data'))->toBe([])
        ->and(TenantContext::run($this->tenant, fn () => ProductVariant::query()->where('sku', 'TEA_03')->exists()))->toBeTrue();
    // A product that is not deleted cannot be "restored".
    $this->api->postJson("/api/v1/products/{$id}/restore")->assertNotFound();
});

it('suggests an unused internal EAN-13 barcode with a valid check digit', function () {
    $code = $this->api->getJson('/api/v1/products/barcode-suggestion')->assertOk()->json('data.barcode');

    expect($code)->toMatch('/^2\d{12}$/')
        ->and(ProductController::ean13(substr($code, 0, 12)))->toBe($code)
        ->and(ProductController::ean13('893604850001'))->toBe('8936048500016'); // a real Vietnamese EAN-13
});

it('saves the usual supplier, its code and the package size of a SKU', function () {
    $supplier = $this->api->postJson('/api/v1/suppliers', ['name' => 'Trà Thái Nguyên'])->json('data.id');
    $product = TenantContext::run($this->tenant, fn () => $this->plenty->product->ulid);
    $variant = ['id' => $this->plenty->ulid, 'sku' => 'TEA-01', 'supplier_id' => $supplier, 'supplier_sku' => 'TN-500', 'length_mm' => 200, 'width_mm' => 80, 'height_mm' => 50];

    $this->api->putJson("/api/v1/products/{$product}", ['variants' => [$variant]])->assertOk()
        ->assertJsonPath('data.variants.0.supplier.id', $supplier)
        ->assertJsonPath('data.variants.0.supplier.name', 'Trà Thái Nguyên')
        ->assertJsonPath('data.variants.0.supplier_sku', 'TN-500')
        ->assertJsonPath('data.variants.0.length_mm', 200);

    // Another business's supplier is refused.
    [$other, $otherOwner] = tenantWithOwner();
    $foreign = actingInTenant($otherOwner, $other)->postJson('/api/v1/suppliers', ['name' => 'Khác'])->json('data.id');
    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/products/{$product}", ['variants' => [['supplier_id' => $foreign] + $variant]])->assertUnprocessable();
});
