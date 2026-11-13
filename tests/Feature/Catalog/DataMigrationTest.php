<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerDebtEntry;
use Illuminate\Http\UploadedFile;

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
        ->and(collect($check->json('columns'))->firstWhere('header', 'Mô tả')['key'])->toBeNull()
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
