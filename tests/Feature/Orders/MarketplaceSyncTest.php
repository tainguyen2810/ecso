<?php

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Connectors\UnverifiedConnector;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Marketplace\Services\AccountService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 10);
    $this->shop = fakeShop($this->tenant);
    $this->fake = app(FakeMarketplace::class);
});

function remoteStock($shop, $variant): ?int
{
    $v = DB::table('fake_marketplace_products')->where('shop_id', $shop->external_shop_id)->where('seller_sku', $variant->sku)->value('stock');

    return $v === null ? null : (int) $v;
}

it('only suggests exact SKU matches and never maps by name', function () {
    TenantContext::run($this->tenant, fn () => Subscription::query()->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]));
    $fresh = fakeShop($this->tenant, map: false, name: 'Shop 2');
    $rows = ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $fresh->id)->get()->keyBy('seller_sku');

    expect($rows['AO-001']->mapping_status)->toBe(ListingVariant::SUGGESTED)
        ->and($rows['AO-001']->isMapped())->toBeFalse()
        ->and(ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $fresh->id)->whereNull('seller_sku')->value('mapping_status'))->toBe('unmapped');
});

it('pushes the published stock to the marketplace after mapping and after each change', function () {
    expect(remoteStock($this->shop, $this->shirt))->toBe(10);

    $order = fakeOrder($this->shop, [[$this->shirt, 3]]);
    expect(remoteStock($this->shop, $this->shirt))->toBe(7);

    TenantContext::run($this->tenant, fn () => app(InventoryService::class)
        ->setSafetyStock(defaultWarehouse($this->tenant), $this->shirt, 2));
    expect(remoteStock($this->shop, $this->shirt))->toBe(5);

    $this->fake->setStatus($order->external_order_id, 'fake_cancelled');
    expect(remoteStock($this->shop, $this->shirt))->toBe(8);

    $listing = ListingVariant::withoutGlobalScopes()->where('seller_sku', 'AO-001')->where('marketplace_account_id', $this->shop->id)->sole();
    expect($listing->push_status)->toBe('ok')->and($listing->last_pushed_qty)->toBe(8);
});

it('retries temporary push failures with backoff and opens the circuit after repeated failures', function () {
    Cache::put("fake-marketplace:fail:{$this->shop->external_shop_id}", true);
    stockIn($this->tenant, $this->shirt, 5);

    $row = InventorySyncOutbox::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->sole();
    expect($row->status)->toBe(InventorySyncOutbox::PENDING)->and($row->attempts)->toBe(1)
        ->and($row->next_attempt_at->isFuture())->toBeTrue()
        ->and(remoteStock($this->shop, $this->shirt))->toBe(10);

    // The marketplace recovers; the scheduler picks the row up when due.
    Cache::forget("fake-marketplace:fail:{$this->shop->external_shop_id}");
    $this->travel(15)->minutes();
    $this->artisan('ecso:marketplace:push-due')->assertSuccessful();
    expect(remoteStock($this->shop, $this->shirt))->toBe(15)->and($row->refresh()->status)->toBe(InventorySyncOutbox::SUCCEEDED);

    // Five consecutive failures open the circuit breaker.
    Cache::put("fake-marketplace:fail:{$this->shop->external_shop_id}", true);
    foreach (range(1, 5) as $i) {
        stockIn($this->tenant, $this->shirt, 1);
        $this->travel(1)->hours();
        $this->artisan('ecso:marketplace:push-due');
    }
    expect($this->shop->refresh()->circuitOpen())->toBeTrue();
});

it('marks a SKU the marketplace rejects without retrying it', function () {
    DB::table('fake_marketplace_products')->where('shop_id', $this->shop->external_shop_id)->where('seller_sku', 'AO-001')->delete();
    stockIn($this->tenant, $this->shirt, 1);

    $listing = ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->where('seller_sku', 'AO-001')->sole();
    expect($listing->push_status)->toBe('rejected')
        ->and(InventorySyncOutbox::withoutGlobalScopes()->where('listing_variant_id', $listing->id)->value('status'))->toBe(InventorySyncOutbox::REJECTED);
});

it('rejects webhooks with a bad signature or an old timestamp', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    [$body, $headers] = $this->fake->webhook($this->shop->external_shop_id, $order->external_order_id, 'evt-x');

    $this->call('POST', '/api/v1/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => 'bad', 'HTTP_X_FAKE_TIMESTAMP' => $headers['X-Fake-Timestamp'], 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(401);

    [$oldBody, $oldHeaders] = $this->fake->webhook($this->shop->external_shop_id, $order->external_order_id, 'evt-y', time() - 3600);
    $this->call('POST', '/api/v1/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => $oldHeaders['X-Fake-Signature'], 'HTTP_X_FAKE_TIMESTAMP' => $oldHeaders['X-Fake-Timestamp'], 'CONTENT_TYPE' => 'application/json'], $oldBody)
        ->assertStatus(401);

    $this->call('POST', '/api/v1/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => $headers['X-Fake-Signature'], 'HTTP_X_FAKE_TIMESTAMP' => $headers['X-Fake-Timestamp'], 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk()->assertJsonPath('duplicate', false);

    expect(WebhookEvent::query()->where('signature_valid', false)->count())->toBe(2);
    $this->postJson('/api/v1/webhooks/shopee', [])->assertNotFound();
});

it('ignores webhooks for a shop that is not connected', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    TenantContext::run($this->tenant, fn () => app(AccountService::class)->disconnect($this->shop));

    $this->fake->setStatus($order->external_order_id, 'fake_packed');
    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::IGNORED)
        ->and($order->refresh()->status)->toBe('READY_TO_PACK');
});

it('returns UnsupportedCapability for an unverified marketplace instead of faking success', function () {
    $connector = new class extends UnverifiedConnector
    {
        public function marketplace(): string
        {
            return 'unverified';
        }
    };

    expect($connector->capabilities())->each->toBe(Capability::UNKNOWN)
        ->and($connector->verifyWebhook('{}', []))->toBeFalse();

    try {
        $connector->updateInventory(new MarketplaceAccount, []);
        $this->fail('expected UnsupportedCapability');
    } catch (UnsupportedCapability $e) {
        expect($e->state)->toBe(Capability::UNKNOWN)->and($e->render()->getStatusCode())->toBe(422);
    }
});

it('reconciles orders whose webhook was lost', function () {
    $remote = $this->fake->createOrder($this->shop->external_shop_id, [['sku_id' => fakeSkuOf($this->shop, $this->shirt), 'quantity' => 2]], notify: false);

    $this->artisan('ecso:orders:reconcile')->assertSuccessful();

    $order = Order::withoutGlobalScopes()->where('external_order_id', $remote['order_sn'])->sole();
    expect($order->status)->toBe('READY_TO_PACK')->and(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->reserved)->toBe(2);
});

it('enforces the shop limit of the plan', function () {
    // Free plan: 1 shop.
    actingInTenant($this->owner, $this->tenant)->postJson('/api/v1/marketplace-accounts/fake', ['shop_name' => 'Shop 3'])
        ->assertStatus(403)->assertJsonPath('code', 'PLAN_LIMIT_EXCEEDED');
});
