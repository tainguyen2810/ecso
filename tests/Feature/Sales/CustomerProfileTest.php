<?php

use App\Modules\Access\Models\Role;
use App\Modules\Audit\Models\AuditLog;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
    $this->variant = makeVariant($this->tenant, 'NHOM-1', ['ref_price' => 100000, 'wholesale_price' => 90000]);
    stockIn($this->tenant, $this->variant, 50);
});

it('keeps type, source and invoice details, and fills the source from the first order channel', function () {
    $id = ($this->as)()->postJson('/api/v1/customers', [
        'name' => 'Công ty Minh Phát', 'type' => 'company', 'source' => 'referral',
        'invoice_name' => 'CÔNG TY TNHH MINH PHÁT', 'invoice_address' => '45 Nguyễn Trãi, Hà Nội', 'invoice_email' => 'ketoan@minhphat.vn',
    ])->assertCreated()->assertJsonPath('data.type', 'company')->assertJsonPath('data.invoice.email', 'ketoan@minhphat.vn')->json('data.id');
    ($this->as)()->getJson('/api/v1/customers?type=company&source=referral')->assertJsonPath('data.0.id', $id);
    ($this->as)()->postJson('/api/v1/customers', ['name' => 'X', 'source' => 'shopee'])->assertUnprocessable()->assertJsonValidationErrors('source');

    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1]], ['customer' => ['name' => 'Chị Hoa', 'phone' => '0912000111']])->assertCreated();
    ($this->as)()->getJson('/api/v1/customers?q=0912000111')->assertJsonPath('data.0.source', defaultChannel($this->tenant)->type);
});

it('retires a group: no new customers join it, the ones in it stay', function () {
    $group = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Khách cũ', 'price_tier' => 'retail'])->json('data.id');
    $member = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Anh Ba', 'group_id' => $group])->assertCreated()->json('data.id');
    ($this->as)()->putJson("/api/v1/customer-groups/{$group}", ['name' => 'Khách cũ', 'price_tier' => 'retail', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    ($this->as)()->postJson('/api/v1/customers', ['name' => 'Chị Tư', 'group_id' => $group])->assertUnprocessable()->assertJsonValidationErrors('group_id');
    ($this->as)()->putJson("/api/v1/customers/{$member}", ['name' => 'Anh Ba Sáu', 'group_id' => $group])->assertOk();
});

it('lets staff without price rights give the group discount, but no more', function () {
    ($this->as)()->putJson('/api/v1/tenant/settings/pricing', ['staff_edit_price' => false])->assertOk();
    $group = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Sỉ', 'price_tier' => 'wholesale', 'discount_percent' => 5])->json('data.id');
    $customer = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Đại lý An', 'group_id' => $group])->json('data.id');
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    $order = fn (int $discount, ?string $buyer) => ($this->as)($staff)->postJson('/api/v1/sales-orders', [
        'channel_id' => defaultChannel($this->tenant)->ulid, 'customer_id' => $buyer, 'discount' => $discount,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 2, 'unit_price' => 90000]],
    ]);

    $order(9000, $customer)->assertCreated()->assertJsonPath('data.discount', '9000.00');
    $order(9001, $customer)->assertUnprocessable()->assertJsonValidationErrors('discount');
    $order(9000, null)->assertUnprocessable()->assertJsonValidationErrors('discount');
});

it('sorts by any column both ways and exports what the list shows', function () {
    foreach (['An' => 1, 'Bình' => 3, 'Cường' => 2] as $name => $qty) {
        $id = ($this->as)()->postJson('/api/v1/customers', ['name' => $name])->json('data.id');
        createSalesOrder($this->owner, $this->tenant, [[$this->variant, $qty, 100000]], ['customer_id' => $id, 'stage' => 'completed'])->assertCreated();
    }
    $names = fn (string $query) => collect(($this->as)()->getJson("/api/v1/customers?{$query}")->assertOk()->json('data'))->pluck('name')->all();
    expect($names('sort=spent'))->toBe(['Bình', 'Cường', 'An'])
        ->and($names('sort=spent&dir=asc'))->toBe(['An', 'Cường', 'Bình'])
        ->and($names('sort=name&dir=desc'))->toBe(['Cường', 'Bình', 'An']);

    $file = ($this->as)()->get('/api/v1/customers/export?format=csv&spent_min=200000&sort=name')->assertOk()->streamedContent();
    expect($file)->toContain('Bình')->toContain('Cường')->not->toContain(',An,');
    expect(AuditLog::query()->where('action', 'customer.exported')->latest('id')->first()->changes)->toMatchArray(['scope' => 'filtered']);
});
