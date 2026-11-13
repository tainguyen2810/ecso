<?php

use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Models\LoyaltyEntry;
use Illuminate\Support\Facades\Artisan;

/** Tích điểm và hạng khách (C5): points earned, spent, returned and expired; tiers by 12-month spending. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->shirt = makeVariant($this->tenant, 'ATC-TRANG-M', ['ref_price' => 500000]);
    stockIn($this->tenant, $this->shirt, 100);
    $this->program = fn (array $tiers = [], array $extra = []) => $this->api->putJson('/api/v1/loyalty', [
        'enabled' => true, 'earn_per' => 100000, 'point_value' => 1000, 'max_redeem_percent' => 50, 'expire_months' => 12, 'with_promotions' => true,
        'tiers' => $tiers, ...$extra,
    ])->assertOk()->json('data');
    $this->sell = fn (int $qty, array $extra = []) => createSalesOrder($this->owner, $this->tenant, [[$this->shirt, $qty, 500000]], [
        'stage' => 'completed', 'customer' => ['name' => 'Trần Thị Mai', 'phone' => '0938123321'], ...$extra,
    ])->assertCreated()->json('data.id');
    $this->fetch = fn (string $id) => Order::withoutGlobalScopes()->where('ulid', $id)->sole();
    $this->customer = fn () => Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
});

it('earns points when an order completes, takes them back on refunds and cancels, and moves the buyer up a tier', function () {
    $program = ($this->program)([
        ['name' => 'Thành viên', 'min_spent' => 0, 'point_multiplier' => 1],
        ['name' => 'Vàng', 'min_spent' => 1500000, 'point_multiplier' => 1.5, 'discount_percent' => 5],
    ]);
    expect($program['tiers'])->toHaveCount(2)->and($program['tiers'][1]['customer_group']['name'])->toBe('Khách Vàng');

    // 1.000.000 paid with shipping 30.000 left out → 10 points; the buyer is now a member.
    $first = ($this->sell)(2, ['shipping_fee' => 30000]);
    $customer = ($this->customer)();
    expect($customer->loyalty_points)->toBe(10);
    $loyalty = $this->api->getJson("/api/v1/customers/{$customer->ulid}/loyalty")->assertOk()->json('data');
    expect($loyalty['tier']['name'])->toBe('Thành viên')->and($loyalty['next_tier']['name'])->toBe('Vàng')
        ->and(collect($loyalty['entries'])->firstWhere('kind', 'earn')['note'])->toContain('hoàn tất');

    // Another 1.000.000: spending reaches 2.000.000 → Vàng (and its group); points at the multiplier the buyer had.
    ($this->sell)(2, ['customer_id' => $customer->ulid, 'customer' => null]);
    $customer->refresh();
    expect($customer->loyalty_points)->toBe(20)->and($customer->loyalty_tier_id)->not->toBeNull()
        ->and(CustomerGroup::withoutGlobalScopes()->find($customer->customer_group_id)?->name)->toBe('Khách Vàng');
    $third = ($this->sell)(2, ['customer_id' => $customer->ulid, 'customer' => null]);
    expect($customer->refresh()->loyalty_points)->toBe(35); // 10 × 1,5

    // Half refunded: half the points go; cancelled: the rest.
    $order = ($this->fetch)($third);
    $order->forceFill(['refunded_amount' => '500000.00'])->save();
    expect($customer->refresh()->loyalty_points)->toBe(27); // 5 × 1,5 = 7 kept of 15
    $order->forceFill(['refunded_amount' => '1000000.00', 'status' => 'RETURNED'])->save();
    expect($customer->refresh()->loyalty_points)->toBe(20);
    expect((int) LoyaltyEntry::withoutGlobalScopes()->where('order_id', $order->id)->sum('points'))->toBe(0);
    expect(($this->fetch)($first)->customer_id)->toBe($customer->id);
});

it('spends points on an order within the limits and gives them back when the order is cancelled', function () {
    ($this->program)();
    ($this->sell)(10); // 50 points
    $customer = ($this->customer)();
    expect($customer->loyalty_points)->toBe(50);

    $preview = $this->api->postJson('/api/v1/promotions/preview', ['customer_id' => $customer->ulid, 'lines' => [['variant_id' => $this->shirt->ulid, 'quantity' => 1, 'unit_price' => 60000]]])
        ->assertOk()->json('data.loyalty');
    expect($preview)->toMatchArray(['enabled' => true, 'balance' => 50, 'max' => 30, 'point_value' => 1000]); // 50% of 60.000

    $base = ['customer_id' => $customer->ulid, 'customer' => null, 'stage' => 'pending'];
    createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1, 60000]], [...$base, 'points_used' => 31])->assertStatus(422)
        ->assertJsonPath('errors.points_used.0', 'Đơn này dùng tối đa 30 điểm.');
    createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1, 60000]], [...$base, 'points_used' => 28, 'discount' => 5000])->assertStatus(422); // 55.000 left: at most 27
    $id = createSalesOrder($this->owner, $this->tenant, [[$this->shirt, 1, 60000]], [...$base, 'points_used' => 25, 'discount' => 5000])->assertCreated()->json('data.id');
    $order = ($this->fetch)($id);
    expect($order->points_discount)->toBe('25000.00')->and($order->discount)->toBe('30000.00')->and($order->total)->toBe('30000.00')
        ->and($customer->refresh()->loyalty_points)->toBe(25);

    // An edit keeps the typed discount and the points apart.
    $this->api->putJson("/api/v1/sales-orders/{$id}", ['points_used' => 10])->assertOk();
    $order->refresh();
    expect($order->discount)->toBe('15000.00')->and($customer->refresh()->loyalty_points)->toBe(40);

    $this->api->postJson("/api/v1/orders/{$id}/actions/cancel", ['reason' => 'Khách đổi ý'])->assertOk();
    expect($customer->refresh()->loyalty_points)->toBe(50);
});

it('gives points by hand, expires old points and needs the right permission', function () {
    ($this->program)();
    ($this->sell)(4); // 20 points
    $customer = ($this->customer)();
    $this->api->postJson("/api/v1/customers/{$customer->ulid}/loyalty/adjust", ['points' => -30, 'note' => 'x'])->assertStatus(422);
    $this->api->postJson("/api/v1/customers/{$customer->ulid}/loyalty/adjust", ['points' => 15, 'note' => 'Bù đơn giao trễ'])->assertOk()->assertJsonPath('data.points', 35);

    // A year later the first 20 expire; 5 were spent since, so 15 of them go and the hand-given 15 stay.
    LoyaltyEntry::withoutGlobalScopes()->where('kind', LoyaltyEntry::EARN)->update(['expires_at' => now()->subDay()]);
    $this->api->postJson("/api/v1/customers/{$customer->ulid}/loyalty/adjust", ['points' => -5, 'note' => 'Đổi quà'])->assertOk();
    Artisan::call('ecso:loyalty:nightly', ['--tenant' => $this->tenant->id]);
    expect($customer->refresh()->loyalty_points)->toBe(15);
    Artisan::call('ecso:loyalty:nightly', ['--tenant' => $this->tenant->id]);
    expect($customer->refresh()->loyalty_points)->toBe(15);

    $staff = addMember($this->tenant, 'ORDER_STAFF');
    actingInTenant($staff, $this->tenant)->postJson("/api/v1/customers/{$customer->ulid}/loyalty/adjust", ['points' => 5, 'note' => 'x'])->assertForbidden();
    actingInTenant($staff, $this->tenant)->putJson('/api/v1/loyalty', ['enabled' => false])->assertForbidden();
    actingInTenant($staff, $this->tenant)->getJson('/api/v1/loyalty')->assertOk();
});

it('earns nothing while the program is off', function () {
    ($this->sell)(4);
    expect(($this->customer)()->loyalty_points)->toBe(0);
});
