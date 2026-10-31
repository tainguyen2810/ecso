<?php

use App\Modules\Inventory\Events\AvailableStockChanged;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\InvalidStockOperation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    [$this->tenant] = tenantWithOwner();
    inTenant($this->tenant);
    $this->warehouse = defaultWarehouse($this->tenant);
    $this->variant = makeVariant($this->tenant, 'AT-001');
    $this->inventory = app(InventoryService::class);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

it('creates a default warehouse for every new tenant', function () {
    expect($this->warehouse->code)->toBe('MAIN')->and($this->warehouse->is_default)->toBeTrue();
});

it('receives stock, writes the ledger and computes weighted average cost', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 10, 'unit_cost' => '100']], 'r1');
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 30, 'unit_cost' => '200']], 'r2');

    $balance = balanceOf($this->warehouse, $this->variant);
    expect($balance->on_hand)->toBe(40)->and($balance->reserved)->toBe(0)->and($balance->version)->toBe(2)
        // (10×100 + 30×200) / 40 = 175
        ->and($this->variant->refresh()->cost_price)->toBe('175.0000');

    $movements = StockMovement::query()->orderBy('id')->get();
    expect($movements)->toHaveCount(2)
        ->and($movements[1]->on_hand_after)->toBe(40)
        ->and($movements[1]->unit_cost)->toBe('200.0000')
        ->and($movements[1]->type)->toBe(StockMovement::RECEIPT);
});

it('is idempotent: the same key applies once', function () {
    foreach (range(1, 5) as $i) {
        $result = $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 7, 'unit_cost' => 1]], 'same-key');
        expect($result->applied)->toBe($i === 1);
    }

    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(7)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('reserves up to available and records the rest as shortage', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 5, 'unit_cost' => 1]], 'r');

    $full = $this->inventory->reserve($this->warehouse, $this->variant, 3, 'order_item', 1, 'res-1');
    $partial = $this->inventory->reserve($this->warehouse, $this->variant, 4, 'order_item', 2, 'res-2');
    $none = $this->inventory->reserve($this->warehouse, $this->variant, 2, 'order_item', 3, 'res-3');

    expect([$full->quantity, $full->shortage])->toBe([3, 0])
        ->and([$partial->quantity, $partial->shortage])->toBe([2, 2])
        ->and([$none->quantity, $none->shortage])->toBe([0, 2]);

    $balance = balanceOf($this->warehouse, $this->variant);
    expect($balance->reserved)->toBe(5)->and($balance->available())->toBe(0);
});

it('can refuse a reservation instead of recording shortage', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => 1]], 'r');

    expect(fn () => $this->inventory->reserve($this->warehouse, $this->variant, 2, 'order_item', 1, 'k', allowShortage: false))
        ->toThrow(InsufficientStock::class);
    expect(balanceOf($this->warehouse, $this->variant)->reserved)->toBe(0);
});

it('does not reserve twice for the same reference, whatever the key', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 10, 'unit_cost' => 1]], 'r');

    $a = $this->inventory->reserve($this->warehouse, $this->variant, 2, 'order_item', 9, 'webhook-1');
    $b = $this->inventory->reserve($this->warehouse, $this->variant, 2, 'order_item', 9, 'webhook-1');
    $c = $this->inventory->reserve($this->warehouse, $this->variant, 2, 'order_item', 9, 'webhook-retry-2');

    expect($b->id)->toBe($a->id)->and($c->id)->toBe($a->id)
        ->and(balanceOf($this->warehouse, $this->variant)->reserved)->toBe(2)
        ->and(StockReservation::query()->count())->toBe(1);
});

it('releases and ships reservations exactly once', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 10, 'unit_cost' => 1]], 'r');
    $cancelled = $this->inventory->reserve($this->warehouse, $this->variant, 3, 'order_item', 1, 'a');
    $shipped = $this->inventory->reserve($this->warehouse, $this->variant, 4, 'order_item', 2, 'b');

    $this->inventory->release($cancelled, 'cancel-1');
    $this->inventory->release($cancelled, 'cancel-1-again');
    $this->inventory->ship($shipped, 'ship-2');
    $this->inventory->ship($shipped, 'ship-2');

    $balance = balanceOf($this->warehouse, $this->variant);
    expect($balance->on_hand)->toBe(6)->and($balance->reserved)->toBe(0)
        ->and($cancelled->refresh()->status)->toBe(StockReservation::RELEASED)
        ->and($shipped->refresh()->status)->toBe(StockReservation::CONSUMED);

    // A released reference can be reserved again (order re-opened).
    $again = $this->inventory->reserve($this->warehouse, $this->variant, 1, 'order_item', 1, 'reopen');
    expect($again->id)->toBe($cancelled->id)->and($again->status)->toBe(StockReservation::ACTIVE);
});

it('never issues stock reserved for someone else', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 5, 'unit_cost' => 1]], 'r');
    $this->inventory->reserve($this->warehouse, $this->variant, 4, 'order_item', 1, 'res');

    expect(fn () => $this->inventory->issue($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 2]], 'out', null, StockMovement::DAMAGE_OUT))
        ->toThrow(InsufficientStock::class);

    $this->inventory->issue($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 1]], 'out-ok', null, StockMovement::DAMAGE_OUT);
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(4);
});

it('rolls back the whole document when one line fails', function () {
    $other = makeVariant($this->tenant, 'AT-002');
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 5, 'unit_cost' => 1]], 'r');

    expect(fn () => $this->inventory->issue($this->warehouse, [
        ['variant_id' => $this->variant->id, 'quantity' => 2],
        ['variant_id' => $other->id, 'quantity' => 1],
    ], 'multi', null, StockMovement::TRANSFER_OUT))->toThrow(InsufficientStock::class);

    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(5)
        ->and(StockMovement::query()->where('type', StockMovement::TRANSFER_OUT)->count())->toBe(0);
});

it('applies delta and count adjustments without going negative', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 10, 'unit_cost' => 1]], 'r');
    $other = makeVariant($this->tenant, 'AT-003');

    $applied = $this->inventory->adjust($this->warehouse, [
        ['variant_id' => $this->variant->id, 'mode' => 'count', 'quantity' => 7],
        ['variant_id' => $other->id, 'mode' => 'delta', 'quantity' => 4],
    ], 'adj-1', null, 'stocktake');

    expect($applied)->toBe([
        ['variant_id' => $this->variant->id, 'on_hand_before' => 10, 'applied_delta' => -3],
        ['variant_id' => $other->id, 'on_hand_before' => 0, 'applied_delta' => 4],
    ]);
    expect($this->inventory->adjust($this->warehouse, [['variant_id' => $this->variant->id, 'mode' => 'count', 'quantity' => 7]], 'adj-1', null))->toBeNull();

    expect(fn () => $this->inventory->adjust($this->warehouse, [['variant_id' => $this->variant->id, 'mode' => 'delta', 'quantity' => -8]], 'adj-2', null))
        ->toThrow(InsufficientStock::class);
    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(7);
});

it('allows negative stock only in warehouses configured for it', function () {
    $this->warehouse->forceFill(['allow_negative' => true])->save();
    $this->inventory->issue($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 2]], 'neg', null, StockMovement::SHIP_UNRESERVED);

    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(-2);
});

it('rejects variants and warehouses of another tenant', function () {
    [$other] = tenantWithOwner('Shop B');
    $foreignVariant = makeVariant($other, 'B-1');
    $foreignWarehouse = defaultWarehouse($other);

    expect(fn () => $this->inventory->receive($this->warehouse, [['variant_id' => $foreignVariant->id, 'quantity' => 1, 'unit_cost' => 1]], 'x'))
        ->toThrow(InvalidStockOperation::class);
    expect(fn () => $this->inventory->receive($foreignWarehouse, [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => 1]], 'y'))
        ->toThrow(InvalidStockOperation::class);
});

it('validates quantities and duplicate lines', function () {
    expect(fn () => $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 0, 'unit_cost' => 1]], 'a'))->toThrow(InvalidStockOperation::class);
    expect(fn () => $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => -1]], 'b'))->toThrow(InvalidStockOperation::class);
    expect(fn () => $this->inventory->receive($this->warehouse, [
        ['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => 1],
        ['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => 1],
    ], 'c'))->toThrow(InvalidStockOperation::class);
    expect(fn () => $this->inventory->reserve($this->warehouse, $this->variant, 0, 'order_item', 1, 'd'))->toThrow(InvalidStockOperation::class);
});

it('keeps movements immutable', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_cost' => 1]], 'r');
    $movement = StockMovement::query()->first();

    expect(fn () => $movement->forceFill(['on_hand_delta' => 100])->save())->toThrow(LogicException::class);
    expect(fn () => $movement->delete())->toThrow(LogicException::class);
});

it('fires AvailableStockChanged after commit with the changed variants', function () {
    Event::fake([AvailableStockChanged::class]);

    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 3, 'unit_cost' => 1]], 'r');
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 3, 'unit_cost' => 1]], 'r'); // duplicate: no event

    Event::assertDispatchedTimes(AvailableStockChanged::class, 1);
    Event::assertDispatched(AvailableStockChanged::class, fn ($e) => $e->tenantId === $this->tenant->id && $e->variantIds === [$this->variant->id]);
});

it('detects a ledger that does not match the balance', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 3, 'unit_cost' => 1]], 'r');
    // Simulate corruption outside InventoryService.
    DB::table('stock_balances')->where('product_variant_id', $this->variant->id)->update(['on_hand' => 99]);

    $problems = app(StockVerifier::class)->verify($this->tenant->id);
    expect($problems)->toHaveCount(1)->and($problems[0]['problem'])->toBe('on_hand_vs_ledger');

    DB::table('stock_balances')->where('product_variant_id', $this->variant->id)->update(['on_hand' => 3]);
    $this->artisan('ecso:inventory:verify')->assertSuccessful();
});

it('lets reservations use safety stock (orders already exist on the marketplace)', function () {
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 5, 'unit_cost' => 1]], 'r');
    $balance = $this->inventory->setSafetyStock($this->warehouse, $this->variant, 2);

    expect($balance->safety_stock)->toBe(2);
    // Orders already exist on the marketplace, so reservation may use safety stock.
    expect($this->inventory->reserve($this->warehouse, $this->variant, 5, 'order_item', 1, 'k')->quantity)->toBe(5);
});

it('treats a transfer into a second warehouse as stock in without changing cost', function () {
    $second = Warehouse::create(['code' => 'HN', 'name' => 'Kho Hà Nội', 'status' => 'active']);
    $this->inventory->receive($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 10, 'unit_cost' => '50']], 'r');

    $this->inventory->issue($this->warehouse, [['variant_id' => $this->variant->id, 'quantity' => 4]], 't-out', null, StockMovement::TRANSFER_OUT);
    $this->inventory->transferIn($second, [['variant_id' => $this->variant->id, 'quantity' => 4]], 't-in', null);

    expect(balanceOf($this->warehouse, $this->variant)->on_hand)->toBe(6)
        ->and(balanceOf($second, $this->variant)->on_hand)->toBe(4)
        ->and($this->variant->refresh()->cost_price)->toBe('50.0000');
});
