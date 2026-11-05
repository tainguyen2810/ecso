<?php

use App\Modules\Geo\Models\Province;
use App\Modules\Geo\Models\Ward;
use App\Modules\Geo\Services\VnAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;

// Codes of the General Statistics Office, units in force since 1 July 2025.
const HCM = '79';
const SAI_GON = '26740';      // Phường Sài Gòn (was Phường Bến Nghé, Quận 1)
const BINH_LOI_TRUNG = '26905'; // Phường Bình Lợi Trung (was Phường 5, 6, 11, 13, Quận Bình Thạnh)
const HA_NOI = '01';

it('loads the 34 provinces and 3321 wards of the 2025 reform', function () {
    expect(Province::query()->count())->toBe(34)
        ->and(Ward::query()->count())->toBe(3321)
        ->and(Ward::query()->find(SAI_GON)->only(['name', 'province_code']))->toBe(['name' => 'Phường Sài Gòn', 'province_code' => HCM])
        ->and(Province::query()->find(HCM)->former)->toContain('Bình Dương');
});

it('lists provinces and the wards of one, with the former names that help find them', function () {
    [$tenant, $owner] = tenantWithOwner();

    actingInTenant($owner, $tenant)->getJson('/api/v1/geo/provinces')->assertOk()
        ->assertJsonCount(34, 'data')->assertJsonPath('data.0.code', HA_NOI)->assertHeader('Cache-Control', 'max-age=86400, public');

    $wards = actingInTenant($owner, $tenant)->getJson('/api/v1/geo/provinces/'.HCM.'/wards')->assertOk()->json('data');
    $ward = collect($wards)->firstWhere('code', BINH_LOI_TRUNG);
    expect($ward['former_hint'])->toContain('(Q. Bình Thạnh)')
        ->and($ward['search'])->toContain('phuong 11 quan binh thanh');

    actingInTenant($owner, $tenant)->getJson('/api/v1/geo/provinces/99/wards')->assertNotFound();
});

it('reads pasted addresses, also written with old wards, districts and provinces', function (string $text, ?string $ward, string $street) {
    $s = app(VnAddress::class)->suggest($text);

    expect($s['ward_code'])->toBe($ward)->and($s['street'])->toBe($street);
})->with([
    'new units' => ['45 Lê Lợi, Phường Sài Gòn, TP. Hồ Chí Minh', SAI_GON, '45 Lê Lợi'],
    'old ward + district, short forms' => ['12 Nguyễn Huệ, P. Bến Nghé, Q.1, TP.HCM', SAI_GON, '12 Nguyễn Huệ'],
    'no marks, no commas' => ['88 no trang long p11 binh thanh hcm', BINH_LOI_TRUNG, '88 no trang long'],
    'Hà Nội' => ['Số 5 ngõ 10 Láng Hạ, Phường Láng, Hà Nội', '00199', 'Số 5 ngõ 10 Láng Hạ'],
]);

it('does not guess a ward when the text names none clearly', function () {
    $s = app(VnAddress::class)->suggest('Ấp 2, Bình Dương');

    expect($s['province_code'])->toBe(HCM)->and($s['ward_code'])->toBeNull()
        ->and(app(VnAddress::class)->suggest('không có gì'))->toBeNull();
});

it('creates an order with a standard address and composes the text from it', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);

    $id = createSalesOrder($owner, $tenant, [[$variant, 1]], [
        'buyer_name' => 'Nguyễn Văn A', 'buyer_phone' => '0912345678', 'carrier' => 'GHN',
        'shipping_street' => ' 12 Nguyễn Huệ ', 'shipping_ward_code' => SAI_GON,
    ])->assertCreated()
        ->assertJsonPath('data.shipping_address', '12 Nguyễn Huệ, Phường Sài Gòn, Thành phố Hồ Chí Minh')
        ->assertJsonPath('data.shipping_province_code', HCM)
        ->assertJsonPath('data.shipping_ward_code', SAI_GON)
        ->json('data.id');

    $order = Order::withoutGlobalScopes()->where('ulid', $id)->sole();
    expect($order->shipping_street)->toBe('12 Nguyễn Huệ')
        ->and($order->getRawOriginal('shipping_street'))->not->toContain('Nguyễn Huệ'); // encrypted at rest
});

it('refuses an order for a carrier without province, ward, street, recipient and phone', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);

    createSalesOrder($owner, $tenant, [[$variant, 1]], ['carrier' => 'Viettel Post', 'shipping_address' => '12 Nguyễn Huệ, Q1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['shipping_ward_code', 'shipping_street', 'buyer_name', 'buyer_phone']);

    // A ward of another province.
    createSalesOrder($owner, $tenant, [[$variant, 1]], ['shipping_province_code' => HA_NOI, 'shipping_ward_code' => SAI_GON])
        ->assertUnprocessable()->assertJsonValidationErrors(['shipping_ward_code']);

    // Already booked with the carrier elsewhere (tracking number known): nothing to send, no standard address needed.
    createSalesOrder($owner, $tenant, [[$variant, 1]], ['carrier' => 'GHN', 'tracking_number' => 'GHN123', 'shipping_address' => '12 Nguyễn Huệ, Q1'])->assertCreated();

    // Self delivery or a pickup keeps working with a free-text address.
    createSalesOrder($owner, $tenant, [[$variant, 1]], ['carrier' => 'Shipper riêng', 'shipping_address' => '12 Nguyễn Huệ, Q1'])
        ->assertCreated()->assertJsonPath('data.shipping_ward_code', null);
});

it('takes the customer standard address onto a new order and keeps it on the customer and saved addresses', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);

    $customerId = actingInTenant($owner, $tenant)->postJson('/api/v1/customers', [
        'name' => 'Trần B', 'phone' => '0987654321', 'street' => '88 Nơ Trang Long', 'province_code' => HCM, 'ward_code' => BINH_LOI_TRUNG,
    ])->assertCreated()->assertJsonPath('data.address', '88 Nơ Trang Long, Phường Bình Lợi Trung, Thành phố Hồ Chí Minh')->json('data.id');

    createSalesOrder($owner, $tenant, [[$variant, 1]], ['customer_id' => $customerId])->assertCreated()
        ->assertJsonPath('data.shipping_ward_code', BINH_LOI_TRUNG)
        ->assertJsonPath('data.shipping_address', '88 Nơ Trang Long, Phường Bình Lợi Trung, Thành phố Hồ Chí Minh');

    actingInTenant($owner, $tenant)->postJson("/api/v1/customers/{$customerId}/addresses", ['label' => 'Công ty', 'street' => '1 Lê Duẩn', 'ward_code' => SAI_GON])
        ->assertCreated()->assertJsonPath('data.address', '1 Lê Duẩn, Phường Sài Gòn, Thành phố Hồ Chí Minh')->assertJsonPath('data.province_code', HCM);

    // Typing a plain address again replaces the parts: they no longer describe it.
    actingInTenant($owner, $tenant)->putJson("/api/v1/customers/{$customerId}", ['name' => 'Trần B', 'address' => 'Chợ Bến Thành'])->assertOk()
        ->assertJsonPath('data.ward_code', null)->assertJsonPath('data.address', 'Chợ Bến Thành');

    // A new customer typed on the order gets the standard address too.
    createSalesOrder($owner, $tenant, [[$variant, 1]], ['customer' => ['name' => 'Lê C', 'phone' => '0911222333', 'street' => '5 Hai Bà Trưng', 'ward_code' => SAI_GON]])->assertCreated()
        ->assertJsonPath('data.shipping_ward_code', SAI_GON);
    expect(Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'Lê C')->sole()->ward_code)->toBe(SAI_GON)
        ->and(CustomerAddress::withoutGlobalScopes()->sole()->street)->toBe('1 Lê Duẩn');
});

it('keeps the pickup address of a warehouse and of the store as standard parts', function () {
    [$tenant, $owner] = tenantWithOwner();
    $warehouse = defaultWarehouse($tenant);

    actingInTenant($owner, $tenant)->patchJson("/api/v1/warehouses/{$warehouse->ulid}", ['street' => '10 Pasteur', 'ward_code' => SAI_GON])->assertOk()
        ->assertJsonPath('data.address', '10 Pasteur, Phường Sài Gòn, Thành phố Hồ Chí Minh')->assertJsonPath('data.ward_code', SAI_GON);

    actingInTenant($owner, $tenant)->putJson('/api/v1/tenant/settings/store', ['address' => '106 Đình Nghi Xuân', 'province_code' => HCM, 'ward_code' => SAI_GON])->assertOk()
        ->assertJsonPath('data.store.ward', 'Phường Sài Gòn')->assertJsonPath('data.store.province', 'Thành phố Hồ Chí Minh');
});
