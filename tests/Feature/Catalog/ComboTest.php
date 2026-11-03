<?php

use App\Modules\Inventory\Services\StockReader;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Support\TenantContext;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->shirt = makeVariant($this->tenant, 'AO-1', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    $this->socks = makeVariant($this->tenant, 'VO-1', ['product_name' => 'Vớ', 'ref_price' => 30000]);
    stockIn($this->tenant, $this->shirt, 10); // cost 50,000
    stockIn($this->tenant, $this->socks, 9);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

/** Combo "1 áo + 3 đôi vớ". */
function comboPayload($test, array $overrides = []): array
{
    return array_replace([
        'type' => 'combo',
        'name' => 'Combo áo + 3 đôi vớ',
        'variants' => [['sku' => 'CB-AO-VO', 'ref_price' => 199000]],
        'components' => [['variant_id' => $test->shirt->ulid, 'quantity' => 1], ['variant_id' => $test->socks->ulid, 'quantity' => 3]],
    ], $overrides);
}

function comboOrder($user, $tenant, $variant, int $qty)
{
    return actingInTenant($user, $tenant)->postJson('/api/v1/sales-orders', [
        'channel_id' => SalesChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail()->ulid,
        'lines' => [['variant_id' => $variant, 'quantity' => $qty, 'unit_price' => 199000]],
    ]);
}

it('creates a combo whose stock is what its components make', function () {
    $res = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', comboPayload($this))
        ->assertCreated()
        ->assertJsonPath('data.type', 'combo')
        ->assertJsonPath('data.variants.0.components.1.variant.sku', 'VO-1')
        ->assertJsonPath('data.variants.0.components.1.quantity', 3);
    $id = $res->json('data.id');

    // 10 shirts, 9 socks / 3 = 3 combos.
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/products?type=combo')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock.available', 3);
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/inventory/products/{$id}/stock")->assertOk()
        ->assertJsonPath('data.0.available', 3)
        ->assertJsonPath('data.0.cost_price', '200000.0000'); // 50,000 + 3 × 50,000
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/variants?q=CB-AO&warehouse={$this->warehouse->ulid}")->assertOk()
        ->assertJsonPath('data.0.stock.available', 3);
    // Stock documents do not offer combos.
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/variants?q=CB-AO&stocked=1')->assertOk()->assertJsonCount(0, 'data');
    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/inventory/stock?q=CB-AO')->assertOk()->assertJsonCount(0, 'data');

    TenantContext::run($this->tenant, function () {
        $comboId = OrderItem::query()->getConnection()->table('product_variants')->where('sku', 'CB-AO-VO')->value('id');
        expect(app(StockReader::class)->sellable([$this->warehouse->id], [$comboId, $this->shirt->id]))->toBe([$comboId => 3, $this->shirt->id => 10]);
    });
});

it('validates combos', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->postJson('/api/v1/products', comboPayload($this, ['components' => []]))->assertUnprocessable()->assertJsonValidationErrors('components');
    $api->postJson('/api/v1/products', comboPayload($this, ['variants' => [['sku' => 'CB-1'], ['sku' => 'CB-2']]]))->assertUnprocessable()->assertJsonValidationErrors('variants');
    $api->postJson('/api/v1/products', comboPayload($this, ['opening_warehouse_id' => $this->warehouse->ulid, 'variants' => [['sku' => 'CB-1', 'opening_qty' => 5]]]))
        ->assertUnprocessable()->assertJsonValidationErrors('opening_warehouse_id');
    $api->postJson('/api/v1/products', ['name' => 'Thường', 'variants' => [['sku' => 'X-1']], 'components' => [['variant_id' => $this->shirt->ulid, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('components');

    $combo = $api->postJson('/api/v1/products', comboPayload($this))->json('data.variants.0.id');
    // A combo is not a component of another combo, and has no stock of its own.
    $api->postJson('/api/v1/products', comboPayload($this, ['variants' => [['sku' => 'CB-2']], 'components' => [['variant_id' => $combo, 'quantity' => 1]]]))
        ->assertUnprocessable()->assertJsonValidationErrors('components.0.variant_id');
    $api->postJson('/api/v1/inventory/receipts', ['warehouse_id' => $this->warehouse->ulid, 'lines' => [['variant_id' => $combo, 'quantity' => 1, 'unit_cost' => 1]]])
        ->assertUnprocessable();

    // A SKU used in a combo cannot be deleted, even without stock.
    $cap = makeVariant($this->tenant, 'MU-1', ['product_name' => 'Mũ']);
    $api->postJson('/api/v1/products', comboPayload($this, ['variants' => [['sku' => 'CB-MU']], 'components' => [['variant_id' => $cap->ulid, 'quantity' => 1]]]))->assertCreated();
    inTenant($this->tenant);
    $capProduct = $cap->product->ulid;
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products/bulk-delete', ['ids' => [$capProduct]])->assertOk()
        ->assertJsonPath('deleted', 0)->assertJsonPath('skipped.0.message', 'SKU MU-1 đang là thành phần của combo CB-MU, không thể xóa.');
});

it('holds, ships and costs a combo order on its components', function () {
    $combo = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', comboPayload($this))->json('data.variants.0.id');

    $order = comboOrder($this->owner, $this->tenant, $combo, 2)->assertCreated()->json('data.id');
    expect(balanceOf($this->warehouse, $this->shirt)->reserved)->toBe(2)
        ->and(balanceOf($this->warehouse, $this->socks)->reserved)->toBe(6);
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/orders/{$order}")->assertOk()
        ->assertJsonPath('data.items.0.reservation.quantity', 2);
    // Remaining combos: socks 3 left → 1.
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/variants?q=CB-AO&warehouse={$this->warehouse->ulid}")->assertJsonPath('data.0.stock.available', 1);

    foreach (['confirm', 'pack'] as $action) {
        actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/sales-orders/{$order}/actions/{$action}")->assertOk();
    }
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/sales-orders/{$order}/actions/handover", ['carrier' => 'GHN', 'tracking_number' => 'X1'])->assertOk();

    expect(balanceOf($this->warehouse, $this->shirt)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 8, 'reserved' => 0])
        ->and(balanceOf($this->warehouse, $this->socks)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 3, 'reserved' => 0]);
    inTenant($this->tenant);
    expect(OrderItem::query()->sole()->cost_snapshot)->toBe('200000.0000');
});

it('releases a cancelled combo order and reports a combo shortage', function () {
    $combo = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', comboPayload($this))->json('data.variants.0.id');

    // 4 combos need 12 socks; only 9: the order cannot be confirmed for shipping.
    $order = comboOrder($this->owner, $this->tenant, $combo, 4)->assertCreated()->json('data.id');
    expect(balanceOf($this->warehouse, $this->socks)->reserved)->toBe(9);
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/orders/{$order}")
        ->assertJsonPath('data.items.0.reservation.quantity', 3)
        ->assertJsonPath('data.items.0.reservation.shortage', 1);

    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/sales-orders/{$order}/actions/cancel", ['reason' => 'Khách đổi ý'])->assertOk();
    expect(balanceOf($this->warehouse, $this->shirt)->reserved)->toBe(0)
        ->and(balanceOf($this->warehouse, $this->socks)->reserved)->toBe(0);
});

it('restocks a returned combo as its components', function () {
    $combo = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/products', comboPayload($this))->json('data.variants.0.id');
    $order = comboOrder($this->owner, $this->tenant, $combo, 1)->json('data.id');
    foreach (['confirm', 'pack'] as $action) {
        actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/sales-orders/{$order}/actions/{$action}")->assertOk();
    }
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/sales-orders/{$order}/actions/handover", ['carrier' => 'GHN', 'tracking_number' => 'X2'])->assertOk();
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/sales-orders/{$order}/actions/deliver")->assertOk();

    $itemId = actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/orders/{$order}")->json('data.items.0.id');
    $return = actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/orders/{$order}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $itemId, 'quantity' => 1]]])->assertCreated()->json('data.id');
    foreach (['approved', 'received'] as $status) {
        actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/returns/{$return}/status", ['status' => $status])->assertOk();
    }
    $line = actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/returns/{$return}")->json('data.lines.0.id');
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/returns/{$return}/inspect", [
        'warehouse_id' => $this->warehouse->ulid,
        'lines' => [['line_id' => $line, 'received_qty' => 1, 'sellable_qty' => 1, 'damaged_qty' => 0]],
    ])->assertOk();

    expect(balanceOf($this->warehouse, $this->shirt)->on_hand)->toBe(10)
        ->and(balanceOf($this->warehouse, $this->socks)->on_hand)->toBe(9);
});
