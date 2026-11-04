<?php

use App\Modules\Access\Models\Role;
use App\Modules\Analytics\Services\BusinessReports;
use App\Modules\Analytics\Services\StockReports;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Audit round A: stocktake snapshot, Idempotency-Key, cost kept on every movement. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->variant = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun']);
    // Headers per request: withHeaders() would stick to every later request of the test.
    $this->receive = fn (int $qty, $cost = 100, array $headers = []) => actingInTenant($this->owner, $this->tenant)
        ->postJson('/api/v1/inventory/receipts', [
            'warehouse_id' => $this->warehouse->ulid,
            'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => $qty, 'unit_cost' => $cost]],
        ], $headers);
    $this->sell = fn (int $qty, string $key) => TenantContext::run($this->tenant, fn () => app(InventoryService::class)
        ->issue($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => $qty]], $key, null, StockMovement::SHIP_UNRESERVED));
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

it('applies a stocktake as the difference found when counting, keeping sales made before approval', function () {
    ($this->receive)(50)->assertCreated();
    $staff = addMember($this->tenant, Role::WAREHOUSE_STAFF);

    // Counted 48 while the system showed 50 (the screen sends what it showed).
    $id = actingInTenant($staff, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid, 'reason' => 'stocktake', 'draft' => true,
        'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => 48, 'system_qty' => 50]],
    ])->assertCreated()->assertJsonPath('data.lines.0.system_qty', 50)->json('data.id');
    actingInTenant($staff, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$id}/balance")->assertOk()->assertJsonPath('data.status', 'pending');

    // 5 sold and shipped before the owner approves: the pending sheet shows it.
    ($this->sell)(5, 'sale-1');
    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/inventory/adjustments/{$id}")
        ->assertOk()->assertJsonPath('data.lines.0.on_hand_now', 45)->assertJsonPath('data.lines.0.system_qty', 50);

    // Approval applies 48 − 50 = −2 to today's 45: the 5 sold are not "found" again.
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$id}/approve")
        ->assertOk()->assertJsonPath('data.lines.0.applied_delta', -2)->assertJsonPath('data.lines.0.on_hand_before', 45);
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(43);
});

it('snapshots the stock when a count line is saved without the system quantity', function () {
    ($this->receive)(10)->assertCreated();
    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid, 'reason' => 'stocktake', 'draft' => true,
        'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => 9]],
    ])->assertCreated()->assertJsonPath('data.lines.0.system_qty', 10)->json('data.id');

    ($this->receive)(4)->assertCreated(); // goods received after counting stay in stock

    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$id}/balance")
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.lines.0.applied_delta', -1);
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(13);
});

it('still refuses a stocktake that would make stock negative', function () {
    ($this->receive)(3)->assertCreated();
    $id = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->ulid, 'reason' => 'stocktake',
        'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => 0, 'system_qty' => 3]],
    ])->assertCreated()->json('data.id');
    ($this->sell)(2, 'sale-2');
    // 1 left, the count says −3: stock would be −2.
    actingInTenant($this->owner, $this->tenant)->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertUnprocessable();
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(1);
});

it('answers a repeated request with the same Idempotency-Key once', function () {
    $first = ($this->receive)(5, 100, ['Idempotency-Key' => 'rcv-1'])->assertCreated();
    $again = ($this->receive)(5, 100, ['Idempotency-Key' => 'rcv-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.id'))->toBe($first->json('data.id'));
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(5);
    TenantContext::run($this->tenant, fn () => expect(StockReceipt::query()->count())->toBe(1));

    // The same key for a different request is refused; without a key requests run as before.
    ($this->receive)(7, 100, ['Idempotency-Key' => 'rcv-1'])->assertUnprocessable();
    ($this->receive)(5)->assertCreated();
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(10);
});

it('frees the key when the request fails so the corrected form can be sent', function () {
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/inventory/receipts', [
        'warehouse_id' => $this->warehouse->ulid, 'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 0, 'unit_cost' => 1]],
    ], ['Idempotency-Key' => 'rcv-2'])->assertUnprocessable();
    ($this->receive)(2, 100, ['Idempotency-Key' => 'rcv-2'])->assertCreated();
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(2);
});

it('keeps keys per user and per business', function () {
    ($this->receive)(1, 100, ['Idempotency-Key' => 'same'])->assertCreated();
    $other = addMember($this->tenant, Role::MANAGER);
    actingInTenant($other, $this->tenant)->postJson('/api/v1/inventory/receipts', [
        'warehouse_id' => $this->warehouse->ulid, 'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 1, 'unit_cost' => 100]],
    ], ['Idempotency-Key' => 'same'])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(2);

    // A first request still running (no answer stored yet) makes the repeat wait with 409, not run twice.
    ($this->receive)(1, 100, ['Idempotency-Key' => 'busy'])->assertCreated();
    DB::table('idempotency_keys')->where('key', 'busy')->update(['status_code' => null, 'response_body' => null]);
    ($this->receive)(1, 100, ['Idempotency-Key' => 'busy'])->assertStatus(409);
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(3);
});

it('records the cost of the moment on every movement that changes quantity', function () {
    ($this->receive)(10, 100)->assertCreated();
    ($this->receive)(10, 200)->assertCreated(); // average 150
    ($this->sell)(4, 'sale-3');

    $movements = TenantContext::run($this->tenant, fn () => StockMovement::query()->orderBy('id')->get());
    expect($movements->pluck('type')->all())->toBe(['RECEIPT', 'RECEIPT', 'SHIP_UNRESERVED'])
        ->and($movements->pluck('unit_cost')->all())->toBe(['100.0000', '200.0000', '150.0000'])
        ->and($movements->pluck('avg_cost_after')->all())->toBe(['100.0000', '150.0000', '150.0000']);
});

it('values stock of a past date at the cost of that date', function () {
    $day = fn (int $d) => CarbonImmutable::parse('2026-10-0'.$d.' 10:00');
    $this->travelTo($day(1));
    ($this->receive)(10, 100)->assertCreated();
    $this->travelTo($day(3));
    ($this->receive)(10, 200)->assertCreated(); // average 150
    $this->travelTo($day(5));
    TenantContext::run($this->tenant, fn () => app(InventoryDocumentService::class)->createCostAdjustment([['variant_id' => $this->variant->id, 'new_cost' => 300]]));
    $this->travelTo($day(7));

    $at = fn (int $d) => TenantContext::run($this->tenant, fn () => app(StockReports::class)->onHand($day($d)))['rows'][0];
    expect($at(2)['unit_cost'])->toEqual(100)->and($at(2)['value'])->toEqual(1000)
        ->and($at(4)['unit_cost'])->toEqual(150)->and($at(4)['value'])->toEqual(3000)
        ->and($at(6)['unit_cost'])->toEqual(300);

    $ledger = TenantContext::run($this->tenant, fn () => app(BusinessReports::class)->stockLedger($day(1)->startOfDay(), $day(2)->endOfDay()));
    expect($ledger['rows'][0]['closing'])->toBe(10)->and($ledger['rows'][0]['closing_value'])->toEqual(1000);
});

it('restocks a returned item at the cost it was sold at', function () {
    stockIn($this->tenant, $this->variant, 10); // cost 50 000
    $shop = fakeShop($this->tenant);
    $order = fakeOrder($shop, [[$this->variant, 2]]);
    app(FakeMarketplace::class)->setStatus($order->external_order_id, 'fake_delivered');
    $item = OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->first();
    expect($item->cost_snapshot)->toBe('50000.0000');

    // The average cost changes before the parcel comes back.
    $this->actingAs($this->owner);
    TenantContext::run($this->tenant, fn () => app(InventoryDocumentService::class)->createCostAdjustment([['variant_id' => $this->variant->id, 'new_cost' => 80000]]));

    $api = actingInTenant($this->owner, $this->tenant);
    $id = $api->postJson("/api/v1/orders/{$order->ulid}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $item->id, 'quantity' => 1]]])->assertCreated()->json('data.id');
    $api->postJson("/api/v1/returns/{$id}/status", ['status' => 'approved'])->assertOk();
    $api->postJson("/api/v1/returns/{$id}/status", ['status' => 'received'])->assertOk();
    $line = $api->getJson("/api/v1/returns/{$id}")->json('data.lines.0.id');
    $api->postJson("/api/v1/returns/{$id}/inspect", ['warehouse_id' => $this->warehouse->ulid, 'lines' => [['line_id' => $line, 'received_qty' => 1, 'sellable_qty' => 1, 'damaged_qty' => 0]]])->assertOk();

    $restock = TenantContext::run($this->tenant, fn () => StockMovement::query()->where('type', StockMovement::RETURN_RESTOCK)->firstOrFail());
    expect($restock->unit_cost)->toBe('50000.0000')->and($restock->avg_cost_after)->toBe('80000.0000');
});
