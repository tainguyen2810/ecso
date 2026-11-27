<?php

use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;

/** Cấu hình › Mã đơn hàng: SON + running number for every order, marketplace / old numbers kept as secondary. */
function importedSapoOrder(Tenant $tenant, string $code, string $placed): Order
{
    return TenantContext::run($tenant, function () use ($tenant, $code, $placed) {
        $order = new Order;
        $order->forceFill([
            'tenant_id' => $tenant->id, 'marketplace' => Order::SOURCE_MANUAL, 'external_order_id' => $code, 'code' => $code,
            'imported_from' => 'sapo', 'historical' => true, 'raw_status' => 'COMPLETED', 'status' => 'COMPLETED', 'placed_at' => $placed,
        ])->save();

        return $order;
    });
}

it('keeps the old numbering until the shop starts, then numbers old orders by date and every new order after Sapo', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 50);
    $shop = fakeShop($tenant);
    importedSapoOrder($tenant, 'SON50890', '2026-11-20 09:00:00');
    importedSapoOrder($tenant, 'SON50891', '2026-11-21 09:00:00');

    // Before: nothing changes.
    $manual = createSalesOrder($owner, $tenant, [[$variant, 1]])->assertCreated();
    expect($manual->json('data.external_order_id'))->toBe('SO-000001')->and($manual->json('data.code'))->toBeNull();
    $market = fakeOrder($shop, [[$variant, 1]]);
    expect($market->code)->toBeNull();

    $api = actingInTenant($owner, $tenant);
    $api->getJson('/api/v1/order-codes')->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.prefix', 'SON')
        ->assertJsonPath('data.next.SON', 50892)
        ->assertJsonPath('data.counts.imported', 2)
        ->assertJsonPath('data.counts.without_code', 2);

    $api->postJson('/api/v1/order-codes/start')->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.started.count', 2)
        ->assertJsonPath('data.started.first', 'SON50892')
        ->assertJsonPath('data.started.last', 'SON50893');
    $api->postJson('/api/v1/order-codes/start')->assertUnprocessable();

    expect(Order::withoutGlobalScopes()->where('external_order_id', 'SO-000001')->value('code'))->toBe('SON50892')
        ->and($market->fresh()->code)->toBe('SON50893');

    // After: a manual order's own number is its code; a marketplace order keeps its number as the secondary one.
    $next = createSalesOrder($owner, $tenant, [[$variant, 1]], ['reference' => 'Woo #10391'])->assertCreated();
    expect($next->json('data.code'))->toBe('SON50894')->and($next->json('data.external_order_id'))->toBe('SON50894')
        ->and($next->json('data.reference'))->toBe('Woo #10391');
    $later = fakeOrder($shop, [[$variant, 1]]);
    expect($later->code)->toBe('SON50895')->and($later->external_order_id)->not->toBe('SON50895');

    // A code typed by hand must be free; the counter skips it.
    createSalesOrder($owner, $tenant, [[$variant, 1]], ['code' => 'son50896'])->assertCreated()->assertJsonPath('data.code', 'SON50896');
    createSalesOrder($owner, $tenant, [[$variant, 1]], ['code' => 'SON50896'])->assertUnprocessable()->assertJsonValidationErrors('code');
    createSalesOrder($owner, $tenant, [[$variant, 1]])->assertJsonPath('data.code', 'SON50897');

    // Search: main code, marketplace number, old number and typed reference.
    $api->getJson('/api/v1/orders?q=SON50893')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'SON50893');
    $api->getJson('/api/v1/orders?q='.substr($later->external_order_id, 2, 6))->assertJsonPath('data.0.code', 'SON50895');
    $api->getJson('/api/v1/orders?q=SO-000001')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'SON50892');
    $api->getJson('/api/v1/orders?q=10391')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'SON50894');
});

it('sets the next number, never back to one already used, and supports a prefix per channel', function () {
    [$tenant, $owner] = tenantWithOwner();
    $variant = makeVariant($tenant, 'AO-01');
    stockIn($tenant, $variant, 50);
    $shop = fakeShop($tenant);
    importedSapoOrder($tenant, 'SON100', '2026-11-20 09:00:00');
    $api = actingInTenant($owner, $tenant);

    $api->putJson('/api/v1/order-codes', ['mode' => 'shared', 'prefix' => 'SON', 'next' => 50])->assertUnprocessable()->assertJsonValidationErrors('next');
    $api->putJson('/api/v1/order-codes', ['mode' => 'shared', 'prefix' => 'son1'])->assertUnprocessable()->assertJsonValidationErrors('prefix');
    $api->putJson('/api/v1/order-codes', ['mode' => 'per_source', 'prefix' => 'SON', 'next' => 500, 'prefixes' => ['manual' => 'dh']])->assertOk()
        ->assertJsonPath('data.next.SON', 500)->assertJsonPath('data.next.DH', 1)->assertJsonPath('data.prefixes.manual', 'DH');
    $api->postJson('/api/v1/order-codes/start')->assertOk();

    // Manual orders take their own prefix; the marketplace (no prefix of its own) the shared one.
    expect(createSalesOrder($owner, $tenant, [[$variant, 1]])->json('data.code'))->toBe('DH1')
        ->and(fakeOrder($shop, [[$variant, 1]])->code)->toBe('SON500');
});

it('only lets managers change the order codes', function () {
    [$tenant, $owner] = tenantWithOwner();
    $staff = addMember($tenant, 'ORDER_STAFF');

    actingInTenant($staff, $tenant)->getJson('/api/v1/order-codes')->assertOk();
    actingInTenant($staff, $tenant)->postJson('/api/v1/order-codes/start')->assertForbidden();
    actingInTenant($staff, $tenant)->putJson('/api/v1/order-codes', ['mode' => 'shared', 'prefix' => 'DH'])->assertForbidden();
});
