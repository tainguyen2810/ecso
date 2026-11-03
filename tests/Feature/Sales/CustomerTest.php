<?php

use App\Modules\Access\Models\Role;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesChannel;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

function customerCsv(array $rows): UploadedFile
{
    $csv = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => str_contains((string) $c, ',') ? "\"{$c}\"" : $c, $r)), $rows));

    return UploadedFile::fake()->createWithContent('khach-hang.csv', "\xEF\xBB\xBF".$csv);
}

function orderFor($user, $tenant, string $customerId, int $price, int $paid = 0): TestResponse
{
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);

    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail()->ulid,
        'customer_id' => $customerId,
        'stage' => 'completed',
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 1, 'unit_price' => $price]],
        'payments' => $paid ? [['method' => 'cash', 'amount' => $paid]] : [],
    ])->assertCreated();
}

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
});

it('manages customer groups with their buying terms', function () {
    $vip = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Khách VIP', 'price_tier' => 'retail', 'discount_percent' => 5, 'payment_method' => 'bank_transfer'])
        ->assertCreated()->assertJsonPath('data.code', 'KHACHVIP')->assertJsonPath('data.discount_percent', '5.00')->json('data');
    ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Khách VIP', 'price_tier' => 'retail'])->assertUnprocessable()->assertJsonValidationErrors('name');
    ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Sỉ', 'code' => 'KHACHVIP', 'price_tier' => 'wholesale'])->assertUnprocessable()->assertJsonValidationErrors('code');
    ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Sỉ', 'price_tier' => 'sale'])->assertUnprocessable()->assertJsonValidationErrors('price_tier');

    $c = ($this->as)()->postJson('/api/v1/customers', ['name' => 'An', 'group_id' => $vip['id']])->assertCreated()->assertJsonPath('data.group.name', 'Khách VIP')->json('data');
    ($this->as)()->getJson('/api/v1/customer-groups')->assertJsonPath('data.0.customers_count', 1);
    ($this->as)()->getJson("/api/v1/customers?group={$vip['id']}")->assertJsonCount(1, 'data');
    ($this->as)()->getJson('/api/v1/customers?group=none')->assertJsonCount(0, 'data');

    ($this->as)()->deleteJson("/api/v1/customer-groups/{$vip['id']}")->assertUnprocessable();
    ($this->as)()->putJson("/api/v1/customers/{$c['id']}", ['name' => 'An', 'group_id' => null])->assertOk()->assertJsonPath('data.group', null);
    ($this->as)()->putJson("/api/v1/customer-groups/{$vip['id']}", ['name' => 'VIP', 'price_tier' => 'wholesale'])->assertOk()->assertJsonPath('data.code', 'KHACHVIP');
    ($this->as)()->deleteJson("/api/v1/customer-groups/{$vip['id']}")->assertNoContent();

    $viewer = addMember($this->tenant, Role::ACCOUNTANT);
    ($this->as)($viewer)->getJson('/api/v1/customer-groups')->assertOk();
    ($this->as)($viewer)->postJson('/api/v1/customer-groups', ['name' => 'X', 'price_tier' => 'retail'])->assertForbidden();
});

it('keeps the full profile: code, birthday, gender, tax code, staff in charge, tags and status', function () {
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    [, $stranger] = tenantWithOwner('Shop B');

    $c = ($this->as)()->postJson('/api/v1/customers', [
        'name' => 'Công ty Minh Phát', 'code' => 'MP01', 'birthday' => '1990-08-15', 'gender' => 'female', 'tax_code' => '0101234567',
        'assigned_user_id' => $staff->ulid, 'tags' => [' Sỉ ', 'sỉ', 'Zalo', ''],
    ])->assertCreated()->json('data');
    expect($c)->toMatchArray(['code' => 'MP01', 'birthday' => '1990-08-15', 'gender' => 'female', 'tax_code' => '0101234567', 'tags' => ['Sỉ', 'Zalo'], 'status' => 'active'])
        ->and($c['assignee']['name'])->toBe($staff->name);

    ($this->as)()->postJson('/api/v1/customers', ['name' => 'Trùng mã', 'code' => 'MP01'])->assertUnprocessable()->assertJsonValidationErrors('code');
    ($this->as)()->postJson('/api/v1/customers', ['name' => 'Ngoài', 'assigned_user_id' => $stranger->ulid])->assertUnprocessable()->assertJsonValidationErrors('assigned_user_id');
    ($this->as)()->postJson('/api/v1/customers', ['name' => 'Tương lai', 'birthday' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('birthday');

    // A typed code that looks automatic is skipped by the automatic numbering.
    ($this->as)()->postJson('/api/v1/customers', ['name' => 'Gõ tay', 'code' => 'KH-000001'])->assertCreated();
    ($this->as)()->postJson('/api/v1/customers', ['name' => 'Tự động'])->assertCreated()->assertJsonPath('data.code', 'KH-000002');

    ($this->as)()->getJson('/api/v1/customers/tags')->assertJsonPath('data', ['Sỉ', 'Zalo']);
    ($this->as)()->getJson('/api/v1/customers/staff')->assertJsonFragment(['id' => $staff->ulid]);
});

it('filters by status, birthday month, gender, tag, staff and purchases', function () {
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    $a = ($this->as)()->postJson('/api/v1/customers', ['name' => 'A', 'birthday' => '1991-03-02', 'gender' => 'male', 'tags' => ['VIP'], 'assigned_user_id' => $staff->ulid])->json('data.id');
    $b = ($this->as)()->postJson('/api/v1/customers', ['name' => 'B', 'birthday' => '1992-07-09', 'gender' => 'female', 'status' => 'inactive'])->json('data.id');
    orderFor($this->owner, $this->tenant, $a, 300000, 100000);

    $ids = fn (string $query) => collect(($this->as)()->getJson("/api/v1/customers?{$query}")->assertOk()->json('data'))->pluck('id')->all();
    expect($ids('status=active'))->toBe([$a])
        ->and($ids('status=inactive'))->toBe([$b])
        ->and($ids('birth_month=7'))->toBe([$b])
        ->and($ids('gender=male'))->toBe([$a])
        ->and($ids('tag=VIP'))->toBe([$a])
        ->and($ids("assigned={$staff->ulid}"))->toBe([$a])
        ->and($ids('assigned=none'))->toBe([$b])
        ->and($ids('spent_min=200000'))->toBe([$a])
        ->and($ids('spent_max=200000'))->toBe([$b])
        ->and($ids('orders_min=1&debt=1'))->toBe([$a])
        ->and($ids('last_order_from='.now()->toDateString()))->toBe([$a])
        ->and($ids('sort=orders'))->toBe([$a, $b]);
});

it('updates many customers at once and keeps customers that have orders when deleting', function () {
    $group = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Sỉ', 'price_tier' => 'wholesale'])->json('data.id');
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    $a = ($this->as)()->postJson('/api/v1/customers', ['name' => 'A', 'tags' => ['Cũ']])->json('data.id');
    $b = ($this->as)()->postJson('/api/v1/customers', ['name' => 'B'])->json('data.id');
    orderFor($this->owner, $this->tenant, $a, 100000, 100000);
    $bulk = fn (string $action, ?string $value = null) => ($this->as)()->postJson('/api/v1/customers/bulk', ['ids' => [$a, $b], 'action' => $action, 'value' => $value]);

    $bulk('set_group', $group)->assertOk()->assertJsonPath('updated', 2);
    $bulk('add_tag', 'Tết')->assertJsonPath('updated', 2);
    $bulk('remove_tag', 'cũ')->assertJsonPath('updated', 1);
    $bulk('assign', $staff->ulid)->assertJsonPath('updated', 2);
    $bulk('set_status', 'inactive')->assertJsonPath('updated', 2);
    $bulk('set_status', 'closed')->assertUnprocessable();
    $bulk('add_tag', ' ')->assertUnprocessable();

    $row = ($this->as)()->getJson("/api/v1/customers/{$a}")->json('data');
    expect($row['group']['id'])->toBe($group)->and($row['tags'])->toBe(['Tết'])->and($row['status'])->toBe('inactive')->and($row['assignee']['id'])->toBe($staff->ulid);

    $bulk('delete')->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('skipped.0.id', $a);
    expect(Customer::withoutGlobalScopes()->pluck('ulid')->all())->toBe([$a]);

    ($this->as)(addMember($this->tenant, Role::ACCOUNTANT))->postJson('/api/v1/customers/bulk', ['ids' => [$a], 'action' => 'set_status', 'value' => 'active'])->assertForbidden();
});

it('keeps a debt book with the running balance', function () {
    $c = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Nợ'])->json('data.id');
    orderFor($this->owner, $this->tenant, $c, 300000, 100000);
    $this->travel(1)->minutes();
    orderFor($this->owner, $this->tenant, $c, 200000);

    $ledger = ($this->as)()->getJson("/api/v1/customers/{$c}/ledger")->assertOk()->json('data');
    expect(array_column($ledger, 'type'))->toBe(['order', 'payment', 'order'])
        ->and(array_column($ledger, 'balance'))->toBe(['400000.00', '200000.00', '300000.00'])
        ->and($ledger[1]['change'])->toBe('-100000.00');
    ($this->as)()->getJson("/api/v1/customers/{$c}")->assertJsonPath('data.debt', '400000.00');
});

it('keeps extra delivery addresses and a note timeline', function () {
    $c = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Giao nhiều nơi'])->json('data.id');
    $addr = ($this->as)()->postJson("/api/v1/customers/{$c}/addresses", ['label' => 'Công ty', 'phone' => '0912 000 111', 'address' => '1 Lê Lợi'])
        ->assertCreated()->assertJsonPath('data.phone', '0912000111')->json('data.id');
    ($this->as)()->putJson("/api/v1/customers/{$c}/addresses/{$addr}", ['address' => '2 Lê Lợi'])->assertOk()->assertJsonPath('data.address', '2 Lê Lợi');
    ($this->as)()->postJson("/api/v1/customers/{$c}/addresses", ['address' => 'x', 'phone' => '12'])->assertUnprocessable();

    $accountant = addMember($this->tenant, Role::ACCOUNTANT);
    ($this->as)($accountant)->postJson("/api/v1/customers/{$c}/addresses", ['address' => '3 Lê Lợi'])->assertForbidden();
    $note = ($this->as)($accountant)->postJson("/api/v1/customers/{$c}/notes", ['body' => 'Khách hẹn thanh toán thứ Sáu'])->assertCreated()->json('data');
    expect($note['author'])->toBe($accountant->name)->and($note['mine'])->toBeTrue();

    $show = ($this->as)()->getJson("/api/v1/customers/{$c}")->json('data');
    expect($show['addresses'])->toHaveCount(1)->and($show['notes'][0]['body'])->toBe('Khách hẹn thanh toán thứ Sáu');

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->deleteJson("/api/v1/customers/{$c}/addresses/{$addr}")->assertNotFound();
    ($this->as)()->deleteJson("/api/v1/customers/{$c}/notes/{$note['id']}")->assertNoContent();
    ($this->as)()->deleteJson("/api/v1/customers/{$c}/addresses/{$addr}")->assertNoContent();
});

it('imports customers all-or-nothing, matching by code then phone, and exports them back', function () {
    ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Bán sỉ', 'code' => 'SI', 'price_tier' => 'wholesale']);
    $byCode = ($this->as)()->postJson('/api/v1/customers', ['name' => 'Cũ theo mã', 'code' => 'OLD1', 'email' => 'old@example.com'])->json('data');
    ($this->as)()->postJson('/api/v1/customers', ['name' => 'Cũ theo SĐT', 'phone' => '0911111111']);
    $header = ['Mã khách hàng', 'Tên khách hàng', 'Số điện thoại', 'Nhóm khách hàng', 'Ngày sinh', 'Giới tính', 'Tags', 'Trạng thái'];

    $bad = customerCsv([$header,
        ['', 'Mới', '0922222222', 'Không có', '31/02/1990', 'Nam', '', ''],
        ['', 'Trùng', '0922222222', '', '', 'X', '', 'Đóng'],
    ]);
    $errors = ($this->as)()->postJson('/api/v1/customers/import', ['file' => $bad])->assertUnprocessable()->json('errors');
    expect(array_column($errors, 'row'))->toBe([2, 2, 3, 3, 3]);
    expect(Customer::withoutGlobalScopes()->count())->toBe(2);

    $good = [$header,
        ['OLD1', '', '', 'SI', '', '', 'Sỉ, Tết', ''],
        ['', 'Đổi tên theo SĐT', '0911 111 111', '', '05/03/1985', 'Nữ', '', 'Ngừng giao dịch'],
        ['', 'Khách mới', '0933333333', 'bán sỉ', '', '', '', ''],
    ];
    ($this->as)()->postJson('/api/v1/customers/import', ['file' => customerCsv($good), 'dry_run' => true])->assertOk()->assertJsonPath('created', 1)->assertJsonPath('updated', 2);
    expect(Customer::withoutGlobalScopes()->count())->toBe(2);
    ($this->as)()->postJson('/api/v1/customers/import', ['file' => customerCsv($good)])->assertOk()->assertJsonPath('created', 1);

    $old = ($this->as)()->getJson("/api/v1/customers/{$byCode['id']}")->json('data');
    expect($old)->toMatchArray(['name' => 'Cũ theo mã', 'email' => 'old@example.com', 'tags' => ['Sỉ', 'Tết']])->and($old['group']['code'])->toBe('SI');
    $phone = Customer::withoutGlobalScopes()->where('phone_last4', '1111')->sole();
    expect($phone->only(['name', 'gender', 'status']))->toBe(['name' => 'Đổi tên theo SĐT', 'gender' => 'female', 'status' => 'inactive'])->and($phone->birthday->toDateString())->toBe('1985-03-05');

    $csv = ($this->as)()->get('/api/v1/customers/export?format=csv')->assertOk()->streamedContent();
    expect($csv)->toContain('"Mã khách hàng","Tên khách hàng"')->toContain('OLD1')->toContain('0933333333')->toContain('05/03/1985');
    $only = ($this->as)()->get("/api/v1/customers/export?format=csv&ids={$byCode['id']}")->streamedContent();
    expect($only)->toContain('OLD1')->not->toContain('0933333333');
    ($this->as)()->get('/api/v1/customers/import-template')->assertOk();

    ($this->as)(addMember($this->tenant, Role::ACCOUNTANT))->get('/api/v1/customers/export')->assertForbidden();
    ($this->as)()->postJson('/api/v1/customers/import', ['file' => customerCsv([['Họ tên'], ['A']])])->assertUnprocessable()->assertJsonPath('errors.0.row', 1);
});

it('isolates groups and customers between businesses', function () {
    $group = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'VIP', 'price_tier' => 'retail'])->json('data.id');
    [$other, $otherOwner] = tenantWithOwner('Shop B');

    actingInTenant($otherOwner, $other)->getJson('/api/v1/customer-groups')->assertJsonCount(0, 'data');
    actingInTenant($otherOwner, $other)->postJson('/api/v1/customers', ['name' => 'X', 'group_id' => $group])->assertUnprocessable()->assertJsonValidationErrors('group_id');
    actingInTenant($otherOwner, $other)->postJson('/api/v1/customer-groups', ['name' => 'VIP', 'price_tier' => 'retail'])->assertCreated();
});
