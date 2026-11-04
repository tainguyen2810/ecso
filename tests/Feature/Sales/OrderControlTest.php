<?php

use App\Modules\Access\Models\Role;

it('refuses to save an order edited from a stale copy', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $order = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]])->assertCreated()->json('data');
    $version = $order['version'];
    $line = [['variant_id' => $variant->ulid, 'quantity' => 2, 'unit_price' => 100000]];

    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-orders/{$order['id']}", ['version' => $version, 'lines' => $line])->assertOk()
        ->assertJsonPath('data.version', $version + 1);
    // The second editor still holds the old version.
    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-orders/{$order['id']}", ['version' => $version, 'buyer_name' => 'Ghi đè'])
        ->assertUnprocessable()->assertJsonValidationErrors('version');
    // Without a version (older clients) the save goes through as before.
    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-orders/{$order['id']}", ['buyer_name' => 'Chị Lan'])->assertOk();
});

it('lets the owner take cancelling and voiding away from sales staff', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $staff = addMember($tenant, Role::ORDER_STAFF);

    $order = createSalesOrder($staff, $tenant, [[$variant, 1, 50000]], ['payments' => [['method' => 'cash', 'amount' => 50000]]])->assertCreated()->json('data');
    $payment = actingInTenant($staff, $tenant)->getJson("/api/v1/orders/{$order['id']}")->json('data.payments.0.id');

    revoke(Role::ORDER_STAFF, 'orders.cancel');
    revoke(Role::ORDER_STAFF, 'orders.payment.void');

    actingInTenant($staff, $tenant)->postJson("/api/v1/sales-orders/{$order['id']}/actions/cancel")->assertForbidden();
    actingInTenant($staff, $tenant)->postJson('/api/v1/orders/bulk', ['ids' => [$order['id']], 'action' => 'cancel'])->assertForbidden();
    actingInTenant($staff, $tenant)->postJson("/api/v1/payments/{$payment}/void", ['reason' => 'Nhầm'])->assertForbidden();
    // Still sells, confirms and collects.
    actingInTenant($staff, $tenant)->postJson("/api/v1/sales-orders/{$order['id']}/actions/confirm")->assertOk();

    actingInTenant($owner, $tenant)->postJson("/api/v1/payments/{$payment}/void", ['reason' => 'Nhầm'])->assertOk();
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$order['id']}/actions/cancel")->assertOk();
});

it('voids a completed sale: all goods back, money back in the methods paid, nothing deleted', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $order = createSalesOrder($owner, $tenant, [[$variant, 3, 100000]], [
        'stage' => 'completed',
        'payments' => [['method' => 'cash', 'amount' => 200000], ['method' => 'bank_transfer', 'amount' => 100000]],
    ])->assertCreated()->json('data');
    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(7);

    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$order['id']}/void-sale", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $return = actingInTenant($owner, $tenant)->withHeader('Idempotency-Key', 'void-1')
        ->postJson("/api/v1/sales-orders/{$order['id']}/void-sale", ['reason' => 'Bấm nhầm'])->assertCreated()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.refunded', '300000.00')
        ->json('data');
    // A lost answer retried: not voided twice.
    actingInTenant($owner, $tenant)->withHeader('Idempotency-Key', 'void-1')
        ->postJson("/api/v1/sales-orders/{$order['id']}/void-sale", ['reason' => 'Bấm nhầm'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(balanceOf(defaultWarehouse($tenant), $variant)->on_hand)->toBe(10);
    $detail = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$order['id']}")->assertOk()
        ->assertJsonPath('data.status', 'COMPLETED')
        ->assertJsonPath('data.return_state', 'full')
        ->assertJsonPath('data.refund_status', 'full')
        ->json('data');
    $refunds = collect($detail['payments'])->where('kind', 'refund')->pluck('amount', 'method')->all();
    expect($refunds)->toEqual(['cash' => '200000.00', 'bank_transfer' => '100000.00']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'order.sale_voided']);

    // Once is enough.
    actingInTenant($owner, $tenant)->withHeader('Idempotency-Key', 'void-2')->postJson("/api/v1/sales-orders/{$order['id']}/void-sale", ['reason' => 'Lại'])->assertUnprocessable()->assertJsonValidationErrors('order');
});

it('refuses to void a sale that is not completed, or without the cancel right', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $staff = addMember($tenant, Role::ORDER_STAFF);
    $pending = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]])->json('data');
    actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$pending['id']}/void-sale", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('order');

    $sale = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 100000]]])->json('data');
    revoke(Role::ORDER_STAFF, 'orders.cancel');
    actingInTenant($staff, $tenant)->postJson("/api/v1/sales-orders/{$sale['id']}/void-sale", ['reason' => 'x'])->assertForbidden();
});

it('taxes a sale at the rate of Cấu hình and keeps that rate on the order', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);

    // Old behaviour while no rate is set.
    createSalesOrder($owner, $tenant, [[$variant, 1, 100000]])->assertJsonPath('data.tax_amount', '0.00')->assertJsonPath('data.total', '100000.00');

    // Prices include VAT 10%: the total stays, the tax inside is shown.
    actingInTenant($owner, $tenant)->putJson('/api/v1/tenant/settings/tax', ['sale_rate' => 10, 'purchase_rate' => 0, 'prices_include_tax' => true])->assertOk();
    createSalesOrder($owner, $tenant, [[$variant, 1, 110000]], ['shipping_fee' => 30000])
        ->assertJsonPath('data.tax_amount', '10000.00')->assertJsonPath('data.total', '140000.00');

    // Prices before VAT 8%: tax on the goods after discount is added, rounded to the đồng (shipping not taxed).
    actingInTenant($owner, $tenant)->putJson('/api/v1/tenant/settings/tax', ['sale_rate' => 8, 'purchase_rate' => 0, 'prices_include_tax' => false])->assertOk();
    $order = createSalesOrder($owner, $tenant, [[$variant, 1, 100005]], ['discount' => 10, 'shipping_fee' => 20000])
        ->assertJsonPath('data.tax_rate', '8.00')
        ->assertJsonPath('data.tax_amount', '8000.00')
        ->assertJsonPath('data.total', '127995.00')
        ->json('data');

    // Cấu hình changes later: the order keeps its own rate when edited.
    actingInTenant($owner, $tenant)->putJson('/api/v1/tenant/settings/tax', ['sale_rate' => 0, 'purchase_rate' => 0, 'prices_include_tax' => true])->assertOk();
    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-orders/{$order['id']}", ['lines' => [['variant_id' => $variant->ulid, 'quantity' => 2, 'unit_price' => 100005]]])->assertOk()
        ->assertJsonPath('data.tax_amount', '16000.00')
        ->assertJsonPath('data.total', '236000.00');
});

it('refunds the VAT the buyer paid on top when the goods come back', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    actingInTenant($owner, $tenant)->putJson('/api/v1/tenant/settings/tax', ['sale_rate' => 10, 'purchase_rate' => 0, 'prices_include_tax' => false])->assertOk();
    $order = createSalesOrder($owner, $tenant, [[$variant, 2, 100000]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 220000]]])
        ->assertJsonPath('data.total', '220000.00')->json('data');

    $return = actingInTenant($owner, $tenant)->postJson("/api/v1/sales-orders/{$order['id']}/void-sale", ['reason' => 'Bấm nhầm'])->assertCreated()->json('data');
    expect($return['refunded'])->toBe('220000.00');
});
