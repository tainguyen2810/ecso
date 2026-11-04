<?php

use App\Modules\Access\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('returns defaults, saves one section at a time and keeps the rest', function () {
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/tenant/settings')->assertOk()
        ->assertJsonPath('data.pricing.staff_edit_price', true)
        ->assertJsonPath('data.print.defaults.sale', 'a5')
        ->assertJsonPath('data.payments.methods.cash', true);

    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/store', ['name' => 'Tin học ITSO', 'phone' => '0909123456', 'address' => '106 Đình Nghi Xuân', 'tax_code' => '0312345678'])
        ->assertOk()->assertJsonPath('data.name', 'Tin học ITSO')->assertJsonPath('data.store.phone', '0909123456');
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/print', ['defaults' => ['purchase' => 'a5'], 'footer' => 'Đổi trả 7 ngày'])
        ->assertOk()->assertJsonPath('data.print.defaults.purchase', 'a5')->assertJsonPath('data.print.defaults.sale', 'a5')
        ->assertJsonPath('data.store.tax_code', '0312345678');
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/reasons', ['reasons' => [['label' => 'Hết hàng', 'cancel' => true, 'return' => false]]])
        ->assertOk()->assertJsonCount(1, 'data.reasons');

    expect($this->tenant->refresh()->name)->toBe('Tin học ITSO');
});

it('checks payment settings and bank accounts', function () {
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/payments', ['methods' => ['cash' => false]])
        ->assertUnprocessable()->assertJsonValidationErrors('default_pos');
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/payments', ['bank_accounts' => [['bank' => 'MB', 'number' => '12AB', 'holder' => 'ITSO']]])
        ->assertUnprocessable()->assertJsonValidationErrors('bank_accounts.0.number');
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/payments', [
        'bank_accounts' => [['bank' => 'MB', 'number' => '0909123456', 'holder' => 'CONG TY ITSO', 'is_default' => true]], 'default_pos' => 'card',
    ])->assertOk()->assertJsonPath('data.payments.bank_accounts.0.bank', 'MB')->assertJsonPath('data.payments.default_pos', 'card');
});

it('lets every member read but only settings managers change, and stores the logo', function () {
    Storage::fake('public');
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    actingInTenant($staff, $this->tenant)->getJson('/api/v1/tenant/settings')->assertOk();
    actingInTenant($staff, $this->tenant)->putJson('/api/v1/tenant/settings/sales', ['invoice_copies' => 2])->assertForbidden();

    $url = actingInTenant($this->owner, $this->tenant)->post('/api/v1/tenant/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.logo_url');
    expect($url)->toContain('/storage/tenants/');
    Storage::disk('public')->assertExists($this->tenant->refresh()->logo_path);
    actingInTenant($this->owner, $this->tenant)->post('/api/v1/tenant/logo', ['logo' => UploadedFile::fake()->create('x.svg', 3, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
    actingInTenant($this->owner, $this->tenant)->deleteJson('/api/v1/tenant/logo')->assertOk()->assertJsonPath('data.logo_url', null);
});

it('stops staff changing prices when the setting is off, but not managers', function () {
    $variant = makeVariant($this->tenant, 'GIA-1', ['ref_price' => 100000, 'wholesale_price' => 90000]);
    stockIn($this->tenant, $variant, 10);
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/pricing', ['staff_edit_price' => false])->assertOk();
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    $order = fn ($user, array $line, array $extra = []) => actingInTenant($user, $this->tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => defaultChannel($this->tenant)->ulid, 'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1, ...$line]], ...$extra,
    ]);

    $order($staff, ['unit_price' => 80000])->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
    $order($staff, ['discount' => 5000])->assertUnprocessable()->assertJsonValidationErrors('lines.0.discount');
    $order($staff, [], ['discount' => 1000])->assertUnprocessable()->assertJsonValidationErrors('discount');
    $order($staff, ['unit_price' => 90000])->assertCreated(); // the wholesale price is a list price
    $order($staff, [])->assertCreated();
    $order(addMember($this->tenant, Role::MANAGER), ['unit_price' => 80000])->assertCreated();
});

it('back-dates a sale only when allowed', function () {
    $variant = makeVariant($this->tenant, 'TG-1', ['ref_price' => 1000]);
    stockIn($this->tenant, $variant, 5);
    $body = ['channel_id' => defaultChannel($this->tenant)->ulid, 'placed_at' => now()->subDays(2)->toDateTimeString(), 'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1]]];

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/sales-orders', $body)->assertUnprocessable()->assertJsonValidationErrors('placed_at');
    actingInTenant($this->owner, $this->tenant)->putJson('/api/v1/tenant/settings/sales', ['allow_change_sale_time' => true])->assertOk();
    $placed = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/sales-orders', $body)->assertCreated()->json('data.placed_at');
    expect(substr($placed, 0, 10))->toBe(now()->subDays(2)->toDateString());
});
