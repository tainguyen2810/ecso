<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Console\SubscriptionDailyCommand;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerSubscription;
use App\Modules\Sales\Models\CustomerSubscriptionEvent;
use App\Modules\Sales\Subscriptions\Reminder;
use App\Modules\Sales\Subscriptions\RenewalService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;

/** Bán thuê bao: a service sold for a period, renewed from the end of the last one, reminded before it ends. */
function hostingPlans($owner, $tenant, array $extra = []): array
{
    $id = actingInTenant($owner, $tenant)->postJson('/api/v1/products', [
        'name' => 'Hosting doanh nghiệp 10GB',
        'type' => 'subscription',
        'subscription' => ['grace_days' => 3, 'auto_order_days' => 7, 'remind' => true],
        'variants' => [
            ['sku' => 'HOST-1T', 'name' => '1 tháng', 'period_months' => 1, 'ref_price' => 250000],
            ['sku' => 'HOST-12T', 'name' => '12 tháng', 'period_months' => 12, 'ref_price' => 2400000],
        ],
        ...$extra,
    ])->assertCreated()->json('data.id');

    return TenantContext::run($tenant, fn () => ProductVariant::query()->whereHas('product', fn ($q) => $q->where('ulid', $id))->orderBy('period_months')->get()->all());
}

function subCustomer($owner, $tenant): Customer
{
    $id = actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['name' => 'Công ty An Phát', 'phone' => '0908123456'])->assertCreated()->json('data.id');

    return Customer::withoutGlobalScopes()->where('ulid', $id)->sole();
}

function sellPlan($owner, $tenant, ProductVariant $plan, ?Customer $customer, array $line = [], array $extra = [])
{
    return actingInTenant($owner, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => defaultChannel($tenant)->ulid,
        'customer_id' => $customer?->ulid,
        'stage' => 'completed',
        'lines' => [['variant_id' => $plan->ulid, 'quantity' => 1, ...$line]],
        ...$extra,
    ]);
}

afterEach(fn () => Carbon::setTestNow());

it('creates a subscription product whose plans have a cycle and no stock', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$month, $year] = hostingPlans($owner, $tenant);
    expect($month->period_months)->toBe(1)->and($year->period_months)->toBe(12);

    $show = actingInTenant($owner, $tenant)->getJson('/api/v1/products/'.TenantContext::run($tenant, fn () => $year->product->ulid))->assertOk();
    expect($show->json('data.type'))->toBe('subscription')->and($show->json('data.subscription.auto_order_days'))->toBe(7);

    // A cycle is required, two plans cannot sell the same cycle, and there is no opening stock.
    actingInTenant($owner, $tenant)->postJson('/api/v1/products', ['name' => 'Bảo trì', 'type' => 'subscription', 'variants' => [['sku' => 'BT-1'], ['sku' => 'BT-2', 'period_months' => 6], ['sku' => 'BT-3', 'period_months' => 6]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['variants.0.period_months', 'variants.2.period_months']);
    actingInTenant($owner, $tenant)->postJson('/api/v1/products', ['name' => 'Lắp đặt', 'type' => 'service', 'opening_warehouse_id' => defaultWarehouse($tenant)->ulid, 'variants' => [['sku' => 'LD-1', 'opening_qty' => 3]]])
        ->assertUnprocessable()->assertJsonValidationErrors('variants.0.opening_qty');
});

it('sells a period without touching stock, needs a customer, and renews from the old end date', function () {
    Carbon::setTestNow('2026-11-26 03:00:00');
    [$tenant, $owner] = tenantWithOwner();
    [$month, $year] = hostingPlans($owner, $tenant);
    $customer = subCustomer($owner, $tenant);

    sellPlan($owner, $tenant, $year, null)->assertUnprocessable()->assertJsonValidationErrors('customer_id');

    $id = sellPlan($owner, $tenant, $year, $customer, ['label' => 'anphat.vn', 'start' => '2025-12-04'])->assertCreated()->json('data.id');
    $order = Order::withoutGlobalScopes()->where('ulid', $id)->sole();
    expect($order->status)->toBe(OrderStatus::COMPLETED)
        ->and(StockReservation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
    $item = $order->items()->withoutGlobalScopes()->sole();
    expect($item->product_variant_id)->toBeNull()->and($item->service_variant_id)->toBe($year->id)->and($item->seller_sku)->toBe('HOST-12T');

    $sub = CustomerSubscription::withoutGlobalScopes()->sole();
    expect($sub->label)->toBe('anphat.vn')->and($sub->starts_on->toDateString())->toBe('2025-12-04')->and($sub->ends_on->toDateString())->toBe('2026-12-03');

    // Renewing early continues the day after the old end: no day lost.
    $res = actingInTenant($owner, $tenant)->postJson("/api/v1/subscriptions/{$sub->ulid}/renew", ['variant_id' => $year->ulid, 'payments' => [['method' => 'bank_transfer', 'amount' => 2400000]]])->assertCreated();
    expect((float) $res->json('data.paid_amount'))->toBe(2400000.0);
    $sub->refresh();
    expect($sub->ends_on->toDateString())->toBe('2027-12-03')->and($sub->starts_on->toDateString())->toBe('2025-12-04');

    $detail = actingInTenant($owner, $tenant)->getJson("/api/v1/subscriptions/{$sub->ulid}")->assertOk();
    expect($detail->json('data.periods'))->toHaveCount(2)
        ->and($detail->json('data.periods.0.starts_on'))->toBe('2026-12-04')
        ->and($detail->json('data.paid_total'))->toBe('4800000.00')
        ->and($detail->json('data.plans'))->toHaveCount(2);

    // The same customer buying the same service again (no subscription named) renews it too.
    sellPlan($owner, $tenant, $month, $customer)->assertCreated();
    expect(CustomerSubscription::withoutGlobalScopes()->count())->toBe(1)
        ->and($sub->refresh()->ends_on->toDateString())->toBe('2028-01-03');
});

it('counts a period only once the order is confirmed and drops it when the order is cancelled', function () {
    Carbon::setTestNow('2026-11-26 03:00:00');
    [$tenant, $owner] = tenantWithOwner();
    [, $year] = hostingPlans($owner, $tenant);
    $customer = subCustomer($owner, $tenant);

    $id = sellPlan($owner, $tenant, $year, $customer, [], ['stage' => 'pending'])->assertCreated()->json('data.id');
    $sub = CustomerSubscription::withoutGlobalScopes()->sole();
    expect($sub->ends_on)->toBeNull();
    $list = actingInTenant($owner, $tenant)->getJson('/api/v1/subscriptions?tab=pending')->assertOk();
    expect($list->json('data.0.pending_order.id'))->toBe($id)->and($list->json('data.0.state'))->toBe('pending');

    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/confirm")->assertOk();
    expect($sub->refresh()->ends_on->toDateString())->toBe('2027-11-25');

    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$id}/actions/cancel", ['reason' => 'Khách đổi ý'])->assertOk();
    expect($sub->refresh()->ends_on)->toBeNull();
    expect(CustomerSubscriptionEvent::withoutGlobalScopes()->where('kind', 'period_void')->count())->toBe(1);

    // Editing a pending order keeps its line a subscription line.
    $id2 = sellPlan($owner, $tenant, $year, $customer, ['start' => '2026-12-01'], ['stage' => 'pending'])->assertCreated()->json('data.id');
    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-orders/{$id2}", ['note' => 'Sửa ghi chú'])->assertOk();
    $show = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id2}")->assertOk();
    expect($show->json('data.items.0.service_variant.sku'))->toBe('HOST-12T')
        ->and($show->json('data.items.0.subscription.start'))->toBe('2026-12-01')
        ->and($show->json('data.items.0.subscription.period.status'))->toBe('pending');
});

it('lists by tab with figures, pauses and cancels', function () {
    Carbon::setTestNow('2026-11-26 03:00:00');
    [$tenant, $owner] = tenantWithOwner();
    [$month, $year] = hostingPlans($owner, $tenant);
    $customer = subCustomer($owner, $tenant);
    sellPlan($owner, $tenant, $year, $customer, ['start' => '2025-12-04', 'label' => 'a.vn']);
    sellPlan($owner, $tenant, $month, $customer, ['start' => '2026-10-20', 'label' => 'b.vn']);

    $res = actingInTenant($owner, $tenant)->getJson('/api/v1/subscriptions')->assertOk();
    expect($res->json('meta.counts.all'))->toBe(2)
        ->and($res->json('meta.counts.expiring'))->toBe(1)
        ->and($res->json('meta.counts.overdue'))->toBe(1)
        ->and($res->json('meta.kpi.monthly'))->toBe('200000.00')
        // Side cards: the one ending within 30 days, and the running period's monthly worth per service.
        ->and($res->json('meta.kpi.calendar.0.ends_on'))->toBe('2026-12-03')
        ->and($res->json('meta.kpi.by_product.0.subscriptions'))->toBe(1)
        ->and($res->json('meta.kpi.by_product.0.monthly'))->toBe('200000.00');
    $overdue = collect($res->json('data'))->firstWhere('label', 'b.vn');
    expect($overdue['days_left'])->toBe(-7)->and($overdue['state'])->toBe('expired');

    $id = collect($res->json('data'))->firstWhere('label', 'a.vn')['id'];
    actingInTenant($owner, $tenant)->postJson("/api/v1/subscriptions/{$id}/pause")->assertOk()->assertJsonPath('data.state', 'paused');
    actingInTenant($owner, $tenant)->postJson("/api/v1/subscriptions/{$id}/cancel", ['reason' => 'Chuyển nhà cung cấp'])->assertOk()->assertJsonPath('data.state', 'cancelled');
    actingInTenant($owner, $tenant)->postJson("/api/v1/subscriptions/{$id}/renew", ['variant_id' => $year->ulid])->assertUnprocessable();
});

it('makes a renewal order a week before the end, reminds once per day, drops it after the grace days', function () {
    Carbon::setTestNow('2026-11-26 03:00:00');
    [$tenant, $owner] = tenantWithOwner();
    [, $year] = hostingPlans($owner, $tenant);
    $customer = subCustomer($owner, $tenant);
    sellPlan($owner, $tenant, $year, $customer, ['start' => '2025-12-04']);
    $sub = CustomerSubscription::withoutGlobalScopes()->sole();

    $run = fn () => TenantContext::run($tenant, fn () => app(SubscriptionDailyCommand::class)->runTenant(app(RenewalService::class), app(Reminder::class)));
    expect($run())->toBe(['orders' => 1, 'dropped' => 0, 'reminded' => 1]);
    expect($run())->toBe(['orders' => 0, 'dropped' => 0, 'reminded' => 0]);
    $renewal = Order::withoutGlobalScopes()->whereKey($sub->refresh()->renewal_order_id)->sole();
    expect($renewal->status)->toBe(OrderStatus::PENDING_CONFIRMATION)->and((float) $renewal->total)->toBe(2400000.0);

    // Four days after the end (3 days grace) the customer has not renewed: the order is cancelled.
    Carbon::setTestNow('2026-12-07 03:00:00');
    expect($run()['dropped'])->toBe(1);
    expect($renewal->refresh()->status)->toBe(OrderStatus::CANCELLED)->and($sub->refresh()->renewal_order_id)->toBeNull();
    expect(CustomerSubscriptionEvent::withoutGlobalScopes()->where('kind', 'reminded')->count())->toBe(1);
});

it('counts two of a plan as two cycles', function () {
    Carbon::setTestNow('2026-11-26 03:00:00');
    [$tenant, $owner] = tenantWithOwner();
    [, $year] = hostingPlans($owner, $tenant);
    $customer = subCustomer($owner, $tenant);

    sellPlan($owner, $tenant, $year, $customer, ['quantity' => 2, 'start' => '2026-12-01'])->assertCreated();
    expect(CustomerSubscription::withoutGlobalScopes()->sole()->ends_on->toDateString())->toBe('2028-11-30');
});
