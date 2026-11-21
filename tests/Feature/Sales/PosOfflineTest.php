<?php

use App\Modules\Access\Models\Role;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\OrderPayment;
use Illuminate\Support\Str;

/** Bán khi mất mạng: the till keeps the catalog on the device and sends the sales it made once back online. */
function offlineSale($user, $tenant, ProductVariant $variant, array $extra = [])
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => defaultChannel($tenant)->ulid,
        'warehouse_id' => defaultWarehouse($tenant)->ulid,
        'stage' => 'completed',
        'counter' => true,
        'offline' => true,
        'promotions_off' => true,
        'lines' => [['variant_id' => $variant->ulid, 'quantity' => 2, 'unit_price' => 120000]],
        'payments' => [['method' => 'cash', 'amount' => 240000]],
        ...$extra,
    ]);
}

it('gives the till its catalog with prices and the stock of one warehouse', function () {
    [$tenant, $owner] = tenantWithOwner();
    $shirt = makeVariant($tenant, 'AO-1', ['product_name' => 'Áo thun', 'ref_price' => 150000, 'barcode' => '8930001']);
    $off = makeVariant($tenant, 'CU-1', ['product_name' => 'Hàng ngừng bán', 'status' => 'inactive']);
    stockIn($tenant, $shirt, 7);

    $res = actingInTenant($owner, $tenant)->getJson('/api/v1/pos/catalog?warehouse='.defaultWarehouse($tenant)->ulid)->assertOk();
    $skus = collect($res->json('data'))->pluck('sku');
    expect($skus->all())->toContain('AO-1')->not->toContain('CU-1');
    $row = collect($res->json('data'))->firstWhere('sku', 'AO-1');
    expect($row['barcode'])->toBe('8930001')->and($row['product']['name'])->toBe('Áo thun')->and($row['stock']['available'])->toBe(7)
        ->and((float) $row['ref_price'])->toBe(150000.0);
    expect($res->json('meta.truncated'))->toBeFalse();
    actingInTenant($owner, $tenant)->getJson('/api/v1/pos/catalog?warehouse=khong-co')->assertNotFound();
});

it('takes a sale made offline once: at the time the till sold it, without an open shift, sent twice stays one order', function () {
    [$tenant] = tenantWithOwner();
    $variant = makeVariant($tenant, null, ['ref_price' => 120000]);
    stockIn($tenant, $variant, 10);
    $cashier = addMember($tenant, Role::ORDER_STAFF);
    // Staff may not change prices or drop promotions; an offline sale at list price without promotions still goes in.
    $tenant->forceFill(['settings' => ['pricing' => ['staff_edit_price' => false]]])->save();
    $key = (string) Str::uuid();
    $at = now()->subHours(2)->startOfMinute();

    // No shift is open (the setting requires one at the till): an offline sale already happened, so it is taken.
    $id = offlineSale($cashier, $tenant, $variant, ['offline_key' => $key, 'placed_at' => $at->toIso8601String()])->assertCreated()->json('data.id');
    $order = Order::withoutGlobalScopes()->where('ulid', $id)->sole();
    expect($order->placed_at->equalTo($at))->toBeTrue()
        ->and($order->completed_at->equalTo($at))->toBeTrue()
        ->and((float) $order->total)->toBe(240000.0)
        ->and((float) $order->paid_amount)->toBe(240000.0);
    expect(OrderPayment::withoutGlobalScopes()->where('order_id', $order->id)->sole()->paid_at->equalTo($at))->toBeTrue();

    // The answer was lost and the till sends it again: the same order comes back, nothing sold twice.
    offlineSale($cashier, $tenant, $variant, ['offline_key' => $key, 'placed_at' => $at->toIso8601String()])->assertOk()->assertJsonPath('data.id', $id);
    expect(Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);

    // Too old, a key is required, and a normal till sale still needs the shift.
    offlineSale($cashier, $tenant, $variant, ['offline_key' => (string) Str::uuid(), 'placed_at' => now()->subDays(8)->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('placed_at');
    offlineSale($cashier, $tenant, $variant)->assertUnprocessable()->assertJsonValidationErrors('offline_key');
    offlineSale($cashier, $tenant, $variant, ['offline' => false, 'promotions_off' => false])->assertUnprocessable()->assertJsonValidationErrors('shift');

    // A till clock a little ahead is taken as now.
    offlineSale($cashier, $tenant, $variant, ['offline_key' => (string) Str::uuid(), 'placed_at' => now()->addMinutes(3)->toIso8601String()])->assertCreated();
});
