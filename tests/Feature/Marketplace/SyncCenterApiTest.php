<?php

use App\Modules\Access\Models\Role;
use App\Modules\Marketplace\Domain\SyncErrorCode;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Marketplace\Models\WebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Sync Center round 1: overview, unified task queue, safe retry/cancel, correlation ids, error codes, permissions. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 10);
    $this->shop = fakeShop($this->tenant);
    $this->listing = ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->where('seller_sku', 'AO-001')->sole();
    $this->outbox = fn () => InventorySyncOutbox::withoutGlobalScopes()->where('listing_variant_id', $this->listing->id)->sole();
    $this->remote = fn () => (int) DB::table('fake_marketplace_products')->where('shop_id', $this->shop->external_shop_id)->where('seller_sku', 'AO-001')->value('stock');
    $this->api = actingInTenant($this->owner, $this->tenant);
});

it('follows one webhook to the stock push it caused with a single correlation id', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 3]]);
    $event = WebhookEvent::query()->where('external_order_id', $order->external_order_id)->firstOrFail();

    expect($event->correlation_id)->not->toBeNull()->and(($this->outbox)()->correlation_id)->toBe($event->correlation_id);

    $rows = $this->api->getJson('/api/v1/sync-center/tasks?q='.$event->correlation_id)->assertOk()->json('data');
    expect(collect($rows)->pluck('type')->sort()->values()->all())->toBe(['stock_push', 'webhook']);

    $this->api->getJson("/api/v1/sync-center/tasks/webhook/{$event->id}")->assertOk()
        ->assertJsonPath('data.status', 'success')->assertJsonPath('data.object', "Đơn {$order->external_order_id}")
        ->assertJsonPath('data.related.0.type', 'stock_push');
});

it('shows the overview of shops, tasks and problems', function () {
    fakeOrder($this->shop, [[$this->shirt, 1]]);
    // Someone changed the stock on the channel: what was read back differs from what ECSO published.
    $this->listing->refresh()->forceFill(['marketplace_stock' => 50])->save();

    $this->api->getJson('/api/v1/sync-center/overview')->assertOk()
        ->assertJsonPath('data.accounts.total', 1)->assertJsonPath('data.accounts.active', 1)
        ->assertJsonPath('data.problems.stock_drift', 1)
        ->assertJsonPath('data.shops.0.id', $this->shop->ulid)->assertJsonPath('data.shops.0.stock_drift', 1)
        ->assertJsonPath('data.tasks.by_type.webhook.success', 1)
        ->assertJsonPath('data.permissions.operate', true)
        ->assertJsonPath('data.error_codes.rate_limited.auto_retry', true);
});

it('normalizes statuses and records a standard error code on failed pushes', function () {
    Cache::put("fake-marketplace:fail:{$this->shop->external_shop_id}", true);
    stockIn($this->tenant, $this->shirt, 5);

    expect(($this->outbox)()->error_code)->toBe(SyncErrorCode::API_UNAVAILABLE)->and(($this->outbox)()->last_attempt_at)->not->toBeNull();
    $this->api->getJson('/api/v1/sync-center/tasks?type=stock_push&status=retry_scheduled')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.error_code', 'api_unavailable')
        ->assertJsonPath('data.0.error_label', 'Sàn/website lỗi hoặc bảo trì')->assertJsonPath('data.0.can_cancel', true);
    $this->api->getJson('/api/v1/sync-center/tasks?error_code=api_unavailable')->assertOk()->assertJsonCount(1, 'data');
    $this->api->getJson('/api/v1/sync-center/tasks?type=order_poll&status=retry_scheduled')->assertOk()->assertJsonCount(0, 'data');
});

it('retries a dead-letter stock push with the current stock, not the old value', function () {
    // The marketplace was down for every attempt; meanwhile the stock went from 10 to 12.
    Cache::put("fake-marketplace:fail:{$this->shop->external_shop_id}", true);
    stockIn($this->tenant, $this->shirt, 2);
    InventorySyncOutbox::withoutGlobalScopes()->whereKey(($this->outbox)()->id)->update(['status' => InventorySyncOutbox::FAILED, 'desired_qty' => 3, 'attempts' => 8]);
    Cache::forget("fake-marketplace:fail:{$this->shop->external_shop_id}");
    $id = ($this->outbox)()->id;
    expect(($this->remote)())->toBe(10);

    $this->api->getJson('/api/v1/sync-center/tasks?status=dead_letter')->assertOk()->assertJsonPath('data.0.id', "stock_push:{$id}");
    $this->api->postJson("/api/v1/sync-center/tasks/stock_push/{$id}/retry")->assertOk()->assertJsonPath('data.status', 'success');

    expect(($this->remote)())->toBe(12)
        ->and(DB::table('audit_logs')->where('action', 'sync.task_retried')->exists())->toBeTrue();
});

it('cancels a waiting push and refuses to retry what has not failed', function () {
    Cache::put("fake-marketplace:fail:{$this->shop->external_shop_id}", true);
    stockIn($this->tenant, $this->shirt, 5);
    $id = ($this->outbox)()->id;

    $this->api->postJson("/api/v1/sync-center/tasks/stock_push/{$id}/retry")->assertUnprocessable();
    $this->api->postJson("/api/v1/sync-center/tasks/stock_push/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    Cache::forget("fake-marketplace:fail:{$this->shop->external_shop_id}");
    $this->travel(1)->hours();
    $this->artisan('ecso:marketplace:push-due');
    expect(($this->remote)())->toBe(10);
});

it('retries a failed webhook by reading the order again', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    $event = WebhookEvent::query()->where('external_order_id', $order->external_order_id)->firstOrFail();
    $event->forceFill(['status' => WebhookEvent::FAILED, 'error_code' => SyncErrorCode::NETWORK_TIMEOUT])->save();

    $this->api->postJson("/api/v1/sync-center/tasks/webhook/{$event->id}/retry")->assertOk()->assertJsonPath('data.status', 'success');
    expect($event->refresh()->status)->toBe(WebhookEvent::PROCESSED)->and($event->error_code)->toBeNull()
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->reserved)->toBe(2);
});

it('queues "sync now" and logs the pass with its correlation id', function () {
    $this->api->postJson("/api/v1/sync-center/accounts/{$this->shop->ulid}/sync-now")->assertStatus(202);

    $run = SyncRun::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->sole();
    expect($run->correlation_id)->not->toBeNull()->and($run->status)->toBe(SyncRun::OK);
    $this->api->getJson('/api/v1/sync-center/tasks?type=order_poll')->assertOk()->assertJsonPath('data.0.action', 'Đồng bộ thủ công');
});

it('exports the log for those allowed and audits it', function () {
    fakeOrder($this->shop, [[$this->shirt, 1]]);

    $response = $this->api->get('/api/v1/sync-center/tasks/export');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml')
        ->and(DB::table('audit_logs')->where('action', 'sync.logs_exported')->exists())->toBeTrue();
});

it('lets a viewer look but not operate, and hides the Sync Center from roles without sync.view', function () {
    $viewer = addMember($this->tenant, Role::VIEWER);
    $staff = addMember($this->tenant, Role::ORDER_STAFF);
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    $event = WebhookEvent::query()->where('external_order_id', $order->external_order_id)->firstOrFail();
    $event->forceFill(['status' => WebhookEvent::FAILED])->save();

    actingInTenant($viewer, $this->tenant)->getJson('/api/v1/sync-center/overview')->assertOk()->assertJsonPath('data.permissions.operate', false);
    actingInTenant($viewer, $this->tenant)->postJson("/api/v1/sync-center/tasks/webhook/{$event->id}/retry")->assertForbidden();
    actingInTenant($viewer, $this->tenant)->get('/api/v1/sync-center/tasks/export')->assertForbidden();
    actingInTenant($staff, $this->tenant)->getJson('/api/v1/sync-center/tasks')->assertForbidden();
});

it('never shows or touches another business\'s sync tasks', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    $event = WebhookEvent::query()->where('external_order_id', $order->external_order_id)->firstOrFail();
    $event->forceFill(['status' => WebhookEvent::FAILED])->save();
    [$other, $otherOwner] = tenantWithOwner('Shop B');
    $api = actingInTenant($otherOwner, $other);

    $api->getJson('/api/v1/sync-center/tasks')->assertOk()->assertJsonCount(0, 'data');
    $api->getJson('/api/v1/sync-center/overview')->assertOk()->assertJsonPath('data.accounts.total', 0)->assertJsonPath('data.tasks.by_status.success', 0);
    $api->postJson("/api/v1/sync-center/tasks/webhook/{$event->id}/retry")->assertNotFound();
    $api->getJson('/api/v1/sync-center/tasks/stock_push/'.($this->outbox)()->id)->assertNotFound();
});

it('groups failed tasks by error code and counts statuses for the queue chips', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 1]]);
    WebhookEvent::query()->where('external_order_id', $order->external_order_id)->update(['status' => WebhookEvent::FAILED, 'error_code' => SyncErrorCode::RATE_LIMITED]);
    InventorySyncOutbox::withoutGlobalScopes()->whereKey(($this->outbox)()->id)->update(['status' => InventorySyncOutbox::REJECTED, 'error_code' => SyncErrorCode::INVALID_DATA]);

    $this->api->getJson('/api/v1/sync-center/overview')->assertOk()
        ->assertJsonPath('data.problems.failed_by_code.rate_limited', 1)->assertJsonPath('data.problems.failed_by_code.invalid_data', 1);
    $this->api->getJson('/api/v1/sync-center/tasks?status=failed')->assertOk()
        ->assertJsonPath('meta.by_status.failed', 1)->assertJsonPath('meta.by_status.dead_letter', 1);
});

it('retries every failed task of one error code and skips what cannot run now', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    $event = WebhookEvent::query()->where('external_order_id', $order->external_order_id)->firstOrFail();
    $event->forceFill(['status' => WebhookEvent::FAILED, 'error_code' => SyncErrorCode::NETWORK_TIMEOUT])->save();
    InventorySyncOutbox::withoutGlobalScopes()->whereKey(($this->outbox)()->id)->update(['status' => InventorySyncOutbox::FAILED, 'error_code' => SyncErrorCode::INVALID_DATA]);

    $this->api->postJson('/api/v1/sync-center/tasks/retry-failed', ['error_code' => 'network_timeout'])->assertOk()
        ->assertJsonPath('data.retried', 1)->assertJsonPath('data.skipped', []);
    expect($event->refresh()->status)->toBe(WebhookEvent::PROCESSED)
        ->and(($this->outbox)()->status)->toBe(InventorySyncOutbox::FAILED) // other error code: untouched
        ->and(DB::table('audit_logs')->where('action', 'sync.tasks_retried')->exists())->toBeTrue();

    // Stock sync switched off for the shop: the push is skipped with the reason, not forced.
    $this->shop->forceFill(['settings' => array_merge($this->shop->settings ?? [], ['sync_stock' => false])])->save();
    $this->api->postJson('/api/v1/sync-center/tasks/retry-failed', ['error_code' => 'invalid_data'])->assertOk()
        ->assertJsonPath('data.retried', 0)->assertJsonPath('data.skipped.0.count', 1);
    expect(($this->outbox)()->status)->toBe(InventorySyncOutbox::FAILED);

    actingInTenant(addMember($this->tenant, Role::VIEWER), $this->tenant)->postJson('/api/v1/sync-center/tasks/retry-failed')->assertForbidden();
});
