<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductComponent;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerDebtEntry;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\RemoteImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Chuyển dữ liệu (C7): files exported from Sapo and KiotViet are read as they are. */
function migrationCsv(array $rows, string $name = 'xuat.csv'): UploadedFile
{
    $csv = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => preg_match('/[,"]/', (string) $c) ? '"'.str_replace('"', '""', (string) $c).'"' : $c, $r)), $rows));

    return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF".$csv);
}

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->variant = fn (string $sku) => ProductVariant::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('sku', $sku)->sole();
});

it('reads a Sapo product file: variants grouped by name, attribute values, prices with separators and opening stock', function () {
    $file = fn () => migrationCsv([
        ['Tên sản phẩm*', 'Mã SKU*', 'Barcode', 'Loại sản phẩm', 'Nhãn hiệu', 'Thuộc tính 1', 'Giá trị thuộc tính 1', 'Thuộc tính 2', 'Giá trị thuộc tính 2', 'Khối lượng', 'Đơn vị khối lượng', 'Đơn vị', 'PL_Giá bán lẻ', 'PL_Giá bán buôn', 'LC_CN1_Tồn kho ban đầu*', 'LC_CN1_Giá vốn khởi tạo*', 'Mô tả'],
        ['Áo thun cổ tròn', 'ATCT-DO-M', '8930001', 'Áo', 'Coolmate', 'Màu', 'Đỏ', 'Size', 'M', '0,25', 'kg', 'Cái', '150,000', '120,000', '10', '80.000', 'x'],
        ['Áo thun cổ tròn', 'ATCT-DO-L', '', 'Áo', 'Coolmate', 'Màu', 'Đỏ', 'Size', 'L', '250', 'g', 'Cái', '150,000', '', '5', '80.000', ''],
        ['Quần short', 'QS-01', '', 'Quần', '', '', '', '', '', '', '', '', '99000', '', '', '', ''],
        ['Hàng lỗi', 'mã có dấu cách', '', '', '', '', '', '', '', '', '', '', 'abc', '', '', '', ''],
    ], 'sapo-san-pham.csv');
    $warehouse = defaultWarehouse($this->tenant)->ulid;

    // The check names the source and how each column was read; the bad row stops the import unless skipped.
    $check = $this->api->postJson('/api/v1/products/import', ['file' => $file(), 'dry_run' => true, 'warehouse_id' => $warehouse])->assertUnprocessable();
    expect($check->json('source'))->toBe('sapo')
        ->and(collect($check->json('columns'))->firstWhere('header', 'PL_Giá bán lẻ')['key'])->toBe('ref_price')
        ->and(collect($check->json('columns'))->firstWhere('header', 'Mô tả')['key'])->toBe('description')
        ->and(collect($check->json('columns'))->firstWhere('header', 'LC_CN1_Tồn kho ban đầu*')['key'])->toBe('branch_qty')
        ->and($check->json('branches'))->toBe([['name' => 'CN1', 'quantity' => 15, 'warehouse_id' => $warehouse]])
        ->and(collect($check->json('errors'))->pluck('row')->unique()->values()->all())->toBe([5]);

    $done = $this->api->postJson('/api/v1/products/import', ['file' => $file(), 'skip_invalid' => true, 'warehouse_id' => $warehouse])->assertOk();
    expect($done->json('created'))->toBe(3)->and($done->json('products'))->toBe(2)->and($done->json('skipped.0.row'))->toBe(5)
        ->and($done->json('opening_receipt'))->not->toBeNull();

    $m = ($this->variant)('ATCT-DO-M');
    expect($m->name)->toBe('Đỏ / M')->and($m->ref_price)->toBe('150000.00')->and($m->wholesale_price)->toBe('120000.00')
        ->and($m->weight_gram)->toBe(250)->and($m->barcode)->toBe('8930001')
        ->and(($this->variant)('ATCT-DO-L')->product_id)->toBe($m->product_id)
        ->and(($this->variant)('ATCT-DO-L')->weight_gram)->toBe(250)
        ->and(Product::withoutGlobalScopes()->find($m->product_id)->unit)->toBe('Cái')
        ->and(balanceOf(defaultWarehouse($this->tenant), $m)->on_hand)->toBe(10);
    expect(ProductVariant::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('sku', 'like', '%dấu%')->exists())->toBeFalse();
});

it('reads a KiotViet product file: names without the attribute part, attributes, status and negative stock', function () {
    $this->api->postJson('/api/v1/products/import', ['file' => migrationCsv([
        ['Loại hàng', 'Nhóm hàng(3 Cấp)', 'Mã hàng', 'Mã vạch', 'Tên hàng', 'Thương hiệu', 'Giá bán', 'Giá vốn', 'Tồn kho', 'ĐVT', 'Thuộc tính', 'Trọng lượng', 'Đang kinh doanh'],
        ['Hàng hóa', 'Thời trang>>Giày', 'SP000021', '', 'Giày chạy bộ - Đen - 40', 'Bitis', '650000', '400000', '3', 'Đôi', 'MAUSAC:Đen|SIZE:40', '600', '1'],
        ['Hàng hóa', 'Thời trang>>Giày', 'SP000022', '', 'Giày chạy bộ - Đen - 41', 'Bitis', '650000', '400000', '-2', 'Đôi', 'MAUSAC:Đen|SIZE:41', '600', '0'],
    ], 'kiotviet-hang-hoa.csv'), 'warehouse_id' => defaultWarehouse($this->tenant)->ulid])->assertOk()
        ->assertJsonPath('source', 'kiotviet')->assertJsonPath('created', 2)->assertJsonPath('products', 1);

    $a = ($this->variant)('SP000021');
    $b = ($this->variant)('SP000022');
    $product = Product::withoutGlobalScopes()->find($a->product_id);
    expect($product->name)->toBe('Giày chạy bộ')->and($b->product_id)->toBe($a->product_id)
        ->and($a->name)->toBe('Đen / 40')->and($a->status)->toBe('active')->and($b->status)->toBe('inactive')
        ->and(balanceOf(defaultWarehouse($this->tenant), $a)->on_hand)->toBe(3)
        ->and(balanceOf(defaultWarehouse($this->tenant), $b)?->on_hand ?? 0)->toBe(0);
});

it('reads a KiotViet customer file with opening debts, and skips debts the user may not set', function () {
    $file = fn () => migrationCsv([
        ['Mã khách hàng', 'Tên khách hàng', 'Điện thoại', 'Địa chỉ', 'Khu vực giao hàng', 'Nhóm khách hàng', 'Ngày sinh', 'Giới tính', 'Email', 'Nợ cần thu hiện tại', 'Tổng bán'],
        ['KH000001', 'Nguyễn Văn An', '0912345678, 0987654321', '12 Lê Lợi', 'TP.HCM', 'Khách sỉ KiotViet', '15/08/1990', 'Nam', '', '1,500,000', '9000000'],
        ['KH000002', 'Trần Thị Bình', '0938111222', '', '', '', '', 'Nữ', '', '0', ''],
        ['KH000003', 'Lê Văn Cường', '0938111222', '', '', '', '', '', '', '200000', ''],
    ], 'kiotviet-khach.csv');

    $staff = addMember($this->tenant, 'ORDER_STAFF');
    $check = actingInTenant($staff, $this->tenant)->postJson('/api/v1/customers/import', ['file' => $file(), 'dry_run' => true, 'skip_invalid' => true])->assertOk();
    expect($check->json('source'))->toBe('kiotviet')->and($check->json('created'))->toBe(2)->and($check->json('debts'))->toBe(0)
        ->and($check->json('skipped.0.row'))->toBe(4)
        ->and(collect($check->json('warnings'))->pluck('message')->implode(' '))->toContain('không có quyền')->toContain('Khách sỉ KiotViet');

    $this->api = actingInTenant($this->owner, $this->tenant); // back to the owner, who may set debts
    $this->api->postJson('/api/v1/customers/import', ['file' => $file()])->assertUnprocessable(); // the repeated phone stops it
    $this->api->postJson('/api/v1/customers/import', ['file' => $file(), 'skip_invalid' => true])->assertOk()
        ->assertJsonPath('created', 2)->assertJsonPath('debts', 1);

    $an = Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('code', 'KH000001')->sole();
    expect($an->phone)->toBe('0912345678')->and($an->gender)->toBe('male')
        ->and(CustomerDebtEntry::withoutGlobalScopes()->where('customer_id', $an->id)->where('kind', CustomerDebtEntry::OPENING)->value('amount'))->toBe('1500000.00');

    // Importing the same file again updates the customers and does not add the debt twice.
    $again = $this->api->postJson('/api/v1/customers/import', ['file' => $file(), 'skip_invalid' => true])->assertOk();
    expect($again->json('updated'))->toBe(2)->and($again->json('debts'))->toBe(0)
        ->and(CustomerDebtEntry::withoutGlobalScopes()->where('customer_id', $an->id)->count())->toBe(1);
});

it('still reads EzSell\'s own files the old way', function () {
    $this->api->postJson('/api/v1/customers/import', ['file' => migrationCsv([['Tên khách hàng', 'Số điện thoại', 'Nhóm khách hàng'], ['An', '0912345678', 'Không có']])])
        ->assertUnprocessable()->assertJsonPath('source', 'ezsell')->assertJsonPath('errors.0.message', 'Chưa có nhóm khách hàng "Không có".');
});

it('brings each Sapo branch\'s stock into the warehouse chosen for it, and never twice', function () {
    $second = TenantContext::run($this->tenant, fn () => Warehouse::create(['code' => 'TD', 'name' => 'Kho Thủ Đức']));
    $main = defaultWarehouse($this->tenant);
    $file = fn () => migrationCsv([
        ['Tên sản phẩm', 'Mã SKU', 'PL_Giá nhập', 'LC_Cửa hàng chính_Tồn kho ban đầu', 'LC_Cửa hàng chính_Giá vốn khởi tạo', 'LC_Kho Thủ Đức_Tồn kho ban đầu', 'LC_Kho Thủ Đức_Giá vốn khởi tạo', 'LC_Hà Nội_Tồn kho ban đầu'],
        ['Cáp mạng', 'CAP-01', '5,000', '10', '6,000', '4', '', '7'],
        ['Chuột', 'CHUOT-01', '', '-2', '', '3', '90,000', '1'],
    ], 'sapo-chi-nhanh.csv');

    // Same name: Kho Thủ Đức. Other names: nothing until the user chooses.
    $check = $this->api->postJson('/api/v1/products/import', ['file' => $file(), 'dry_run' => true])->assertOk();
    expect(collect($check->json('branches'))->pluck('warehouse_id', 'name')->all())
        ->toBe(['Cửa hàng chính' => null, 'Kho Thủ Đức' => $second->ulid, 'Hà Nội' => null]);

    $this->api->postJson('/api/v1/products/import', ['file' => $file(), 'branch_warehouses' => ['Cửa hàng chính' => 'not-a-warehouse']])->assertUnprocessable();

    $done = $this->api->postJson('/api/v1/products/import', ['file' => $file(), 'branch_warehouses' => ['Cửa hàng chính' => $main->ulid, 'Hà Nội' => '']])->assertOk();
    expect($done->json('opening_receipts'))->toHaveCount(2)
        ->and(StockReceipt::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source', StockReceipt::SOURCE_OPENING)->count())->toBe(2);

    $cap = ($this->variant)('CAP-01');
    $chuot = ($this->variant)('CHUOT-01');
    expect(balanceOf($main, $cap)->on_hand)->toBe(10)->and(balanceOf($second, $cap)->on_hand)->toBe(4)
        ->and(balanceOf($main, $chuot)?->on_hand ?? 0)->toBe(0)->and(balanceOf($second, $chuot)->on_hand)->toBe(3)
        // The branch cost first, the purchase price when the branch has none.
        ->and((float) $cap->fresh()->cost_price)->toBe(round((10 * 6000 + 4 * 5000) / 14, 4))
        ->and((float) $chuot->fresh()->cost_price)->toBe(90000.0);

    // The same file again updates the SKUs and says the stock is left alone.
    $again = $this->api->postJson('/api/v1/products/import', ['file' => $file(), 'branch_warehouses' => ['Cửa hàng chính' => $main->ulid, 'Hà Nội' => '']])->assertOk();
    expect($again->json('updated'))->toBe(2)->and($again->json('opening_receipts'))->toBe([])
        ->and(collect($again->json('warnings'))->pluck('message')->implode(' '))->toContain('không cộng tồn kho')
        ->and(balanceOf($main, $cap->fresh())->on_hand)->toBe(10);
});

it('brings descriptions, tags and pictures over, mends SKUs with spaces and keeps a shared barcode on the first SKU', function () {
    Storage::fake('public');
    RemoteImage::$resolver = fn (string $host) => $host === 'cdn.example.vn' ? ['93.184.216.34'] : ['10.0.0.5'];
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    Http::fake(['cdn.example.vn/*' => Http::response($png, 200, ['Content-Type' => 'image/png']), '*' => Http::response('nope', 500)]);

    $done = $this->api->postJson('/api/v1/products/import', ['file' => migrationCsv([
        ['Tên sản phẩm', 'Mã SKU', 'Barcode', 'Mô tả sản phẩm', 'Tags', 'Ảnh đại diện', 'PL_Giá bán lẻ'],
        ['Màn hình', 'LCD PL241V8', 'ITS234', '<p>Tấm nền <b>IPS</b>&nbsp;24"</p><p>Bảo hành 12 tháng</p>', 'màn hình, LCD,màn hình', 'https://cdn.example.vn/a.png https://intranet.example.vn/b.png', '2,500,000'],
        ['Bàn phím', 'KB-01', 'ITS234', '', '', '', '300000'],
    ], 'sapo-mo-ta.csv')])->assertOk();

    expect($done->json('created'))->toBe(2)->and($done->json('images'))->toBe(2)
        ->and(collect($done->json('warnings'))->pluck('message')->implode(' '))->toContain('đổi thành LCD-PL241V8')->toContain('Mã vạch ITS234');
    $lcd = ($this->variant)('LCD-PL241V8');
    $product = Product::withoutGlobalScopes()->find($lcd->product_id);
    expect($lcd->barcode)->toBe('ITS234')->and(($this->variant)('KB-01')->barcode)->toBeNull()
        ->and($product->description)->toBe("Tấm nền IPS 24\"\nBảo hành 12 tháng")
        ->and($product->tags)->toBe(['màn hình', 'LCD']);

    // Only the public picture is kept: the other link points into a private network and is never called.
    $images = ProductImage::withoutGlobalScopes()->where('product_id', $product->id)->get();
    expect($images)->toHaveCount(1);
    Storage::disk('public')->assertExists($images[0]->path);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'intranet'));
    RemoteImage::$resolver = null;
});

it('reads the file the Sapo GO bookmark saves: real stock and average cost, combos and pack sizes', function () {
    Http::fake();
    $data = [
        'format' => 'ezsell-sapo-go', 'version' => 1,
        'locations' => [['id' => 7, 'name' => 'Chi nhánh mặc định']],
        'products' => [
            ['id' => 1, 'name' => 'Dây mạng Cat6', 'status' => 'active', 'product_type' => 'normal', 'description' => '', 'category' => 'Phụ kiện', 'brand' => 'Unitek', 'tags' => '', 'images' => [],
                'variants' => [
                    ['sku' => 'CL6ESTP', 'barcode' => '', 'opts' => ['Mặc định', null, null], 'unit' => 'Mét', 'status' => 'active', 'sellable' => true, 'retail' => 6000, 'wholesale' => 0, 'import' => 4000,
                        'weight_value' => 0.05, 'weight_unit' => 'kg', 'images' => [], 'packsize' => false, 'composite_items' => [], 'stock' => [['location_id' => 7, 'on_hand' => 283, 'mac' => 4250.5]]],
                    ['sku' => 'CL6-CUON', 'barcode' => '', 'opts' => [null, null, null], 'unit' => 'Cuộn', 'status' => 'active', 'sellable' => true, 'retail' => 500000, 'packsize' => true,
                        'packsize_quantity' => 100, 'packsize_root_sku' => 'CL6ESTP', 'composite_items' => [], 'stock' => [['location_id' => 7, 'on_hand' => 2, 'mac' => 0]]],
                ]],
            ['id' => 2, 'name' => 'Combo 30m dây mạng', 'status' => 'active', 'product_type' => 'composite', 'images' => [],
                'variants' => [['sku' => 'COMBO-30', 'opts' => ['Mặc định'], 'unit' => 'Bộ', 'status' => 'active', 'sellable' => true, 'retail' => 170000,
                    'composite_items' => [['sku' => 'CL6ESTP', 'quantity' => 30]], 'stock' => [['location_id' => 7, 'on_hand' => 9, 'mac' => 0]]]]],
            ['id' => 3, 'name' => 'Hết hàng âm kho', 'status' => 'active', 'product_type' => 'normal', 'images' => [],
                'variants' => [['sku' => 'AM-01', 'opts' => ['Mặc định'], 'status' => 'active', 'sellable' => false, 'stock' => [['location_id' => 7, 'on_hand' => -3, 'mac' => 100]]]]],
        ],
    ];
    $file = fn () => UploadedFile::fake()->createWithContent('sapo-du-lieu-itso.json', json_encode($data));
    $main = defaultWarehouse($this->tenant);

    $check = $this->api->postJson('/api/v1/products/import', ['file' => $file(), 'dry_run' => true, 'warehouse_id' => $main->ulid])->assertOk();
    expect($check->json('source'))->toBe('sapo')->and($check->json('created'))->toBe(4)
        ->and($check->json('branches'))->toBe([['name' => 'Chi nhánh mặc định', 'quantity' => 283, 'warehouse_id' => $main->ulid]]);

    $this->api->postJson('/api/v1/products/import', ['file' => $file(), 'warehouse_id' => $main->ulid])->assertOk()
        ->assertJsonPath('products', 3);

    $cable = ($this->variant)('CL6ESTP');
    $roll = ($this->variant)('CL6-CUON');
    $combo = ($this->variant)('COMBO-30');
    expect($cable->name)->toBeNull()->and($cable->weight_gram)->toBe(50)->and($cable->ref_price)->toBe('6000.00')
        ->and(balanceOf($main, $cable)->on_hand)->toBe(283)->and((float) $cable->fresh()->cost_price)->toBe(4250.5)
        // The pack is a SKU of the same product holding 100 of the base; it has no stock of its own.
        ->and($roll->product_id)->toBe($cable->product_id)->and($roll->name)->toBe('Cuộn')->and(balanceOf($main, $roll))->toBeNull()
        ->and(ProductComponent::withoutGlobalScopes()->where('product_variant_id', $roll->id)->sole()->only(['component_variant_id', 'quantity']))
        ->toBe(['component_variant_id' => $cable->id, 'quantity' => 100])
        ->and(Product::withoutGlobalScopes()->find($combo->product_id)->type)->toBe(Product::TYPE_COMBO)
        ->and(ProductComponent::withoutGlobalScopes()->where('product_variant_id', $combo->id)->sole()->quantity)->toBe(30)
        ->and(balanceOf($main, $combo))->toBeNull()
        ->and(($this->variant)('AM-01')->status)->toBe('inactive');

    $this->api->postJson('/api/v1/products/import', ['file' => UploadedFile::fake()->createWithContent('x.json', '{"products":[]}')])
        ->assertUnprocessable()->assertJsonPath('errors.0.row', 1);
});
