<?php

use App\Modules\Access\Models\Role;

/** The figures behind the Khách hàng pages (mẫu 28/11): list summary, channels, group revenue, month stats, "Không phải trùng". */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
    $this->variant = makeVariant($this->tenant);
    stockIn($this->tenant, $this->variant, 50);
    $this->card = fn (array $data) => ($this->as)()->postJson('/api/v1/customers', $data)->assertCreated()->json('data.id');
});

it('sums up the customer list: totals, returning buyers, debt and quick groups', function () {
    $lan = ($this->card)(['name' => 'Lan', 'phone' => '0901000001']);
    $minh = ($this->card)(['name' => 'Minh', 'phone' => '0901000002']);
    ($this->card)(['name' => 'Hoa', 'status' => 'inactive']);
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 100000]], ['customer_id' => $lan, 'stage' => 'completed'])->assertCreated();
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 50000]], ['customer_id' => $lan, 'stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 50000]]])->assertCreated();
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 1, 70000]], ['customer_id' => $minh, 'stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 70000]]])->assertCreated();

    $s = ($this->as)()->getJson('/api/v1/customers/summary')->assertOk()->json('data');
    expect($s)->toMatchArray(['total' => 3, 'active' => 2, 'inactive' => 1, 'new_this_month' => 3])
        ->and($s['returning'])->toBe(['count' => 1, 'percent' => 50])
        ->and($s['debt'])->toBe(['amount' => '100000.00', 'count' => 1])
        ->and($s['segments'])->toHaveKeys(['follow_up', 'birthday', 'lapsed', 'frequent', 'high_value']);

    $rows = collect(($this->as)()->getJson('/api/v1/customers')->assertOk()->json('data'))->keyBy('id');
    expect($rows[$lan]['channels'])->toHaveCount(1)
        ->and($rows[$lan]['channels'][0])->toHaveKeys(['kind', 'name'])
        ->and($rows[$lan])->toHaveKey('tier');
    $kind = $rows[$lan]['channels'][0]['kind'];
    expect(collect(($this->as)()->getJson("/api/v1/customers?channel={$kind}")->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$lan, $minh])->sort()->values()->all());
    expect(($this->as)()->getJson('/api/v1/customers?channel=shopee')->json('data'))->toBe([]);
});

it('gives each group its 12-month revenue only when asked', function () {
    $group = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Khách sỉ', 'price_tier' => 'wholesale'])->assertCreated()->json('data.id');
    $lan = ($this->card)(['name' => 'Lan', 'group_id' => $group]);
    ($this->card)(['name' => 'Minh']);
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2, 100000]], ['customer_id' => $lan, 'stage' => 'completed'])->assertCreated();

    expect(($this->as)()->getJson('/api/v1/customer-groups')->assertOk()->json('data.0'))->not->toHaveKey('revenue_12m');
    $res = ($this->as)()->getJson('/api/v1/customer-groups?stats=1')->assertOk();
    expect($res->json('data.0.revenue_12m'))->toBe('200000.00')
        ->and($res->json('meta'))->toBe(['customers' => 2, 'revenue_12m' => '200000.00']);
});

it('adds this month to the promotions list and the loyalty program', function () {
    expect(($this->as)()->getJson('/api/v1/promotions')->assertOk()->json('meta'))
        ->toMatchArray(['amount' => '0.00', 'orders' => 0, 'code_uses' => 0, 'top' => []]);
    expect(($this->as)()->getJson('/api/v1/loyalty')->assertOk()->json('meta'))
        ->toMatchArray(['stats' => ['held' => 0, 'holders' => 0, 'earned' => 0, 'earned_before' => 0, 'earn_orders' => 0, 'spent' => 0, 'spend_orders' => 0, 'expiring' => 0, 'expiring_customers' => 0], 'moves' => []]);
});

it('hides a group marked "Không phải trùng" until another card joins it', function () {
    ($this->card)(['name' => 'Nguyễn Văn Nam', 'phone' => '0901000001']);
    ($this->card)(['name' => 'Nguyễn Văn Nam', 'phone' => '0901000002']);
    $groups = ($this->as)()->getJson('/api/v1/customers/duplicates')->assertOk()->json('data');
    expect($groups)->toHaveCount(1)->and($groups[0]['key'])->toHaveLength(40);

    ($this->as)(addMember($this->tenant, Role::ORDER_STAFF))->postJson('/api/v1/customers/duplicates/dismiss', ['key' => $groups[0]['key']])->assertForbidden();
    ($this->as)()->postJson('/api/v1/customers/duplicates/dismiss', ['key' => str_repeat('a', 40)])->assertUnprocessable();
    ($this->as)()->postJson('/api/v1/customers/duplicates/dismiss', ['key' => $groups[0]['key']])->assertNoContent();
    expect(($this->as)()->getJson('/api/v1/customers/duplicates')->json('data'))->toBe([]);

    // Another shop's dismissals do not touch this one.
    [$other, $otherOwner] = tenantWithOwner();
    actingInTenant($otherOwner, $other)->postJson('/api/v1/customers', ['name' => 'Nguyễn Văn Nam'])->assertCreated();
    actingInTenant($otherOwner, $other)->postJson('/api/v1/customers', ['name' => 'Nguyễn Văn Nam'])->assertCreated();
    expect(actingInTenant($otherOwner, $other)->getJson('/api/v1/customers/duplicates')->json('data'))->toHaveCount(1);

    ($this->card)(['name' => 'Nguyễn Văn Nam', 'phone' => '0901000003']);
    expect(($this->as)()->getJson('/api/v1/customers/duplicates')->json('data.0.customers'))->toHaveCount(3);
});
