<?php

use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeWooStore;

/** WooCommerce orders, round B: orders deleted/restored on the store, edited lines, webhook health, interval, errors. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->mouse = makeVariant($this->tenant, 'G304-BK', ['product_name' => 'Chuột G304', 'ref_price' => 499000]);
    stockIn($this->tenant, $this->mouse, 12);
    $this->store = new FakeWooStore;
    $this->store->addSimple(11, 'G304-BK', 'Chuột Logitech G304', '549000', '499000', stock: 40);
    config(['app.url' => 'https://erp.example.vn']);
    $this->account = wooConnect($this);
    wooMap($this, $this->account);
    $this->api = actingInTenant($this->owner, $this->tenant);
    $this->bump = function (int $id, array $changes) {
        $this->travel(1)->minutes();
        $this->store->orders[$id] = array_merge($this->store->orders[$id], $changes, ['date_modified_gmt' => now()->utc()->format('Y-m-d\TH:i:s')]);

        return $this->store->orders[$id];
    };
});

function syncedOrder(string $id): Order
{
    return Order::withoutGlobalScopes()->where('external_order_id', $id)->sole();
}

function mouseStock($test): array
{
    $b = balanceOf(defaultWarehouse($test->tenant), $test->mouse);

    return [$b->on_hand, $b->reserved];
}

it('keeps an order deleted on the website, cancels it if it had not shipped, and opens it again when restored', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(901, 'processing', [[11, 2, 'G304-BK', 499000]]))->assertOk();
    expect(mouseStock($this))->toBe([12, 2]);

    // Moved to the bin: Woo answers the order with status "trash".
    wooWebhook($this, 'order.deleted', ($this->bump)(901, ['status' => 'trash']))->assertOk();
    expect(syncedOrder('901')->status)->toBe('CANCELLED')->and(syncedOrder('901')->deleted_on_channel_at)->not->toBeNull()
        ->and(mouseStock($this))->toBe([12, 0]);

    // Restored from the bin: the order opens again and holds its stock again.
    wooWebhook($this, 'order.restored', ($this->bump)(901, ['status' => 'processing']))->assertOk();
    expect(syncedOrder('901')->status)->toBe('READY_TO_PACK')->and(syncedOrder('901')->deleted_on_channel_at)->toBeNull()
        ->and(syncedOrder('901')->cancelled_at)->toBeNull()->and(mouseStock($this))->toBe([12, 2]);

    // Deleted for good (the store answers 404): still kept in ECSO, cancelled, stock free.
    unset($this->store->orders[901]);
    wooWebhook($this, 'order.deleted', ['id' => 901])->assertOk();
    $order = syncedOrder('901');
    expect($order->status)->toBe('CANCELLED')->and($order->cancel_reason)->toBe('Đơn đã bị xóa trên website')
        ->and(mouseStock($this))->toBe([12, 0])
        ->and(SyncError::withoutGlobalScopes()->count())->toBe(0);
});

it('does not touch stock when an order that already shipped is deleted on the website', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(902, 'completed', [[11, 1, 'G304-BK', 499000]]))->assertOk();
    expect(mouseStock($this))->toBe([11, 0]);

    unset($this->store->orders[902]);
    wooWebhook($this, 'order.deleted', ['id' => 902])->assertOk();
    $order = syncedOrder('902');
    expect($order->status)->toBe('COMPLETED')->and($order->deleted_on_channel_at)->not->toBeNull()
        ->and($order->sync_warning)->toContain('xóa trên website')->and(mouseStock($this))->toBe([11, 0]);
});

it('follows quantity edits made on the website before the order ships, and only warns after', function () {
    wooWebhook($this, 'order.created', $this->store->addOrder(903, 'processing', [[11, 2, 'G304-BK', 499000]]))->assertOk();
    expect(mouseStock($this))->toBe([12, 2]);

    $o = $this->store->orders[903];
    $o['line_items'][0]['quantity'] = 5;
    $o['line_items'][0]['subtotal'] = $o['line_items'][0]['total'] = '2495000';
    $this->store->orders[903] = $o;
    wooWebhook($this, 'order.updated', ($this->bump)(903, []))->assertOk();
    expect(mouseStock($this))->toBe([12, 5])
        ->and(TenantContext::run($this->tenant, fn () => syncedOrder('903')->items()->sole()->quantity))->toBe(5);

    // Shipped (completed), then edited again on the website: ECSO keeps what left and flags it.
    wooWebhook($this, 'order.updated', ($this->bump)(903, ['status' => 'completed']))->assertOk();
    expect(mouseStock($this))->toBe([7, 0]);
    $o = $this->store->orders[903];
    $o['line_items'][0]['quantity'] = 1;
    $this->store->orders[903] = $o;
    wooWebhook($this, 'order.updated', ($this->bump)(903, []))->assertOk();
    expect(mouseStock($this))->toBe([7, 0])->and(syncedOrder('903')->sync_warning)->toContain('sửa số lượng');
});

it('turns webhooks that Woo disabled back on and adds missing ones', function () {
    $hooks = $this->store->webhooks;
    $first = array_key_first($hooks);
    $this->store->webhooks[$first]['status'] = 'disabled';
    $missing = $hooks[array_key_last($hooks)]['topic'];
    unset($this->store->webhooks[array_key_last($hooks)]);

    Artisan::call('ecso:woo:check-webhooks');

    expect(collect($this->store->webhooks)->pluck('status')->unique()->all())->toBe(['active'])
        ->and(collect($this->store->webhooks)->pluck('topic')->sort()->values()->all())->toHaveCount(7);
    $this->api->getJson("/api/v1/marketplace-accounts/{$this->account->ulid}")->assertOk()
        ->assertJsonPath('data.webhook_health.ok', true)
        ->assertJsonPath('data.webhook_health.reactivated', [$hooks[$first]['topic']])
        ->assertJsonPath('data.webhook_health.missing', [$missing]);

    // A store that refuses the keys: the check is recorded as failed, nothing is thrown.
    $this->store->key = 'ck_other';
    $this->api->postJson("/api/v1/marketplace-accounts/{$this->account->ulid}/check-webhooks")->assertOk()->assertJsonPath('data.ok', false);
});

it('reads each store at its own interval', function () {
    $this->api->patchJson("/api/v1/marketplace-accounts/{$this->account->ulid}", ['settings' => ['order_sync_interval' => 10]])->assertUnprocessable();
    $this->api->patchJson("/api/v1/marketplace-accounts/{$this->account->ulid}", ['settings' => ['order_sync_interval' => 30]])->assertOk()
        ->assertJsonPath('data.order_sync_interval', 30);
    $runs = fn () => SyncRun::withoutGlobalScopes()->where('marketplace_account_id', $this->account->id)->count();

    Artisan::call('ecso:orders:reconcile');
    $this->travel(15)->minutes();
    Artisan::call('ecso:orders:reconcile');
    expect($runs())->toBe(1);
    $this->travel(15)->minutes();
    Artisan::call('ecso:orders:reconcile');
    expect($runs())->toBe(2);

    // "Đồng bộ ngay" does not wait for the interval.
    $this->api->postJson("/api/v1/marketplace-accounts/{$this->account->ulid}/sync-orders")->assertOk();
    expect($runs())->toBe(3);
    $this->api->getJson('/api/v1/sync-runs')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.trigger', 'user');
});

it('keeps an error per order until it syncs, and lets the user retry or dismiss it', function () {
    $this->store->addOrder(904, 'processing', [[11, 1, 'G304-BK', 499000]]);
    $this->store->failOrders = [904];
    Artisan::call('ecso:orders:reconcile');
    $this->travel(15)->minutes();
    Artisan::call('ecso:orders:reconcile');

    $list = $this->api->getJson('/api/v1/sync-errors')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.external_id', '904')->assertJsonPath('data.0.category', 'api')
        ->assertJsonPath('data.0.attempts', 2)->assertJsonPath('meta.open_by_category.api', 1);
    $id = $list->json('data.0.id');

    // Still busy: the retry says so and the error stays open.
    $this->api->postJson("/api/v1/sync-errors/{$id}/retry")->assertUnprocessable();
    $this->store->failOrders = [];
    $this->api->postJson("/api/v1/sync-errors/{$id}/retry")->assertOk()->assertJsonPath('data.result', 'created')
        ->assertJsonPath('data.error.resolution', 'synced');
    $this->api->getJson('/api/v1/sync-errors')->assertJsonCount(0, 'data');

    // A status the order cannot move to (back from completed to processing) is a conflict the user can dismiss.
    wooWebhook($this, 'order.updated', ($this->bump)(904, ['status' => 'completed']))->assertOk();
    wooWebhook($this, 'order.updated', ($this->bump)(904, ['status' => 'processing']))->assertOk();
    $conflict = $this->api->getJson('/api/v1/sync-errors')->assertJsonPath('data.0.category', 'conflict')->json('data.0.id');
    $this->api->postJson("/api/v1/sync-errors/{$conflict}/resolve")->assertOk()->assertJsonPath('data.resolution', 'dismissed');

    // Another business cannot see or touch them.
    [$other, $otherOwner] = tenantWithOwner();
    actingInTenant($otherOwner, $other)->getJson('/api/v1/sync-errors?state=resolved')->assertOk()->assertJsonCount(0, 'data');
    actingInTenant($otherOwner, $other)->postJson("/api/v1/sync-errors/{$conflict}/retry")->assertNotFound();
});
