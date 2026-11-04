<?php

use App\Modules\Access\Models\Role;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('filters orders by the warehouse they ship from and finds a walk-in buyer by phone', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $second = TenantContext::run($tenant, fn () => Warehouse::create(['code' => 'K2', 'name' => 'Kho 2']));
    stockIn($tenant, $variant, 10, $second);

    $main = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['buyer_name' => 'Khách lẻ', 'buyer_phone' => '0912 345 678'])->json('data.id');
    $other = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['warehouse_id' => $second->ulid])->json('data.id');

    actingInTenant($owner, $tenant)->getJson('/api/v1/orders?warehouse='.$second->ulid)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other);
    // Not a saved customer: found through the order's own phone lookup key, in any format.
    actingInTenant($owner, $tenant)->getJson('/api/v1/orders?q='.urlencode('+84912345678'))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $main);
});

it('shows a member without orders.view.all only the orders they created or were assigned', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $cashier = addMember($tenant, Role::ORDER_STAFF);

    $mine = createSalesOrder($cashier, $tenant, [[$variant, 1, 100000]])->json('data.id');
    $theirs = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]])->json('data.id');

    // Every existing role keeps seeing all orders.
    actingInTenant($cashier, $tenant)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(2, 'data');

    revoke(Role::ORDER_STAFF, 'orders.view.all');
    actingInTenant($cashier, $tenant)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
    actingInTenant($cashier, $tenant)->getJson("/api/v1/orders/{$theirs}")->assertForbidden();
    actingInTenant($owner, $tenant)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(2, 'data');
});

it('reports money that does not add up, without changing anything', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 10);
    $id = createSalesOrder($owner, $tenant, [[$variant, 1, 100000]], ['payments' => [['method' => 'cash', 'amount' => 100000]]])->json('data.id');

    expect(Artisan::call('ecso:orders:verify-money', ['--tenant' => $tenant->id]))->toBe(0);

    DB::table('orders')->where('ulid', $id)->update(['paid_amount' => 50000]);
    expect(Artisan::call('ecso:orders:verify-money', ['--tenant' => $tenant->id]))->toBe(1)
        ->and(Artisan::output())->toContain('paid_amount khác tổng phiếu thu');
    expect((string) Order::withoutGlobalScopes()->where('ulid', $id)->value('paid_amount'))->toBe('50000.00');
});

it('keeps what each edit changed and shows it on the order', function () {
    [$tenant, $owner] = tenantWithOwner();
    $a = makeVariant($tenant, 'SKU-A');
    $b = makeVariant($tenant, 'SKU-B');
    stockIn($tenant, $a, 10);
    stockIn($tenant, $b, 10);
    $id = createSalesOrder($owner, $tenant, [[$a, 1, 100000]])->json('data.id');

    actingInTenant($owner, $tenant)->putJson("/api/v1/sales-orders/{$id}", [
        'lines' => [['variant_id' => $a->ulid, 'quantity' => 2, 'unit_price' => 90000], ['variant_id' => $b->ulid, 'quantity' => 1, 'unit_price' => 50000]],
        'shipping_fee' => 20000,
    ])->assertOk();

    $changes = actingInTenant($owner, $tenant)->getJson("/api/v1/orders/{$id}")->assertOk()->json('data.edits.0.changes');
    expect($changes)->toContain('SKU-A: SL 1 → 2', 'SKU-A: giá 100.000 → 90.000', 'Thêm SKU-B × 1', 'Phí giao hàng: 0 → 20.000', 'Tổng tiền: 100.000 → 250.000');
});

it('keeps order drafts without creating an order or holding stock', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant);
    stockIn($tenant, $variant, 5);
    $cashier = addMember($tenant, Role::ORDER_STAFF);
    $body = ['title' => 'Chị Lan hỏi giá', 'total' => 300000, 'lines_count' => 1, 'payload' => ['lines' => [['variant_id' => $variant->ulid, 'quantity' => 2]]]];

    $id = actingInTenant($cashier, $tenant)->postJson('/api/v1/order-drafts', $body)->assertCreated()->json('data.id');
    expect(Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(balanceOf(defaultWarehouse($tenant), $variant)->reserved)->toBe(0);

    actingInTenant($cashier, $tenant)->putJson("/api/v1/order-drafts/{$id}", [...$body, 'title' => 'Chị Lan'])->assertOk()->assertJsonPath('data.title', 'Chị Lan');
    actingInTenant($cashier, $tenant)->getJson("/api/v1/order-drafts/{$id}")->assertOk()->assertJsonPath('data.payload.lines.0.quantity', 2);
    actingInTenant($owner, $tenant)->getJson('/api/v1/order-drafts')->assertOk()->assertJsonCount(1, 'data');

    // Without orders.view.all a colleague's draft is not visible.
    revoke(Role::ORDER_STAFF, 'orders.view.all');
    $ownerDraft = actingInTenant($owner, $tenant)->postJson('/api/v1/order-drafts', $body)->json('data.id');
    actingInTenant($cashier, $tenant)->getJson('/api/v1/order-drafts')->assertOk()->assertJsonCount(1, 'data');
    actingInTenant($cashier, $tenant)->deleteJson("/api/v1/order-drafts/{$ownerDraft}")->assertNotFound();

    actingInTenant($cashier, $tenant)->deleteJson("/api/v1/order-drafts/{$id}")->assertNoContent();
    // Another shop never sees them.
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->getJson("/api/v1/order-drafts/{$ownerDraft}")->assertNotFound();
});
