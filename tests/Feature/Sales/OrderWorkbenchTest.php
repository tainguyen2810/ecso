<?php

use App\Modules\Access\Models\Role;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\OrderReturn;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

// Order list helpers modelled on everyday shop work: the "to handle" strip, quick payment / cancel in bulk,
// export, buyer summary on the order and the "not received yet" returns tab.

it('sums the queues of orders that wait for someone', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 20);

    createSalesOrder($owner, $tenant, [[$variant, 1, 100000]])->assertCreated();
    createSalesOrder($owner, $tenant, [[$variant, 2, 100000]], ['stage' => 'confirmed', 'payments' => [['method' => 'cash', 'amount' => 50000]]])->assertCreated();
    createSalesOrder($owner, $tenant, [[$variant, 1, 300000]], ['stage' => 'completed', 'customer' => ['name' => 'A', 'phone' => '0900000002']])->assertCreated();
    $cancelled = createSalesOrder($owner, $tenant, [[$variant, 1, 70000]])->json('data.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$cancelled}/actions/cancel")->assertOk();
    fakeOrder(fakeShop($tenant), [[$variant, 1]]); // marketplace order: never counted as payment due

    $data = actingInTenant($owner, $tenant)->getJson('/api/v1/orders/pipeline')->assertOk()->json('data');

    expect($data['to_confirm'])->toBe(['count' => 1, 'amount' => '100000.00'])
        ->and($data['to_pack']['count'])->toBe(2) // the confirmed manual order + the marketplace order
        ->and($data['payment_due'])->toBe(['count' => 3, 'amount' => '550000.00']);

    // The strip's "payment due" queue is also a list filter.
    actingInTenant($owner, $tenant)->getJson('/api/v1/orders?flags[]=payment_due')->assertOk()->assertJsonCount(3, 'data');
});

it('collects what is due on many manual orders at once and reports what it skipped', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 20);
    $a = createSalesOrder($owner, $tenant, [[$variant, 2, 100000]], ['payments' => [['method' => 'cash', 'amount' => 50000]]])->json('data.id');
    $b = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['customer' => ['name' => 'B', 'phone' => '0900000003'], 'payments' => [['method' => 'cash', 'amount' => 100000]]])->json('data.id');
    $shop = fakeOrder(fakeShop($tenant), [[$variant, 1]])->ulid;

    $results = actingInTenant($owner, $tenant)->postJson('/api/v1/orders/bulk', ['ids' => [$a, $b, $shop], 'action' => 'pay', 'method' => 'bank_transfer'])
        ->assertOk()->json('data');

    expect(collect($results)->pluck('ok', 'id')->all())->toHaveCount(3)->toMatchArray([$a => true, $b => false, $shop => false]);
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$a}")->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.payments.1.method', 'bank_transfer')->assertJsonPath('data.payments.1.amount', '150000.00');

    actingInTenant($owner, $tenant)->postJson('/api/v1/orders/bulk', ['ids' => [$a], 'action' => 'pay'])->assertUnprocessable()->assertJsonValidationErrors('method');

    $viewer = addMember($tenant, Role::VIEWER);
    actingInTenant($viewer, $tenant)->postJson('/api/v1/orders/bulk', ['ids' => [$a], 'action' => 'pay', 'method' => 'cash'])->assertForbidden();
});

it('cancels many orders with one reason and releases their stock', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $a = createSalesOrder($owner, $tenant, [[$variant, 2]])->json('data.id');
    $b = createSalesOrder($owner, $tenant, [[$variant, 1]], ['stage' => 'completed', 'customer' => ['name' => 'C', 'phone' => '0900000004']])->json('data.id');

    $results = actingInTenant($owner, $tenant)->postJson('/api/v1/orders/bulk', ['ids' => [$a, $b], 'action' => 'cancel', 'reason' => 'Hết hàng'])->assertOk()->json('data');

    expect(collect($results)->pluck('ok', 'id')->all())->toHaveCount(2)->toMatchArray([$a => true, $b => false]);
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$a}")->assertJsonPath('data.status', OrderStatus::CANCELLED)->assertJsonPath('data.cancel_reason', 'Hết hàng');
    expect(balanceOf(defaultWarehouse($tenant), $variant)->reserved)->toBe(0);
});

it('exports the filtered orders to Excel', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 10);
    createSalesOrder($owner, $tenant, [[$variant, 2, 150000]], ['customer' => ['name' => 'Nguyễn Văn A', 'phone' => '0912345678']])->assertCreated();
    $paid = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['payments' => [['method' => 'cash', 'amount' => 100000]]])->json('data.external_order_id');
    createSalesOrder($otherOwner, $other, [[makeVariant($other, 'X'), 1]]);

    $response = actingInTenant($owner, $tenant)->get('/api/v1/orders/export?flags[]=payment_due');
    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'ord').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $reader = new XlsxReader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    @unlink($path);

    expect($rows)->toHaveCount(2) // heading + the unpaid order; the paid one and the other shop's order are left out
        ->and($rows[0][0])->toBe('Mã đơn')
        ->and($rows[1][4])->toBe('Nguyễn Văn A')
        ->and($rows[1][6])->toBe('0912345678')
        ->and($rows[1][8])->toStartWith('2 x ')
        ->and((float) $rows[1][13])->toBe(300000.0)
        ->and($rows[1][16])->toBe('Chờ xác nhận')
        ->and(collect($rows)->pluck(0))->not->toContain($paid);
});

it('shows a buyer summary on the order', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $buyer = ['name' => 'Khách quen', 'phone' => '0900000005'];
    $first = createSalesOrder($owner, $tenant, [[$variant, 1, 200000]], ['stage' => 'completed', 'customer' => $buyer, 'payments' => [['method' => 'cash', 'amount' => 200000]]])->json('data.id');
    $second = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['customer' => $buyer])->json('data.id');
    $itemId = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$first}")->json('data.items.0.id');
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$first}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertCreated();

    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$second}")->assertOk()->assertJsonPath('data.customer_stats', [
        'orders_count' => 2,
        'total_spent' => '300000.00',
        'debt' => '100000.00',
        'returns_count' => 1,
        'failed_deliveries' => 0,
    ]);

    $walkIn = createSalesOrder($owner, $tenant, [[$variant, 1]])->json('data.id');
    actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$walkIn}")->assertJsonPath('data.customer_stats', null);
});

it('lists returns whose goods have not come back yet', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $order = createSalesOrder($owner, $tenant, [[$variant, 2]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 200000]]])->json('data.id');
    $itemId = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order}")->json('data.items.0.id');

    $waiting = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order}/returns", ['type' => 'return', 'reason' => 'Sai size', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->json('data.id');
    $received = actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->json('data.id');
    foreach ([OrderReturn::APPROVED, OrderReturn::RECEIVED] as $status) {
        actingInTenant($owner, $tenant)->postJson("/api/v1/returns/{$received}/status", ['status' => $status])->assertOk();
    }
    actingInTenant($owner, $tenant)->postJson("/api/v1/orders/{$order}/returns", ['type' => 'refund_only', 'refund_amount' => 10000])->assertCreated();

    actingInTenant($owner, $tenant)->getJson('/api/v1/returns?status=pending_receipt')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $waiting)->assertJsonPath('data.0.reason', 'Sai size');
});
