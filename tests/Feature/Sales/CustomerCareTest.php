<?php

use App\Modules\Access\Models\Role;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
    $this->variant = makeVariant($this->tenant);
    stockIn($this->tenant, $this->variant, 100);
    $this->card = fn (string $name, array $extra = []) => ($this->as)()->postJson('/api/v1/customers', ['name' => $name, ...$extra])->assertCreated()->json('data.id');
    $this->buy = fn (string $customer, int $price, ?string $at = null) => tap(
        createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, $price]], ['customer_id' => $customer, 'stage' => 'completed'])->assertCreated()->json('data.id'),
        fn ($id) => $at && DB::table('orders')->where('ulid', $id)->update(['placed_at' => $at]),
    );
});

it('logs contacts by kind and plans follow-ups, listed until done', function () {
    $c = ($this->card)('Chị Lan');
    ($this->as)()->postJson("/api/v1/customers/{$c}/notes", ['body' => 'Gọi hỏi đơn sỉ', 'kind' => 'call'])->assertCreated()->assertJsonPath('data.kind', 'call');
    ($this->as)()->postJson("/api/v1/customers/{$c}/notes", ['body' => 'x', 'kind' => 'fax'])->assertUnprocessable();

    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    $task = ($this->as)($staff)->postJson("/api/v1/customers/{$c}/tasks", ['title' => 'Gửi báo giá sỉ', 'due_at' => now()->subHour()->toDateTimeString()])
        ->assertCreated()->assertJsonPath('data.late', true)->json('data.id');
    ($this->as)($staff)->getJson('/api/v1/customer-tasks?mine=1')->assertJsonPath('data.0.customer.id', $c);
    ($this->as)()->getJson('/api/v1/customer-tasks?mine=1')->assertJsonCount(0, 'data');
    ($this->as)()->getJson('/api/v1/customers?segment=follow_up')->assertJsonPath('data.0.id', $c);

    ($this->as)($staff)->postJson("/api/v1/customers/{$c}/tasks/{$task}/done")->assertOk()->assertJsonPath('data.late', false);
    ($this->as)()->getJson('/api/v1/customer-tasks')->assertJsonCount(0, 'data');
    ($this->as)()->getJson('/api/v1/customers?segment=follow_up')->assertJsonCount(0, 'data');
    expect(collect(($this->as)()->getJson("/api/v1/customers/{$c}/activity")->json('data'))->pluck('action'))->toContain('customer.task_done');

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->postJson("/api/v1/customers/{$c}/tasks", ['title' => 'x', 'due_at' => now()->toDateTimeString()])->assertNotFound();
});

it('segments customers from their real orders', function () {
    $lapsed = ($this->card)('Đã lâu');
    ($this->buy)($lapsed, 100000, now()->subDays(120)->toDateTimeString());
    $frequent = ($this->card)('Hay mua');
    foreach (range(1, 3) as $i) {
        ($this->buy)($frequent, 50000, now()->subDays($i * 10)->toDateTimeString());
    }
    $big = ($this->card)('Mua lớn');
    ($this->buy)($big, 900000);
    $names = fn (string $segment) => collect(($this->as)()->getJson("/api/v1/customers?segment={$segment}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

    expect($names('lapsed'))->toBe(['Đã lâu'])
        ->and($names('frequent'))->toBe(['Hay mua'])
        ->and($names('high_value'))->toBe(['Mua lớn'])
        ->and($names('new'))->toBe(['Hay mua', 'Mua lớn', 'Đã lâu']);

    $summary = ($this->as)()->getJson("/api/v1/customers/{$frequent}")->json('data.summary');
    expect($summary['top_products'][0])->toMatchArray(['quantity' => 3, 'amount' => '150000.00', 'orders' => 3]);
});

it('puts customers with no group into the group whose threshold they reached, never moving others', function () {
    $silver = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Bạc', 'price_tier' => 'retail', 'auto_min_spent' => 200000])->json('data.id');
    $gold = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Vàng', 'price_tier' => 'retail', 'auto_min_spent' => 500000, 'auto_min_orders' => 2])->json('data.id');
    $manual = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Tự chọn', 'price_tier' => 'retail'])->json('data.id');

    $a = ($this->card)('A');
    ($this->buy)($a, 300000);
    ($this->buy)($a, 300000);
    $b = ($this->card)('B');
    ($this->buy)($b, 300000);
    $c = ($this->card)('C', ['group_id' => $manual]);
    ($this->buy)($c, 900000);
    $d = ($this->card)('D');
    ($this->buy)($d, 100000);

    $this->artisan('ecso:customers:auto-group')->assertSuccessful();
    $group = fn (string $id) => ($this->as)()->getJson("/api/v1/customers/{$id}")->json('data.group.id');
    expect($group($a))->toBe($gold)->and($group($b))->toBe($silver)->and($group($c))->toBe($manual)->and($group($d))->toBeNull();
    expect(collect(($this->as)()->getJson("/api/v1/customers/{$a}/activity")->json('data'))->pluck('action'))->toContain('customer.auto_grouped');
});
