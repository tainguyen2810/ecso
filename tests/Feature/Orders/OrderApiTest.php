<?php

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    $this->pants = makeVariant($this->tenant, 'QUAN-001', ['product_name' => 'Quần jean', 'ref_price' => 350000]);
    stockIn($this->tenant, $this->shirt, 20);
    stockIn($this->tenant, $this->pants, 20);
    $this->shop = fakeShop($this->tenant);
});

afterEach(function () {
    expect(app(StockVerifier::class)->verify($this->tenant->id))->toBe([]);
});

it('lists orders with tab counts and filters', function () {
    fakeOrder($this->shop, [[$this->shirt, 1]]);
    fakeOrder($this->shop, [[$this->pants, 1]], 'fake_pending');
    fakeOrder($this->shop, [[$this->pants, 2]], 'fake_shipped');

    $api = actingInTenant($this->owner, $this->tenant);
    $api->getJson('/api/v1/orders')->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.counts.all', 3)
        ->assertJsonPath('meta.counts.to_pack', 1)
        ->assertJsonPath('meta.counts.to_confirm', 1)
        ->assertJsonPath('meta.counts.shipping', 1);

    $api->getJson('/api/v1/orders?tab=to_pack')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'READY_TO_PACK');
    $api->getJson('/api/v1/orders?q=QUAN-001')->assertJsonCount(2, 'data');
    $api->getJson('/api/v1/orders?sku='.$this->shirt->ulid)->assertJsonCount(1, 'data');
    $api->getJson('/api/v1/orders?flags[]=overdue')->assertJsonCount(0, 'data');
});

it('shows an order with items, reservations, history and available actions', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);

    actingInTenant($this->owner, $this->tenant)->getJson("/api/v1/orders/{$order->ulid}")->assertOk()
        ->assertJsonPath('data.external_order_id', $order->external_order_id)
        ->assertJsonPath('data.items.0.variant.sku', 'AO-001')
        ->assertJsonPath('data.items.0.reservation.quantity', 2)
        ->assertJsonPath('data.history.0.to', 'READY_TO_PACK')
        ->assertJsonPath('data.actions.pack.allowed', true)
        ->assertJsonPath('data.actions.confirm.allowed', false)
        ->assertJsonMissingPath('data.raw')
        ->assertJson(fn ($json) => $json->where('data.capabilities', fn ($c) => $c['shipping.label'] === 'unsupported')->etc());
});

it('packs and hands over through the marketplace and records who did it', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    $api = actingInTenant($this->owner, $this->tenant);

    $api->postJson("/api/v1/orders/{$order->ulid}/actions/pack")->assertOk()->assertJsonPath('data.status', 'PACKED');
    $api->postJson("/api/v1/orders/{$order->ulid}/actions/pack")->assertStatus(422);
    $api->postJson("/api/v1/orders/{$order->ulid}/actions/handover")->assertOk()->assertJsonPath('data.status', 'HANDED_OVER');

    $history = $api->getJson("/api/v1/orders/{$order->ulid}")->json('data.history');
    expect(collect($history)->pluck('source')->all())->toBe(['webhook', 'user', 'user'])
        ->and($history[2]['actor'])->toBe($this->owner->name)
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->only(['on_hand', 'reserved']))->toBe(['on_hand' => 18, 'reserved' => 0]);
});

it('runs bulk actions order by order and reports each result', function () {
    $a = fakeOrder($this->shop, [[$this->shirt, 1]], 'fake_pending');
    $b = fakeOrder($this->shop, [[$this->shirt, 1]], 'fake_pending');
    $c = fakeOrder($this->shop, [[$this->shirt, 1]]); // not confirmable

    $results = actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/orders/bulk', ['ids' => [$a->ulid, $b->ulid, $c->ulid], 'action' => 'confirm'])
        ->assertOk()->json('data');

    expect(collect($results)->where('ok', true)->count())->toBe(2)
        ->and(collect($results)->firstWhere('id', $c->ulid)['ok'])->toBeFalse()
        ->and($a->refresh()->status)->toBe('READY_TO_PACK');
});

it('assigns orders to members only, adds notes and marks packing slips as printed', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    $staff = addMember($this->tenant, 'WAREHOUSE_STAFF');
    $stranger = User::factory()->create();
    $api = actingInTenant($this->owner, $this->tenant);

    $api->putJson("/api/v1/orders/{$order->ulid}/assignee", ['user_id' => $staff->ulid])->assertOk()->assertJsonPath('data.assignee.name', $staff->name);
    $api->putJson("/api/v1/orders/{$order->ulid}/assignee", ['user_id' => $stranger->ulid])->assertStatus(422);
    $api->postJson("/api/v1/orders/{$order->ulid}/notes", ['body' => 'Gói kỹ, khách dặn'])->assertCreated();

    $api->postJson('/api/v1/orders/packing-slips', ['ids' => [$order->ulid]])->assertOk()
        ->assertJsonPath('data.0.items.0.sku', 'AO-001')->assertJsonPath('data.0.buyer_phone', fn ($p) => str_contains($p, '*'));
    expect($order->refresh()->packing_slip_printed_at)->not->toBeNull();

    // Warehouse staff can view and print, but not change order status.
    actingInTenant($staff, $this->tenant)->getJson('/api/v1/orders?assignee=me')->assertOk()->assertJsonCount(1, 'data');
    actingInTenant($staff, $this->tenant)->postJson("/api/v1/orders/{$order->ulid}/actions/pack")->assertForbidden();
    actingInTenant($staff, $this->tenant)->postJson('/api/v1/orders/packing-slips', ['ids' => [$order->ulid]])->assertOk();
});

it('keeps orders, shops and listings isolated between tenants', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $listing = ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->first();
    $api = actingInTenant($otherOwner, $other);

    $api->getJson("/api/v1/orders/{$order->ulid}")->assertNotFound();
    $api->getJson('/api/v1/orders')->assertJsonCount(0, 'data');
    $api->postJson("/api/v1/orders/{$order->ulid}/actions/pack")->assertNotFound();
    $api->getJson("/api/v1/marketplace-accounts/{$this->shop->ulid}/listings")->assertNotFound();
    $api->putJson("/api/v1/listings/{$listing->ulid}/mapping", ['variant_id' => $this->shirt->ulid])->assertNotFound();
    $api->postJson("/api/v1/fake-marketplace/{$this->shop->ulid}/orders", ['count' => 1])->assertNotFound();
    $api->getJson('/api/v1/marketplace-webhooks')->assertOk()->assertJsonCount(0, 'data');
});

it('records a customer return and restocks only what is inspected as sellable', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 3]]);
    app(FakeMarketplace::class)->setStatus($order->external_order_id, 'fake_delivered');
    $item = OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->first();
    $api = actingInTenant($this->owner, $this->tenant);

    $api->postJson("/api/v1/orders/{$order->ulid}/returns", ['type' => 'return', 'lines' => [['order_item_id' => $item->id, 'quantity' => 4]]])->assertStatus(422);
    $id = $api->postJson("/api/v1/orders/{$order->ulid}/returns", ['type' => 'return', 'reason' => 'Sai size', 'lines' => [['order_item_id' => $item->id, 'quantity' => 2]]])
        ->assertCreated()->assertJsonPath('data.number', 'RET-000001')->json('data.id');

    $api->postJson("/api/v1/returns/{$id}/status", ['status' => 'closed'])->assertStatus(422);
    $api->postJson("/api/v1/returns/{$id}/status", ['status' => 'approved'])->assertOk();
    $api->postJson("/api/v1/returns/{$id}/status", ['status' => 'received'])->assertOk();
    $line = $api->getJson("/api/v1/returns/{$id}")->json('data.lines.0.id');

    $api->postJson("/api/v1/returns/{$id}/inspect", ['warehouse_id' => defaultWarehouse($this->tenant)->ulid, 'lines' => [['line_id' => $line, 'received_qty' => 2, 'sellable_qty' => 2, 'damaged_qty' => 1]]])->assertStatus(422);
    $api->postJson("/api/v1/returns/{$id}/inspect", ['warehouse_id' => defaultWarehouse($this->tenant)->ulid, 'lines' => [['line_id' => $line, 'received_qty' => 2, 'sellable_qty' => 1, 'damaged_qty' => 1]]])
        ->assertOk()->assertJsonPath('data.status', 'inspected');

    expect(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->on_hand)->toBe(18);
    $api->getJson('/api/v1/returns')->assertOk()->assertJsonCount(1, 'data');
});

it('maps listings through the API and lets the simulator create orders', function () {
    $fresh = $this->shop;
    $listing = ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $fresh->id)->whereNull('seller_sku')->sole();
    $api = actingInTenant($this->owner, $this->tenant);

    $api->getJson("/api/v1/marketplace-accounts/{$fresh->ulid}/listings?status=unmapped")->assertOk()->assertJsonCount(1, 'data');
    $api->putJson("/api/v1/listings/{$listing->ulid}/mapping", ['variant_id' => $this->pants->ulid])->assertOk()->assertJsonPath('data.mapping_status', 'mapped');
    $api->deleteJson("/api/v1/listings/{$listing->ulid}/mapping", ['ignore' => true])->assertOk()->assertJsonPath('data.mapping_status', 'ignored');

    $api->postJson("/api/v1/fake-marketplace/{$fresh->ulid}/orders", ['count' => 3])->assertCreated()->assertJsonCount(3, 'data.orders');
    expect(Order::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(3);

    $api->getJson('/api/v1/marketplaces')->assertOk()->assertJsonPath('data.0.key', 'shopee')->assertJsonPath('data.0.available', true)
        ->assertJson(fn ($json) => $json->where('data.0.capabilities', fn ($c) => $c['inventory.update'] === 'unknown')->etc());
    $api->getJson('/api/v1/marketplace-accounts')->assertOk()->assertJsonPath('data.0.stats.mapped', 2);
});

it('summarises orders for the dashboard without counting cancelled orders as revenue', function () {
    $a = fakeOrder($this->shop, [[$this->shirt, 1]]);
    fakeOrder($this->shop, [[$this->pants, 1]], 'fake_cancelled');

    actingInTenant($this->owner, $this->tenant)->getJson('/api/v1/orders/summary')->assertOk()
        ->assertJsonPath('data.today_orders', 1)
        ->assertJsonPath('data.today_ordered_revenue', (string) Order::withoutGlobalScopes()->find($a->id)->total)
        ->assertJsonPath('data.to_process', 1)
        ->assertJsonCount(2, 'data.recent');

    $viewer = addMember($this->tenant, 'accountant');
    actingInTenant($viewer, $this->tenant)->getJson('/api/v1/orders/summary')->assertOk();
});
