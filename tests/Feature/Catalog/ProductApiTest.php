<?php

use App\Modules\Access\Models\Role;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function productPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Áo thun cotton',
        'description' => 'Vải 100% cotton',
        'variants' => [
            ['sku' => 'AT-TRANG-M', 'name' => 'Trắng / M', 'options' => ['Màu' => 'Trắng', 'Size' => 'M'], 'ref_price' => 159000, 'barcode' => '8930000000011'],
            ['sku' => 'AT-TRANG-L', 'name' => 'Trắng / L', 'options' => ['Màu' => 'Trắng', 'Size' => 'L'], 'ref_price' => 159000],
        ],
    ], $overrides);
}

function csvUpload(array $rows, string $name = 'products.csv'): UploadedFile
{
    $csv = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => str_contains((string) $c, ',') ? "\"{$c}\"" : $c, $r)), $rows));

    return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF".$csv);
}

it('creates a product with variants, category and brand', function () {
    $category = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/categories', ['name' => 'Áo'])->assertCreated()->json('data.id');
    $brand = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/brands', ['name' => 'ECSO'])->assertCreated()->json('data.id');

    $response = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload(['category_id' => $category, 'brand_id' => $brand]))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Áo thun cotton')
        ->assertJsonPath('data.category.name', 'Áo')
        ->assertJsonPath('data.brand.name', 'ECSO')
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.variants.0.options.Size', 'M')
        ->assertJsonPath('data.variants.0.cost_price', '0.0000');

    expect($response->json('data.id'))->toHaveLength(26)
        ->and($response->json('data'))->not->toHaveKey('tenant_id');
    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'product.created']);
});

it('accepts variants without optional price and weight', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', [
        'name' => 'Không giá',
        'variants' => [['sku' => 'NP-1', 'ref_price' => null, 'weight_gram' => null, 'barcode' => null, 'options' => null]],
    ])->assertCreated()->assertJsonPath('data.variants.0.ref_price', '0.00');
});

it('rejects duplicate SKUs case-insensitively within a tenant but allows them across tenants', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload())->assertCreated();

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload(['variants' => [['sku' => 'at-trang-m']]]))
        ->assertUnprocessable()->assertJsonValidationErrors('variants.0.sku');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload(['variants' => [['sku' => 'X-1'], ['sku' => 'x-1']]]))
        ->assertJsonValidationErrors('variants.1.sku');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload(['variants' => [['sku' => 'NEW-1', 'barcode' => '8930000000011']]]))
        ->assertJsonValidationErrors('variants.0.barcode');
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload(['variants' => [['sku' => 'bad sku!']]]))
        ->assertJsonValidationErrors('variants.0.sku');

    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/products/sku-check?sku=AT-TRANG-M')
        ->assertJsonPath('available', false)->assertJsonPath('product.name', 'Áo thun cotton');

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->postJson('/api/v1/products', productPayload())->assertCreated();
    actingInTenant($otherOwner, $other)->getJson('/api/v1/products/sku-check?sku=NEW-SKU')->assertJsonPath('available', true);
});

it('updates variants: edit, add and remove, refusing to remove a SKU with stock', function () {
    $product = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload())->json('data');
    [$m, $l] = $product['variants'];

    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/products/{$product['id']}", [
        'name' => 'Áo thun cotton 2',
        'variants' => [
            ['id' => $m['id'], 'sku' => 'AT-TRANG-M', 'name' => 'Trắng M'],
            ['sku' => 'AT-DEN-M', 'name' => 'Đen M'],
        ],
    ])->assertOk()->assertJsonPath('data.name', 'Áo thun cotton 2')->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.variants.1.sku', 'AT-DEN-M');

    expect(ProductVariant::withoutGlobalScopes()->withTrashed()->where('ulid', $l['id'])->first()->trashed())->toBeTrue();

    // Put stock on M, then try to drop it.
    inTenant($this->tenant);
    $variant = ProductVariant::query()->where('ulid', $m['id'])->first();
    app(InventoryService::class)->receive(defaultWarehouse($this->tenant), [['variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => 1]], 'k');
    app(TenantContext::class)->clear();

    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/products/{$product['id']}", ['variants' => [['sku' => 'ONLY-NEW']]])
        ->assertUnprocessable()->assertJsonValidationErrors('variants');
    actingInTenant($this->owner, $this->tenant)->deleteJson("/api/v1/products/{$product['id']}")->assertUnprocessable();

    // A variant id from another product is refused.
    $other = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload(['variants' => [['sku' => 'OTHER-1']]]))->json('data');
    actingInTenant($this->owner, $this->tenant)->putJson("/api/v1/products/{$product['id']}", [
        'variants' => [['id' => $other['variants'][0]['id'], 'sku' => 'OTHER-1']],
    ])->assertUnprocessable();
});

it('lists, searches and filters products with stock totals', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload())->assertCreated();
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload(['name' => 'Quần jean', 'variants' => [['sku' => 'QJ-30', 'barcode' => '123456']]]))->assertCreated();
    $archived = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload(['name' => 'Cũ', 'status' => 'archived', 'variants' => [['sku' => 'OLD']]]))->json('data.id');

    $names = fn (string $query = '') => collect(actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/products{$query}")->assertOk()->json('data'))->pluck('name')->all();

    expect($names())->toBe(['Quần jean', 'Áo thun cotton'])
        ->and($names('?q=QJ-30'))->toBe(['Quần jean'])
        ->and($names('?q=123456'))->toBe(['Quần jean'])
        ->and($names('?q=thun'))->toBe(['Áo thun cotton'])
        ->and($names('?status=archived'))->toBe(['Cũ']);

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/bulk-status', ['ids' => [$archived], 'status' => 'active'])->assertJsonPath('updated', 1);
    expect($names())->toHaveCount(3);
});

it('enforces product permissions', function () {
    $viewer = addMember($this->tenant, Role::VIEWER);
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload())->json('data.id');

    actingInTenant($viewer, $this->tenant)->getJson('/api/v1/products')->assertOk();
    actingInTenant($viewer, $this->tenant)->getJson("/api/v1/products/{$id}")->assertOk();
    actingInTenant($viewer, $this->tenant)->postJson('/api/v1/products', productPayload(['variants' => [['sku' => 'V-1']]]))->assertForbidden();
    actingInTenant($staff, $this->tenant)->putJson("/api/v1/products/{$id}", ['name' => 'x'])->assertForbidden();
    actingInTenant($staff, $this->tenant)->deleteJson("/api/v1/products/{$id}")->assertForbidden();
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/categories', ['name' => 'x'])->assertForbidden();
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/products/import', ['file' => csvUpload([['product_name', 'sku'], ['A', 'B']])])->assertForbidden();
});

it('isolates products between tenants', function () {
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $foreign = actingInTenant($otherOwner, $other)->postJson('/api/v1/products', productPayload(['name' => 'Hàng của B']))->json('data');
    $foreignCategory = actingInTenant($otherOwner, $other)->postJson('/api/v1/categories', ['name' => 'Danh mục B'])->json('data.id');

    $me = fn () => actingInTenant($this->owner, $this->tenant);
    $me()->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    $me()->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
    $me()->getJson("/api/v1/products/{$foreign['id']}")->assertNotFound();
    $me()->putJson("/api/v1/products/{$foreign['id']}", ['name' => 'hack'])->assertNotFound();
    $me()->deleteJson("/api/v1/products/{$foreign['id']}")->assertNotFound();
    $me()->patchJson("/api/v1/categories/{$foreignCategory}", ['name' => 'hack'])->assertNotFound();
    $me()->postJson('/api/v1/products/bulk-status', ['ids' => [$foreign['id']], 'status' => 'archived'])->assertJsonPath('updated', 0);
    $me()->postJson('/api/v1/products', productPayload(['category_id' => $foreignCategory, 'variants' => [['sku' => 'Z-1']]]))->assertUnprocessable();
    $me()->getJson('/api/v1/variants?q=AT-TRANG')->assertJsonCount(0, 'data');

    // tenant_id in the payload is ignored.
    $mine = $me()->postJson('/api/v1/products', productPayload(['tenant_id' => $other->id, 'variants' => [['sku' => 'MINE-1']]]))->json('data.id');
    expect(Product::withoutGlobalScopes()->where('ulid', $mine)->value('tenant_id'))->toBe($this->tenant->id);
    expect(Product::withoutGlobalScopes()->where('ulid', $foreign['id'])->value('name'))->toBe('Hàng của B');
});

it('imports products from CSV: groups by product_key, never by name, and creates opening stock', function () {
    $file = csvUpload([
        ['product_key', 'product_name', 'sku', 'variant_name', 'barcode', 'category', 'brand', 'ref_price', 'weight_gram', 'status', 'opening_qty', 'opening_cost'],
        ['AO', 'Áo thun', 'AO-M', 'M', '111', 'Áo', 'ECSO', '150000', '200', '', '10', '80000'],
        ['AO', 'Áo thun', 'AO-L', 'L', '', 'Áo', 'ECSO', '150000', '220', '', '5', '80000'],
        ['', 'Áo thun', 'AO-KHAC', '', '', 'Áo', '', '99000', '', '', '', ''],
    ]);

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/import', ['file' => $file, 'dry_run' => true, 'warehouse_id' => defaultWarehouse($this->tenant)->ulid])
        ->assertOk()->assertJsonPath('created', 3)->assertJsonPath('dry_run', true);
    expect(ProductVariant::withoutGlobalScopes()->count())->toBe(0);

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/import', [
        'file' => $file, 'warehouse_id' => defaultWarehouse($this->tenant)->ulid,
    ])->assertOk()->assertJsonPath('created', 3)->assertJsonPath('products', 2)->assertJsonPath('opening_receipt', 'RCV-000001');

    // Same name, no product_key ⇒ separate product.
    expect(Product::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('name', 'Áo thun')->count())->toBe(2);

    inTenant($this->tenant);
    $m = ProductVariant::query()->where('sku', 'AO-M')->first();
    expect(balanceOf(defaultWarehouse($this->tenant), $m)->on_hand)->toBe(10)
        ->and($m->cost_price)->toBe('80000.0000');
    app(TenantContext::class)->clear();

    // Re-import with an updated price: updates by exact SKU, does not add stock again (opening_qty on existing SKU is refused).
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/import', ['file' => csvUpload([
        ['product_name', 'sku', 'ref_price'],
        ['Áo thun', 'AO-M', '170000'],
    ])])->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('created', 0);
    expect(ProductVariant::withoutGlobalScopes()->where('sku', 'AO-M')->value('ref_price'))->toBe('170000.00');

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/import', ['file' => csvUpload([
        ['product_name', 'sku', 'opening_qty'],
        ['Áo thun', 'AO-M', '5'],
    ]), 'warehouse_id' => defaultWarehouse($this->tenant)->ulid])->assertUnprocessable()->assertJsonPath('errors.0.row', 2);
});

it('rejects an invalid import file entirely and reports each bad row', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload())->assertCreated();

    $response = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/import', ['file' => csvUpload([
        ['product_name', 'sku', 'ref_price', 'weight_gram'],
        ['Hợp lệ', 'OK-1', '1000', '10'],
        ['Trùng trong file', 'DUP', '', ''],
        ['Trùng trong file', 'dup', '', ''],
        ['', 'NO-NAME', '', ''],
        ['Giá sai', 'BAD-PRICE', 'abc', '-1'],
        ['SKU sai', 'có dấu cách', '', ''],
    ])])->assertUnprocessable();

    expect(collect($response->json('errors'))->pluck('row')->unique()->values()->all())->toBe([4, 5, 6, 7]);
    expect(ProductVariant::withoutGlobalScopes()->where('sku', 'OK-1')->exists())->toBeFalse();

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/import', ['file' => csvUpload([['name', 'code'], ['a', 'b']])])
        ->assertUnprocessable()->assertJsonPath('errors.0.message', 'Thiếu cột bắt buộc: product_name');
});

it('imports XLSX and exports CSV and XLSX', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['product_name', 'sku', 'ref_price']));
    $writer->addRow(Row::fromValues(['Mũ lưỡi trai', 'MU-01', 50000]));
    $writer->close();

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/import', [
        'file' => new UploadedFile($path, 'products.xlsx', null, null, true),
    ])->assertOk()->assertJsonPath('created', 1);

    $csv = actingInTenant($this->owner, $this->tenant)->get('/api/v1/products/export?format=csv')->assertOk()->streamedContent();
    expect($csv)->toContain('product_name,sku')->toContain('MU-01');

    $xlsx = actingInTenant($this->owner, $this->tenant)->get('/api/v1/products/export?format=xlsx')->assertOk()->streamedContent();
    $out = tempnam(sys_get_temp_dir(), 'out').'.xlsx';
    file_put_contents($out, $xlsx);
    $reader = new XlsxReader;
    $reader->open($out);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    expect($rows[1][2])->toBe('MU-01')->and($rows)->toHaveCount(2);

    actingInTenant($this->owner, $this->tenant)->get('/api/v1/products/import-template')->assertOk();
});

it('imports 10,000 SKUs well under two minutes', function () {
    $rows = [['product_key', 'product_name', 'sku', 'variant_name', 'ref_price', 'category', 'brand']];
    for ($i = 1; $i <= 10000; $i++) {
        $rows[] = ['P'.intdiv($i - 1, 4), 'Sản phẩm '.intdiv($i - 1, 4), sprintf('SKU-%05d', $i), 'Biến thể '.($i % 4), '1000', 'Danh mục '.($i % 20), 'Brand '.($i % 10)];
    }

    $start = microtime(true);
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/import', ['file' => csvUpload($rows)])
        ->assertOk()->assertJsonPath('created', 10000)->assertJsonPath('products', 2500);
    $seconds = microtime(true) - $start;

    fwrite(STDERR, sprintf("\n[import] 10,000 SKUs in %.1fs\n", $seconds));
    expect($seconds)->toBeLessThan(120);
})->group('slow');

it('uploads product images to tenant-scoped random paths', function () {
    Storage::fake('public');
    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', productPayload())->json('data.id');

    $image = actingInTenant($this->owner, $this->tenant)->post("/api/v1/products/{$id}/images", [
        'image' => UploadedFile::fake()->image('ao.jpg', 800, 800),
    ], ['Accept' => 'application/json'])->assertCreated()->json();

    $path = Product::withoutGlobalScopes()->where('ulid', $id)->first()->images()->withoutGlobalScopes()->first()->path;
    expect($path)->toStartWith("tenants/{$this->tenant->ulid}/products/")->not->toContain('ao.jpg');
    Storage::disk('public')->assertExists($path);

    actingInTenant($this->owner, $this->tenant)->post("/api/v1/products/{$id}/images", [
        'image' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    actingInTenant($this->owner, $this->tenant)->deleteJson("/api/v1/products/{$id}/images/{$image['id']}")->assertNoContent();
    Storage::disk('public')->assertMissing($path);
});
