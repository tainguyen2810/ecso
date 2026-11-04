<?php

use App\Modules\Access\Models\Role;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;

/** Return goods of an order through the real return flow, up to the given return status. */
function financeReturn($owner, $tenant, string $orderId, int $quantity, ?float $refund, string $until): string
{
    $api = actingInTenant($owner, $tenant);
    $itemId = $api->getJson("/api/v1/orders/{$orderId}")->json('data.items.0.id');
    $return = $api->postJson("/api/v1/orders/{$orderId}/returns", array_filter([
        'type' => 'return', 'refund_amount' => $refund, 'lines' => [['order_item_id' => $itemId, 'quantity' => $quantity]],
    ], fn ($v) => $v !== null))->assertCreated()->json('data.id');

    $steps = [OrderReturn::APPROVED, OrderReturn::RECEIVED, OrderReturn::INSPECTED];
    foreach (array_slice($steps, 0, array_search($until, $steps, true) + 1) as $status) {
        if ($status === OrderReturn::INSPECTED) {
            $lineId = $api->getJson("/api/v1/returns/{$return}")->json('data.lines.0.id');
            $api->postJson("/api/v1/returns/{$return}/inspect", [
                'warehouse_id' => defaultWarehouse($tenant)->ulid,
                'lines' => [['line_id' => $lineId, 'received_qty' => $quantity, 'sellable_qty' => $quantity, 'damaged_qty' => 0]],
            ])->assertOk();
        } else {
            $api->postJson("/api/v1/returns/{$return}/status", ['status' => $status])->assertOk();
        }
    }

    return $return;
}

it('reports days on the shop clock (Asia/Ho_Chi_Minh), not in UTC', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-TZ');
    stockIn($tenant, $variant, 5);
    $order = fakeOrder(fakeShop($tenant), [[$variant, 1]], 'fake_ready');
    // 1/11 00:30 in Vietnam is still 31/10 in UTC.
    Order::withoutGlobalScopes()->whereKey($order->id)->update(['placed_at' => '2026-10-31 17:30:00']);

    $api = actingInTenant($owner, $tenant);
    $nov = $api->getJson('/api/v1/finance/summary?from=2026-11-01&to=2026-11-01')->assertOk()->json('data');
    $oct = $api->getJson('/api/v1/finance/summary?from=2026-10-31&to=2026-10-31')->assertOk()->json('data');

    expect($nov['ordered']['orders'])->toBe(1)
        ->and($nov['daily'])->toEqual([['day' => '2026-11-01', 'orders' => 1, 'revenue' => (float) $order->total]])
        ->and($oct['ordered']['orders'])->toBe(0);

    $sales = $api->getJson('/api/v1/reports/sales?from=2026-11-01&to=2026-11-01&by=day')->assertOk()->json('data');
    expect(collect($sales['rows'])->firstWhere('label', '2026-11-01')['orders'])->toBe(1);
    expect($api->getJson('/api/v1/orders?from=2026-11-01&to=2026-11-01')->assertOk()->json('meta.total'))->toBe(1)
        ->and($api->getJson('/api/v1/orders?from=2026-10-31&to=2026-10-31')->json('meta.total'))->toBe(0);
});

it('nets a full return to zero gross profit: refund subtracted once, returned goods cost added back', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-RET');
    stockIn($tenant, $variant, 5); // unit cost 50,000
    $order = createSalesOrder($owner, $tenant, [[$variant, 2]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 200000]]])->json('data');
    $api = actingInTenant($owner, $tenant);
    $before = $api->getJson('/api/v1/finance/summary')->json('data');
    $goods = $before['completed']['goods'];

    $return = financeReturn($owner, $tenant, $order['id'], 2, $goods, OrderReturn::RECEIVED);
    // Approved and received but not yet inspected: the refund counts, no stock is back yet.
    $mid = $api->getJson('/api/v1/finance/summary')->json('data');
    expect($mid['refunds'])->toEqual($goods)->and($mid['cogs_returned'])->toEqual(0.0);

    $lineId = $api->getJson("/api/v1/returns/{$return}")->json('data.lines.0.id');
    $api->postJson("/api/v1/returns/{$return}/inspect", [
        'warehouse_id' => defaultWarehouse($tenant)->ulid,
        'lines' => [['line_id' => $lineId, 'received_qty' => 2, 'sellable_qty' => 2, 'damaged_qty' => 0]],
    ])->assertOk();
    $after = $api->getJson('/api/v1/finance/summary')->json('data');

    expect($before['estimated_gross_profit'])->toEqual(round($goods - 100000, 2))
        ->and($after['refunds'])->toEqual($goods)
        ->and($after['cogs'])->toEqual(100000.0)
        ->and($after['cogs_returned'])->toEqual(100000.0)
        ->and($after['estimated_gross_profit'])->toEqual(0.0);
});

it('ignores return requests that are not approved yet', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-REQ');
    stockIn($tenant, $variant, 5);
    $order = createSalesOrder($owner, $tenant, [[$variant, 1]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 100000]]])->json('data');
    $api = actingInTenant($owner, $tenant);
    $itemId = $api->getJson("/api/v1/orders/{$order['id']}")->json('data.items.0.id');
    $api->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'refund_amount' => 30000, 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertCreated();

    $data = $api->getJson('/api/v1/finance/summary')->json('data');
    $pl = collect($api->getJson('/api/v1/reports/profit-loss')->json('data.lines'))->keyBy('key');

    expect($data['refunds'])->toEqual(0.0)->and($pl['returns']['current'])->toEqual(0.0);
});

it('does not subtract the return of an order that is already out of revenue', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-OUT');
    stockIn($tenant, $variant, 5);
    $order = fakeOrder(fakeShop($tenant), [[$variant, 1]], 'fake_completed');
    financeReturn($owner, $tenant, $order->ulid, 1, 80000, OrderReturn::APPROVED);
    $api = actingInTenant($owner, $tenant);

    expect($api->getJson('/api/v1/finance/summary')->json('data.refunds'))->toEqual(80000.0);

    // The marketplace closes the order as returned: it leaves completed revenue and cost, and so does its return.
    Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => OrderStatus::RETURNED]);
    $returned = $api->getJson('/api/v1/finance/summary')->json('data');
    expect($returned['completed']['orders'])->toBe(0)->and($returned['refunds'])->toEqual(0.0)->and($returned['estimated_gross_profit'])->toEqual(0.0);

    // Profit and loss: a cancelled order is out of revenue, so its return is not subtracted either.
    Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => OrderStatus::CANCELLED]);
    $pl = collect($api->getJson('/api/v1/reports/profit-loss')->json('data.lines'))->keyBy('key');
    expect($pl['goods']['current'])->toEqual(0.0)->and($pl['returns']['current'])->toEqual(0.0)->and($pl['revenue']['current'])->toEqual(0.0);
});

it('keeps an order with an open return request in revenue until the return is approved', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-RR');
    stockIn($tenant, $variant, 5);
    $order = fakeOrder(fakeShop($tenant), [[$variant, 1]], 'fake_completed');
    Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => OrderStatus::RETURN_REQUESTED]);

    $data = actingInTenant($owner, $tenant)->getJson('/api/v1/finance/summary')->json('data');
    expect($data['completed']['orders'])->toBe(1)->and($data['cogs'])->toEqual(50000.0);
});

it('recognises marketplace revenue at completion and own-channel revenue at delivery, with shipping apart', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'SKU-POL');
    stockIn($tenant, $variant, 10); // unit cost 50,000
    $shop = fakeShop($tenant);
    $delivered = fakeOrder($shop, [[$variant, 1]], 'fake_delivered');
    $completed = fakeOrder($shop, [[$variant, 2]], 'fake_completed');
    $own = createSalesOrder($owner, $tenant, [[$variant, 1]], ['stage' => 'completed', 'shipping_fee' => 20000, 'payments' => [['method' => 'cash', 'amount' => 120000]]])->assertCreated()->json('data');
    $api = actingInTenant($owner, $tenant);

    $data = $api->getJson('/api/v1/finance/summary')->json('data');
    $goods = fn (Order $o) => (float) $o->subtotal - (float) $o->discount;
    expect($completed->fresh()->completed_at)->not->toBeNull()
        ->and($data['completed']['orders'])->toBe(2)
        ->and($data['completed']['goods'])->toEqual($goods($completed) + 100000)
        ->and($data['completed']['shipping'])->toEqual((float) $completed->shipping_fee + 20000)
        ->and($data['awaiting_completion']['orders'])->toBe(1)
        ->and($data['awaiting_completion']['goods'])->toEqual($goods($delivered))
        ->and($data['cogs'])->toEqual(150000.0)
        ->and($data['estimated_gross_profit'])->toEqual(round($goods($completed) + 100000 - 150000, 2));

    // Profit and loss uses the same basis; shipping charged to buyers is its own line, outside net revenue.
    $pl = collect($api->getJson('/api/v1/reports/profit-loss')->json('data.lines'))->keyBy('key');
    expect($pl['revenue']['current'])->toEqual($data['completed']['goods'])
        ->and($pl['cogs_out']['current'])->toEqual(150000)
        ->and($pl['shipping']['current'])->toEqual($data['completed']['shipping'])
        ->and($pl['profit']['current'])->toEqual($pl['gross_profit']['current'] - $pl['stock_loss']['current'] + $data['completed']['shipping']);
    expect($own['status'] ?? null)->not->toBeNull();
});

it('shows cost and profit only to members with finance.profit', function () {
    [$tenant, $owner] = tenantWithOwner();
    $viewer = addMember($tenant, Role::VIEWER);
    $accountant = addMember($tenant, Role::ACCOUNTANT);

    expect(actingInTenant($accountant, $tenant)->getJson('/api/v1/finance/summary')->assertOk()->json('data.can_view_profit'))->toBeTrue();
    actingInTenant($viewer, $tenant)->getJson('/api/v1/reports/profit-loss')->assertForbidden();
    $overview = actingInTenant($viewer, $tenant)->getJson('/api/v1/reports/overview')->assertOk()->json('data');
    expect($overview['sales']['gross_profit'])->toBeNull()->and($overview['sales']['cogs'])->toBeNull()->and($overview)->not->toHaveKey('finance');
});
