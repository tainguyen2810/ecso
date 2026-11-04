<?php

use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Audit round D: transfers received short, goods on the way, closing a warehouse, stock reconciliation. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->main = defaultWarehouse($this->tenant);
    $this->second = TenantContext::run($this->tenant, fn () => Warehouse::create(['code' => 'K2', 'name' => 'Kho 2']));
    $this->variant = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun']);
    stockIn($this->tenant, $this->variant, 10);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->transfer = function (int $qty) {
        $id = $this->api->postJson('/api/v1/inventory/transfers', [
            'from_warehouse_id' => $this->main->ulid, 'to_warehouse_id' => $this->second->ulid, 'lines' => [['variant_id' => $this->variant->ulid, 'quantity' => $qty]],
        ])->assertCreated()->json('data.id');
        $this->api->postJson("/api/v1/inventory/transfers/{$id}/dispatch")->assertOk();

        return $id;
    };
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

it('receives a transfer short and sends the missing part to a pending loss adjustment', function () {
    $id = ($this->transfer)(6);
    $product = TenantContext::run($this->tenant, fn () => $this->variant->product->ulid);
    $row = collect($this->api->getJson("/api/v1/inventory/products/{$product}/stock")->json('data'))->firstWhere('warehouse.code', 'K2');
    expect($row['in_transit'])->toBe(6);

    $line = $this->api->getJson("/api/v1/inventory/transfers/{$id}")->json('data.lines.0.line_id');
    $this->api->postJson("/api/v1/inventory/transfers/{$id}/receive", ['lines' => [['line_id' => $line, 'received_qty' => 7]]])->assertUnprocessable();
    $doc = $this->api->postJson("/api/v1/inventory/transfers/{$id}/receive", ['lines' => [['line_id' => $line, 'received_qty' => 4]], 'note' => 'Rách thùng'])
        ->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.lines.0.received_qty', 4)->assertJsonPath('data.shortage.status', 'pending')->json('data');

    // Everything sent is booked in; the 2 missing wait for an approver as "thất lạc".
    expect(balanceOf($this->second, $this->variant)->on_hand)->toBe(6);
    $loss = TenantContext::run($this->tenant, fn () => InventoryAdjustment::query()->where('number', $doc['shortage']['number'])->with('lines')->sole());
    expect($loss->reason)->toBe('lost')->and($loss->lines[0]->quantity)->toBe(-2)->and($loss->note)->toContain('Rách thùng');

    $this->api->postJson("/api/v1/inventory/adjustments/{$doc['shortage']['ulid']}/approve")->assertOk();
    expect(balanceOf($this->second, $this->variant)->on_hand)->toBe(4)
        ->and(balanceOf($this->main, $this->variant)->on_hand)->toBe(4);
});

it('receives in full when no quantities are sent', function () {
    $id = ($this->transfer)(3);
    $this->api->postJson("/api/v1/inventory/transfers/{$id}/receive")->assertOk()->assertJsonPath('data.shortage', null)->assertJsonPath('data.lines.0.received_qty', 3);
    expect(balanceOf($this->second, $this->variant)->on_hand)->toBe(3);
});

it('refuses to close a warehouse that still has work pointing at it', function () {
    // Kho 2 is empty but a stocktake there is still waiting for approval.
    $id = $this->api->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->second->ulid, 'reason' => 'stocktake', 'lines' => [['variant_id' => $this->variant->ulid, 'mode' => 'count', 'quantity' => 0]],
    ])->assertCreated()->json('data.id');

    $message = $this->api->patchJson("/api/v1/warehouses/{$this->second->ulid}", ['status' => 'inactive'])->assertStatus(422)->json('message');
    expect($message)->toContain('1 phiếu kiểm/điều chỉnh chưa duyệt');

    $this->api->postJson("/api/v1/inventory/adjustments/{$id}/cancel")->assertOk();
    $this->api->patchJson("/api/v1/warehouses/{$this->second->ulid}", ['status' => 'inactive'])->assertOk();
});

it('lists ledger problems and orders handed over short', function () {
    $this->api->getJson('/api/v1/inventory/reconciliation')->assertOk()->assertJsonPath('data.ledger', [])->assertJsonPath('data.shortages', []);

    // A broken balance (only possible by editing the database) is reported, not fixed.
    DB::table('stock_balances')->where('warehouse_id', $this->main->id)->update(['on_hand' => 11]);
    $this->api->getJson('/api/v1/inventory/reconciliation')->assertOk()
        ->assertJsonPath('data.ledger.0.problem', 'on_hand_vs_ledger')->assertJsonPath('data.ledger.0.variant.sku', 'AO-001')
        ->assertJsonPath('data.ledger.0.expected', 10)->assertJsonPath('data.ledger.0.actual', 11);
    DB::table('stock_balances')->where('warehouse_id', $this->main->id)->update(['on_hand' => 10]);
});
