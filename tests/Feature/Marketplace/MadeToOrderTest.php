<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\LowStockQuery;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeWooStore;

/*
 * "Hàng đặt": listed on the website at the supplier's price (the website's own plugin scrapes it and adds the margin),
 * bought only once a customer orders. ECSO takes the website price, leaves the website's stock alone, and lists the
 * orders waiting for goods ("Chờ nhập hàng") with what to buy.
 */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->lamp = makeVariant($this->tenant, 'DEN-01', ['product_name' => 'Đèn bàn', 'ref_price' => 300000]);
    $this->store = new FakeWooStore;
    // The plugin keeps it orderable on the website (the supplier has it).
    $this->store->addSimple(21, 'DEN-01', 'Đèn bàn LED', '320000', null, stock: 50);
    config(['app.url' => 'https://erp.example.vn']);
    $this->account = wooConnect($this);
    wooMap($this, $this->account);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->productId = TenantContext::run($this->tenant, fn () => $this->lamp->product->ulid);
    $this->api->patchJson("/api/v1/marketplace-accounts/{$this->account->ulid}", ['settings' => ['sync_prices' => true]])->assertOk();
});

it('takes the website price and never pushes stock to the website for a made-to-order SKU', function () {
    $this->api->putJson("/api/v1/products/{$this->productId}/sourcing", ['sourcing' => 'order', 'virtual_stock' => 0])
        ->assertOk()->assertJsonPath('data.updated', 1);
    $this->api->getJson("/api/v1/products/{$this->productId}")->assertJsonPath('data.variants.0.sourcing', 'order');
    $this->api->getJson("/api/v1/products/{$this->productId}/prices")->assertJsonPath('data.variants.0.price_source', 'woo')
        ->assertJsonPath('data.variants.0.holder', 'channel');

    // ECSO has none in stock, yet the website keeps what its plugin sets.
    $this->store->products[21]['stock_quantity'] = 50;
    $this->api->postJson("/api/v1/marketplace-accounts/{$this->account->ulid}/push-stock")->assertOk();
    expect($this->store->products[21]['stock_quantity'])->toBe(50);

    // The plugin re-prices it from the supplier: ECSO's retail price follows.
    $this->store->products[21] = array_merge($this->store->products[21], ['regular_price' => '345000', 'price' => '345000']);
    wooWebhook($this, 'product.updated', $this->store->products[21] + ['date_modified_gmt' => 'b'])->assertOk();
    expect((string) ProductVariant::withoutGlobalScopes()->whereKey($this->lamp->id)->value('ref_price'))->toBe('345000.00')
        ->and($this->store->products[21]['regular_price'])->toBe('345000');

    // Back to stock: ECSO publishes its real stock again.
    $this->api->putJson("/api/v1/products/{$this->productId}/sourcing", ['sourcing' => 'stock'])->assertOk();
    expect($this->store->products[21]['stock_quantity'])->toBe(0)
        ->and(ProductVariant::withoutGlobalScopes()->whereKey($this->lamp->id)->value('price_source'))->toBe('auto');
});

it('puts website orders of a made-to-order SKU in "Chờ nhập hàng" and fills them when the goods arrive', function () {
    $this->api->postJson('/api/v1/products/bulk-sourcing', ['ids' => [$this->productId], 'sourcing' => 'order'])->assertOk()->assertJsonPath('updated', 1);
    DB::table('stock_balances')->where('product_variant_id', $this->lamp->id)->update(['safety_stock' => 3]);

    $o = $this->store->addOrder(901, 'processing', [[21, 2, 'DEN-01', 345000]]);
    wooWebhook($this, 'order.created', $o)->assertOk();

    $order = Order::withoutGlobalScopes()->where('external_order_id', '901')->sole();
    expect($order->awaiting_purchase)->toBeTrue()->and($order->has_shortage)->toBeFalse();
    $this->api->getJson('/api/v1/orders?flags[]=awaiting_purchase')->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.flags.awaiting_purchase', true);
    $this->api->getJson('/api/v1/orders?flags[]=shortage')->assertJsonPath('meta.total', 0);

    $inbox = collect($this->api->getJson('/api/v1/inbox')->assertOk()->json('data'));
    expect($inbox->firstWhere('key', 'orders.awaiting_purchase')['count'])->toBe(1)
        ->and(TenantContext::run($this->tenant, fn () => app(LowStockQuery::class)->forTenant($this->tenant->id)))->toBe([]);

    // What to buy: the units the orders miss, not a stock to keep.
    $this->api->getJson('/api/v1/purchase-orders/reorder?only=to_order')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.variant.sku', 'DEN-01')
        ->assertJsonPath('data.0.to_order', true)->assertJsonPath('data.0.waiting', 2)
        ->assertJsonPath('data.0.waiting_orders', 1)->assertJsonPath('data.0.suggested', 2);

    // The goods arrive: held for the waiting order, which is no longer waiting.
    stockIn($this->tenant, $this->lamp, 2);
    expect($order->fresh()->awaiting_purchase)->toBeFalse()->and($order->fresh()->has_shortage)->toBeFalse();
    $this->api->getJson('/api/v1/purchase-orders/reorder?only=to_order')->assertJsonCount(0, 'data');
});

it('turns an existing shortage into "Chờ nhập hàng" when the SKU becomes made-to-order', function () {
    $o = $this->store->addOrder(902, 'processing', [[21, 1, 'DEN-01', 320000]]);
    wooWebhook($this, 'order.created', $o)->assertOk();
    $order = Order::withoutGlobalScopes()->where('external_order_id', '902')->sole();
    expect($order->has_shortage)->toBeTrue();

    $this->api->putJson("/api/v1/products/{$this->productId}/sourcing", ['sourcing' => 'order'])->assertOk();
    expect($order->fresh()->has_shortage)->toBeFalse()->and($order->fresh()->awaiting_purchase)->toBeTrue();
});

it('shows a fixed stock for made-to-order SKUs on marketplaces', function () {
    [$tenant, $owner] = tenantWithOwner('Shop B');
    $lamp = makeVariant($tenant, 'DEN-02', ['product_name' => 'Đèn ngủ']);
    $shop = fakeShop($tenant);
    $api = actingInTenant($owner, $tenant);
    $product = TenantContext::run($tenant, fn () => $lamp->product->ulid);
    $remote = fn () => (int) DB::table('fake_marketplace_products')->where('shop_id', $shop->external_shop_id)->where('seller_sku', 'DEN-02')->value('stock');

    $api->putJson("/api/v1/products/{$product}/sourcing", ['sourcing' => 'order', 'virtual_stock' => 7])->assertOk();
    expect($remote())->toBe(7);

    // The nightly check compares against that figure, not ECSO's empty stock.
    $this->artisan('ecso:marketplace:stock-check')->assertSuccessful();
    $api->getJson("/api/v1/marketplace-accounts/{$shop->ulid}/stock-check")->assertJsonPath('data.mismatched', 0);
});
