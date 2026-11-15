<?php

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Carbon;

/** Lô / hạn sử dụng: lots on receipts, FEFO holds, returns to the same lot, expiry report. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-15 10:00:00');
    [$this->tenant, $this->owner] = tenantWithOwner();
    inTenant($this->tenant);
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->variant = makeVariant($this->tenant, 'KCN-50', ['product_name' => 'Kem chống nắng']);
    $this->product = Product::withoutGlobalScopes()->findOrFail($this->variant->product_id);
    $this->product->forceFill(['track_lots' => true, 'expiry_warn_days' => 60, 'expiry_block_days' => 15])->save();
    $this->as = fn () => actingInTenant($this->owner, $this->tenant);
    $this->receive = fn (array $lots, int $qty) => ($this->as)()->postJson('/api/v1/inventory/receipts', [
        'warehouse_id' => $this->warehouse->ulid,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => $qty, 'unit_cost' => 100000, 'lots' => $lots]],
    ]);
    $this->lot = fn (string $no) => StockLot::withoutGlobalScopes()->where('product_variant_id', $this->variant->id)->where('warehouse_id', $this->warehouse->id)->where('lot_no', $no)->first();
});

afterEach(function () {
    Carbon::setTestNow();
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

it('asks for lot numbers and expiry dates on a receipt and keeps them per lot', function () {
    ($this->receive)([], 10)->assertUnprocessable()->assertJsonValidationErrors('lines.0.lots');
    ($this->receive)([['lot_no' => 'A', 'expiry_date' => '2028-01-01', 'quantity' => 4]], 10)
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.lots');
    ($this->receive)([['lot_no' => 'A', 'quantity' => 10]], 10)->assertUnprocessable();

    $receipt = ($this->receive)([
        ['lot_no' => 'L2509A', 'mfg_date' => '2026-09-05', 'expiry_date' => '2028-09-05', 'quantity' => 6],
        ['lot_no' => 'L2510C', 'expiry_date' => '2028-10-20', 'quantity' => 4],
    ], 10)->assertCreated();

    expect($receipt->json('data.lines.0.lots'))->toHaveCount(2)
        ->and($receipt->json('data.lines.0.lots.0.lot_no'))->toBe('L2509A')
        ->and(($this->lot)('L2509A')->on_hand)->toBe(6)
        ->and(($this->lot)('L2509A')->expiry_date->toDateString())->toBe('2028-09-05')
        ->and(($this->lot)('L2510C')->on_hand)->toBe(4);
});

it('fills the expiry date from the manufacturing date and the shelf life', function () {
    $this->product->forceFill(['shelf_life_months' => 24])->save();
    ($this->receive)([['lot_no' => 'M1', 'mfg_date' => '2026-03-31', 'quantity' => 3]], 3)->assertCreated();

    expect(($this->lot)('M1')->expiry_date->toDateString())->toBe('2028-03-31');
});

it('holds lots first-expiry-first, never an expired or stop-selling lot, and ships what it held', function () {
    ($this->receive)([
        ['lot_no' => 'NEW', 'expiry_date' => '2028-09-05', 'quantity' => 5],
        ['lot_no' => 'SOON', 'expiry_date' => '2027-01-02', 'quantity' => 3],
        ['lot_no' => 'STOP', 'expiry_date' => '2026-11-25', 'quantity' => 6], // 10 days left < 15: not sold
    ], 14)->assertCreated();

    $order = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 4]])->assertCreated()->json('data');
    expect(($this->lot)('SOON')->reserved)->toBe(3)->and(($this->lot)('NEW')->reserved)->toBe(1)->and(($this->lot)('STOP')->reserved)->toBe(0);

    $detail = ($this->as)()->getJson("/api/v1/orders/{$order['id']}")->assertOk();
    expect(collect($detail->json('data.items.0.lots'))->pluck('quantity', 'lot_no')->all())->toBe(['SOON' => 3, 'NEW' => 1]);

    // 14 on hand, 4 held, 6 in a stop-selling lot: only 4 more can be held, and marketplaces see 4.
    expect(TenantContext::run($this->tenant, fn () => app(StockReader::class)->sellable([$this->warehouse->id], [$this->variant->id])[$this->variant->id]))->toBe(4);
    $short = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 6]])->json('data');
    expect(StockReservation::withoutGlobalScopes()->where('reference_id', firstLotItem($this, $short['id']))->value('shortage'))->toBe(2);

    ($this->as)()->postJson("/api/v1/sales-orders/{$order['id']}/actions/confirm")->assertOk();
    ($this->as)()->postJson("/api/v1/sales-orders/{$order['id']}/actions/handover")->assertOk();
    expect(($this->lot)('SOON')->on_hand)->toBe(0)->and(($this->lot)('NEW')->on_hand)->toBe(4)
        ->and(($this->as)()->getJson("/api/v1/orders/{$order['id']}")->json('data.items.0.lots.0.shipped'))->toBeTrue();
});

it('lets staff pick other lots for an order line', function () {
    ($this->receive)([
        ['lot_no' => 'A', 'expiry_date' => '2027-06-01', 'quantity' => 5],
        ['lot_no' => 'B', 'expiry_date' => '2028-06-01', 'quantity' => 5],
        ['lot_no' => 'X', 'expiry_date' => '2026-11-20', 'quantity' => 5],
    ], 15)->assertCreated();
    $order = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 3]])->json('data');
    $item = firstLotItem($this, $order['id']);

    $choices = ($this->as)()->getJson("/api/v1/orders/{$order['id']}/items/{$item}/lots")->assertOk()->json('data.0');
    expect($choices['quantity'])->toBe(3)
        ->and(collect($choices['lots'])->firstWhere('lot_no', 'X')['blocked'])->toBeTrue()
        ->and(collect($choices['lots'])->firstWhere('lot_no', 'A')['taken'])->toBe(3);

    $lotId = fn (string $no) => collect($choices['lots'])->firstWhere('lot_no', $no)['lot_id'];
    $put = fn (array $lots) => ($this->as)()->putJson("/api/v1/orders/{$order['id']}/items/{$item}/lots", ['reservation_id' => $choices['reservation_id'], 'lots' => $lots]);

    $put([['lot_id' => $lotId('X'), 'quantity' => 3]])->assertUnprocessable();
    $put([['lot_id' => $lotId('B'), 'quantity' => 2]])->assertUnprocessable(); // must add up to 3
    $put([['lot_id' => $lotId('A'), 'quantity' => 1], ['lot_id' => $lotId('B'), 'quantity' => 2]])->assertOk();

    expect(($this->lot)('A')->reserved)->toBe(1)->and(($this->lot)('B')->reserved)->toBe(2);
});

it('releases the held lots when an order is cancelled', function () {
    ($this->receive)([['lot_no' => 'A', 'expiry_date' => '2027-06-01', 'quantity' => 5]], 5)->assertCreated();
    $order = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2]])->json('data');
    expect(($this->lot)('A')->reserved)->toBe(2);

    ($this->as)()->postJson("/api/v1/sales-orders/{$order['id']}/actions/cancel", ['reason' => 'Khách hủy'])->assertOk();
    expect(($this->lot)('A')->reserved)->toBe(0)->and(($this->lot)('A')->on_hand)->toBe(5);
});

it('puts returned goods back into the lot they were shipped from', function () {
    ($this->receive)([
        ['lot_no' => 'A', 'expiry_date' => '2027-06-01', 'quantity' => 2],
        ['lot_no' => 'B', 'expiry_date' => '2028-06-01', 'quantity' => 5],
    ], 7)->assertCreated();
    $order = createSalesOrder($this->owner, $this->tenant, [[$this->variant, 2]], ['stage' => 'completed', 'payments' => [['method' => 'cash', 'amount' => 200000]]])->json('data');
    expect(($this->lot)('A')->on_hand)->toBe(0);

    $return = ($this->as)()->postJson("/api/v1/orders/{$order['id']}/returns", ['type' => 'return', 'lines' => [['order_item_id' => firstLotItem($this, $order['id']), 'quantity' => 1]]])
        ->assertCreated()->json('data');
    foreach ([OrderReturn::APPROVED, OrderReturn::RECEIVED] as $status) {
        ($this->as)()->postJson("/api/v1/returns/{$return['id']}/status", ['status' => $status])->assertOk();
    }
    $lineId = ($this->as)()->getJson("/api/v1/returns/{$return['id']}")->json('data.lines.0.id');
    ($this->as)()->postJson("/api/v1/returns/{$return['id']}/inspect", ['warehouse_id' => $this->warehouse->ulid, 'lines' => [
        ['line_id' => $lineId, 'received_qty' => 1, 'sellable_qty' => 1, 'damaged_qty' => 0],
    ]])->assertOk();

    expect(($this->lot)('A')->on_hand)->toBe(1)->and(($this->lot)('B')->on_hand)->toBe(5)->and(($this->lot)('')?->on_hand ?? 0)->toBe(0);
});

it('moves the same lots between warehouses on a transfer', function () {
    TenantContext::run($this->tenant, fn () => Subscription::query()->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]));
    $other = TenantContext::run($this->tenant, fn () => app(WarehouseService::class)->create(['code' => 'TD', 'name' => 'Kho Thủ Đức']));
    ($this->receive)([
        ['lot_no' => 'A', 'expiry_date' => '2027-06-01', 'quantity' => 2],
        ['lot_no' => 'B', 'expiry_date' => '2028-06-01', 'quantity' => 5],
    ], 7)->assertCreated();

    $transfer = ($this->as)()->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $this->warehouse->ulid, 'to_warehouse_id' => $other->ulid,
        'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => 3]],
    ])->assertCreated()->json('data');
    ($this->as)()->postJson("/api/v1/inventory/transfers/{$transfer['id']}/dispatch")->assertOk();
    ($this->as)()->postJson("/api/v1/inventory/transfers/{$transfer['id']}/receive")->assertOk();

    $there = StockLot::withoutGlobalScopes()->where('warehouse_id', $other->id)->pluck('on_hand', 'lot_no')->all();
    expect($there)->toBe(['A' => 2, 'B' => 1])
        ->and(StockLot::withoutGlobalScopes()->where('warehouse_id', $other->id)->where('lot_no', 'B')->first()->expiry_date->toDateString())->toBe('2028-06-01');
});

it('puts existing stock and open holds into "Chưa rõ lô" when tracking is switched on, then splits it', function () {
    $this->product->forceFill(['track_lots' => false])->save();
    stockIn($this->tenant, $this->variant, 10);
    createSalesOrder($this->owner, $this->tenant, [[$this->variant, 3]])->assertCreated();

    ($this->as)()->putJson("/api/v1/products/{$this->product->ulid}", ['track_lots' => true, 'expiry_block_days' => 15])->assertOk();
    $unknown = ($this->lot)('');
    expect($unknown->on_hand)->toBe(10)->and($unknown->reserved)->toBe(3);

    ($this->as)()->postJson("/api/v1/inventory/lots/{$unknown->id}/move", ['lot_no' => 'REAL', 'expiry_date' => '2028-01-01', 'quantity' => 8])
        ->assertUnprocessable(); // 3 of the 10 are held for the order
    ($this->as)()->postJson("/api/v1/inventory/lots/{$unknown->id}/move", ['lot_no' => 'REAL', 'expiry_date' => '2028-01-01', 'quantity' => 7])->assertOk();

    expect(($this->lot)('')->on_hand)->toBe(3)->and(($this->lot)('REAL')->on_hand)->toBe(7);
    $tab = ($this->as)()->getJson("/api/v1/inventory/products/{$this->product->ulid}/lots")->assertOk();
    expect(collect($tab->json('data'))->pluck('on_hand', 'lot_no')->all())->toBe([null => 3, 'REAL' => 7]);
});

it('corrects lot dates and moves an expired lot to the defective warehouse', function () {
    ($this->receive)([['lot_no' => 'OLD', 'expiry_date' => '2027-01-01', 'quantity' => 4]], 4)->assertCreated();
    $lot = ($this->lot)('OLD');

    ($this->as)()->putJson("/api/v1/inventory/lots/{$lot->id}", ['expiry_date' => '2026-11-01'])->assertOk();
    expect(($this->lot)('OLD')->expiry_date->toDateString())->toBe('2026-11-01')
        ->and(TenantContext::run($this->tenant, fn () => app(StockReader::class)->sellable([$this->warehouse->id], [$this->variant->id])[$this->variant->id]))->toBe(0);

    ($this->as)()->postJson("/api/v1/inventory/lots/{$lot->id}/to-defective")->assertOk();
    $defective = TenantContext::run($this->tenant, fn () => app(WarehouseService::class)->defective());
    expect(($this->lot)('OLD')->on_hand)->toBe(0)
        ->and(StockLot::withoutGlobalScopes()->where('warehouse_id', $defective->id)->where('lot_no', 'OLD')->value('on_hand'))->toBe(4);
});

it('lists lots by days left in the expiry report and the bell', function () {
    ($this->receive)([
        ['lot_no' => 'GONE', 'expiry_date' => '2026-11-10', 'quantity' => 2],
        ['lot_no' => 'D20', 'expiry_date' => '2026-12-05', 'quantity' => 3],
        ['lot_no' => 'D50', 'expiry_date' => '2027-01-04', 'quantity' => 4],
        ['lot_no' => 'FAR', 'expiry_date' => '2028-01-01', 'quantity' => 5],
    ], 14)->assertCreated();

    $report = ($this->as)()->getJson('/api/v1/reports/expiry?by=d30')->assertOk();
    expect(collect($report->json('data.rows'))->pluck('lot_no')->all())->toBe(['D20'])
        ->and($report->json('data.totals.expired.lots'))->toBe(1)
        ->and($report->json('data.totals.d60.lots'))->toBe(1);
    expect(collect(($this->as)()->getJson('/api/v1/reports/expiry?by=expired')->json('data.rows'))->pluck('lot_no')->all())->toBe(['GONE']);

    $bell = collect(($this->as)()->getJson('/api/v1/inbox')->assertOk()->json('data'))->keyBy('key');
    // Blocked: GONE (expired). Expiring within 60 days and still sold: D20, D50.
    expect($bell['stock.expired']['count'])->toBe(1)->and($bell['stock.expiring']['count'])->toBe(2);
});

it('leaves products without lot tracking alone', function () {
    $plain = makeVariant($this->tenant, 'PLAIN');
    stockIn($this->tenant, $plain, 5);
    createSalesOrder($this->owner, $this->tenant, [[$plain, 2]])->assertCreated();

    expect(StockLot::withoutGlobalScopes()->where('product_variant_id', $plain->id)->count())->toBe(0);
    TenantContext::run($this->tenant, fn () => expect(fn () => app(InventoryService::class)->redateLot((new StockLot)->forceFill(['product_variant_id' => $plain->id]), null, '2027-01-01', 'x'))->toThrow(Exception::class));
});

function firstLotItem($test, string $orderId): int
{
    return actingInTenant($test->owner, $test->tenant)->getJson("/api/v1/orders/{$orderId}")->json('data.items.0.id');
}
