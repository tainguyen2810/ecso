<?php

use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Orders\Models\PackScanError;

beforeEach(function () {
    $this->travelTo(now()->setTime(5, 0));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'barcode' => '8930001', 'location' => 'A2-03']);
    $this->pants = makeVariant($this->tenant, 'QUAN-001', ['product_name' => 'Quần jean', 'location' => 'A1-01']);
    $this->hat = makeVariant($this->tenant, 'NON-001', ['product_name' => 'Nón']);
    foreach ([$this->shirt, $this->pants, $this->hat] as $v) {
        stockIn($this->tenant, $v, 20);
    }
    $this->shop = fakeShop($this->tenant);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

it('opens a waiting order by its code or tracking number and lists what goes in the parcel', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2], [$this->pants, 1]]);
    Order::withoutGlobalScopes()->whereKey($order->id)->update(['tracking_number' => 'GHN123']);
    $api = actingInTenant($this->owner, $this->tenant);

    $api->getJson('/api/v1/orders/packing/lookup?code='.$order->external_order_id)->assertOk()
        ->assertJsonPath('data.id', $order->ulid)
        ->assertJsonCount(2, 'data.lines')
        ->assertJson(fn ($json) => $json->where('data.lines', fn ($lines) => collect($lines)->firstWhere('sku', 'AO-001')['codes'] === ['8930001', 'ao-001']
            && collect($lines)->firstWhere('sku', 'AO-001')['location'] === 'A2-03'
            && collect($lines)->firstWhere('sku', 'AO-001')['quantity'] === 2)->etc());
    $api->getJson('/api/v1/orders/packing/lookup?code=GHN123')->assertOk()->assertJsonPath('data.id', $order->ulid);
    $api->getJson('/api/v1/orders/packing/lookup?code=NOPE')->assertStatus(422)->assertJsonValidationErrors('code');

    $api->postJson("/api/v1/orders/{$order->ulid}/actions/pack")->assertOk();
    $api->getJson('/api/v1/orders/packing/lookup?code='.$order->external_order_id)->assertStatus(422)
        ->assertJsonPath('errors.code.0', fn ($m) => str_contains($m, 'Chờ bàn giao'));
});

it('packs a fully scanned order, logs wrong scans and counts today on the side panel', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    fakeOrder($this->shop, [[$this->pants, 1]]);
    $api = actingInTenant($this->owner, $this->tenant);
    $line = $api->getJson('/api/v1/orders/packing/lookup?code='.$order->external_order_id)->json('data.lines.0.id');

    $api->postJson("/api/v1/orders/{$order->ulid}/packing/errors", ['code' => 'NON-001', 'kind' => 'wrong'])->assertCreated();
    $api->postJson("/api/v1/orders/{$order->ulid}/packing/errors", ['code' => 'x', 'kind' => 'bad'])->assertStatus(422);
    $api->postJson("/api/v1/orders/{$order->ulid}/packing/finish", ['scanned' => [$line => 2]])->assertOk()->assertJsonPath('data.status', 'PACKED');

    expect(PackScanError::withoutGlobalScopes()->where('order_id', $order->id)->value('code'))->toBe('NON-001');
    $api->getJson('/api/v1/orders/packing/stats')->assertOk()
        ->assertJsonPath('data.packed_today', 1)
        ->assertJsonPath('data.errors_today', 1)
        ->assertJsonPath('data.remaining', 1)
        ->assertJsonPath('data.recent.0.external_order_id', $order->external_order_id)
        ->assertJsonPath('data.recent.0.by', $this->owner->name);
});

it('packs a short parcel only with a reason and keeps it as a note', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    $api = actingInTenant($this->owner, $this->tenant);
    $line = $api->getJson('/api/v1/orders/packing/lookup?code='.$order->external_order_id)->json('data.lines.0.id');

    $api->postJson("/api/v1/orders/{$order->ulid}/packing/finish", ['scanned' => [$line => 1]])->assertStatus(422)->assertJsonValidationErrors('reason');
    expect($order->refresh()->status)->toBe('READY_TO_PACK');

    $api->postJson("/api/v1/orders/{$order->ulid}/packing/finish", ['scanned' => [$line => 1], 'reason' => 'Hết size M, đã báo khách'])->assertOk();
    expect($order->refresh()->status)->toBe('PACKED')
        ->and(OrderNote::withoutGlobalScopes()->where('order_id', $order->id)->value('body'))->toBe('Đóng thiếu hàng: AO-001 1/2. Lý do: Hết size M, đã báo khách');
});

it('lets warehouse staff pack but not people without the permission', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    $staff = addMember($this->tenant, 'WAREHOUSE_STAFF');
    $accountant = addMember($this->tenant, 'ACCOUNTANT');

    $api = actingInTenant($staff, $this->tenant);
    $line = $api->getJson('/api/v1/orders/packing/lookup?code='.$order->external_order_id)->assertOk()->json('data.lines.0.id');
    $api->postJson("/api/v1/orders/{$order->ulid}/packing/finish", ['scanned' => [$line => 1]])->assertOk();

    actingInTenant($accountant, $this->tenant)->getJson('/api/v1/orders/packing/lookup?code='.$order->external_order_id)->assertForbidden();
    actingInTenant($accountant, $this->tenant)->getJson('/api/v1/orders/packing/stats')->assertForbidden();
});

it('sums the pick list per SKU sorted by shelf location', function () {
    $a = fakeOrder($this->shop, [[$this->shirt, 2], [$this->hat, 1]]);
    $b = fakeOrder($this->shop, [[$this->shirt, 1], [$this->pants, 3]]);

    $rows = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/orders/pick-list', ['ids' => [$a->ulid, $b->ulid]])->assertOk()->json('data.rows');

    expect(collect($rows)->pluck('sku')->all())->toBe(['QUAN-001', 'AO-001', 'NON-001'])
        ->and($rows[1]['quantity'])->toBe(3)
        ->and($rows[1]['location'])->toBe('A2-03')
        ->and($rows[1]['orders'])->toBe([['code' => $a->external_order_id, 'quantity' => 2], ['code' => $b->external_order_id, 'quantity' => 1]]);
});

it('prints the pick list of every order waiting to be packed', function () {
    fakeOrder($this->shop, [[$this->shirt, 2]]);
    fakeOrder($this->shop, [[$this->shirt, 1]], 'fake_pending');

    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/orders/pick-list', ['waiting' => true])->assertOk()
        ->assertJsonCount(1, 'data.orders')->assertJsonPath('data.rows.0.quantity', 2);
});

