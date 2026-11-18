<?php

use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Orders\Jobs\BackfillOrdersJob;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Per-shop order sync settings: "Lấy đơn từ" moment, reconcile interval, and "Lấy lại đơn theo ngày". */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 10);
    $this->shop = fakeShop($this->tenant);
    $this->fake = app(FakeMarketplace::class);
    $this->api = actingInTenant($this->owner, $this->tenant);
});

/** An order on the fake marketplace placed (and last changed) $days ago, without a webhook. */
function oldRemoteOrder($test, int $days, int $quantity = 2): string
{
    $remote = $test->fake->createOrder($test->shop->external_shop_id, [['sku_id' => fakeSkuOf($test->shop, $test->shirt), 'quantity' => $quantity]], notify: false);
    $at = now()->subDays($days);
    $data = json_decode((string) DB::table('fake_marketplace_orders')->where('order_sn', $remote['order_sn'])->value('data'), true);
    $data['created_at'] = $at->toIso8601String();
    DB::table('fake_marketplace_orders')->where('order_sn', $remote['order_sn'])->update(['data' => json_encode($data), 'update_time' => $at->format('Y-m-d H:i:s.u')]);

    return $remote['order_sn'];
}

function localOrder(string $sn): ?Order
{
    return Order::withoutGlobalScopes()->where('external_order_id', $sn)->first();
}

it('reads only the last 3 days on the first pass of a shop without a "Lấy đơn từ" moment', function () {
    $recent = oldRemoteOrder($this, 2);
    $old = oldRemoteOrder($this, 5);

    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncRecent(MarketplaceAccount::query()->findOrFail($this->shop->id)));

    expect(localOrder($recent))->not->toBeNull()->and(localOrder($old))->toBeNull();
});

it('starts the first pass at the "Lấy đơn từ" moment and skips orders placed before it', function () {
    $this->api->patchJson("/api/v1/marketplace-accounts/{$this->shop->ulid}", ['orders_since' => now()->subDays(7)->toDateString()])
        ->assertOk()->assertJsonPath('data.orders_since', fn ($v) => $v !== null);
    $inside = oldRemoteOrder($this, 6);
    $before = oldRemoteOrder($this, 9);

    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncRecent(MarketplaceAccount::query()->findOrFail($this->shop->id), CarbonImmutable::now()->subDays(30)));

    expect(localOrder($inside))->not->toBeNull()->and(localOrder($before))->toBeNull();
});

it('validates the "Lấy đơn từ" moment and the reconcile interval', function () {
    $url = "/api/v1/marketplace-accounts/{$this->shop->ulid}";
    $this->api->patchJson($url, ['orders_since' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('orders_since');
    $this->api->patchJson($url, ['orders_since' => now()->subDays(400)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('orders_since');
    $this->api->patchJson($url, ['settings' => ['order_sync_interval' => 45]])->assertStatus(422);

    $this->api->patchJson($url, ['settings' => ['order_sync_interval' => 60], 'orders_since' => null])->assertOk()
        ->assertJsonPath('data.order_sync_interval', 60)->assertJsonPath('data.orders_since', null);
});

it('fetches a past range as history: old orders are kept but never touch the stock', function () {
    $this->shop->forceFill(['orders_since' => now()->subDay()])->save();
    $old = oldRemoteOrder($this, 20, 3);
    $already = fakeOrder($this->shop, [[$this->shirt, 1]]);
    expect(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->reserved)->toBe(1);

    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncWindow(MarketplaceAccount::query()->findOrFail($this->shop->id), CarbonImmutable::now()->subDays(30), CarbonImmutable::now(), false));

    $order = localOrder($old);
    expect($order)->not->toBeNull()->and($order->historical)->toBeTrue()
        ->and($already->refresh()->historical)->toBeFalse()
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->reserved)->toBe(1)
        ->and(Order::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->count())->toBe(2);

    // Cancelled later on the marketplace: nothing was reserved, so nothing is released.
    $this->fake->setStatus($old, 'fake_cancelled');
    expect(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->reserved)->toBe(1);

    $run = SyncRun::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->where('trigger', 'backfill')->sole();
    expect($run->status)->toBe(SyncRun::OK);
});

it('deducts stock for fetched orders when asked', function () {
    $old = oldRemoteOrder($this, 20, 3);

    TenantContext::run($this->tenant, fn () => app(OrderSync::class)->syncWindow(MarketplaceAccount::query()->findOrFail($this->shop->id), CarbonImmutable::now()->subDays(30), CarbonImmutable::now(), true));

    expect(localOrder($old)->historical)->toBeFalse()->and(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->reserved)->toBe(3);
});

it('splits a range into windows of at most 15 days', function () {
    $from = CarbonImmutable::parse('2026-08-01 00:00:00');
    $windows = BackfillOrdersJob::windows($from, $from->addDays(40));

    expect($windows)->toHaveCount(3)
        ->and($windows[0][1]->equalTo($from->addDays(15)))->toBeTrue()
        ->and($windows[2][1]->equalTo($from->addDays(40)))->toBeTrue()
        ->and(BackfillOrdersJob::windows($from, $from->addDays(3)))->toHaveCount(1);
});

it('queues "Lấy lại đơn theo ngày" and runs every window without moving the cursor', function () {
    $old = oldRemoteOrder($this, 25);
    $url = "/api/v1/marketplace-accounts/{$this->shop->ulid}/backfill-orders";

    $this->api->postJson($url, ['from' => now()->subDays(40)->toDateString(), 'to' => now()->toDateString()])
        ->assertStatus(202)->assertJsonPath('data.windows', 3);

    expect(localOrder($old)?->historical)->toBeTrue()
        ->and(SyncRun::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->where('trigger', 'backfill')->count())->toBe(3)
        ->and(DB::table('sync_cursors')->where('marketplace_account_id', $this->shop->id)->value('last_synced_at'))->toBeNull();
});

it('refuses a backfill range that is too long, reversed or in the future', function () {
    Queue::fake();
    $url = "/api/v1/marketplace-accounts/{$this->shop->ulid}/backfill-orders";

    $this->api->postJson($url, ['from' => now()->subDays(100)->toDateString(), 'to' => now()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('from');
    $this->api->postJson($url, ['from' => now()->toDateString(), 'to' => now()->subDays(3)->toDateString()])->assertStatus(422);
    $this->api->postJson($url, ['from' => now()->toDateString(), 'to' => now()->addDays(2)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('to');

    Queue::assertNothingPushed();
});

it('shows why pulling products failed, and clears that error after a successful pull', function () {
    $url = "/api/v1/marketplace-accounts/{$this->shop->ulid}/sync-products";
    Cache::put("fake-marketplace:fail:{$this->shop->external_shop_id}", true);

    $this->api->postJson($url)->assertStatus(422)->assertJsonPath('message', 'Sàn giả lập đang lỗi tạm thời (mô phỏng).');
    expect($this->shop->refresh()->last_error)->toStartWith('Lấy sản phẩm: ');

    Cache::forget("fake-marketplace:fail:{$this->shop->external_shop_id}");
    $this->api->postJson($url)->assertOk();
    expect($this->shop->refresh()->last_error)->toBeNull();
});
