<?php

use App\Modules\Marketplace\Jobs\PushInventoryJob;
use App\Modules\Marketplace\Jobs\SyncListingsJob;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Marketplace\Services\SyncHealth;
use App\Modules\Orders\Jobs\ReconcileShopJob;
use App\Modules\Sales\Jobs\ImportCustomersJob;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Sync Center round 0: scheduler locks, long jobs, unlinked listings, webhook bodies, stuck work, pruning. */
beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->shirt = makeVariant($this->tenant, 'AO-001', ['product_name' => 'Áo thun', 'ref_price' => 150000]);
    stockIn($this->tenant, $this->shirt, 10);
    $this->shop = fakeShop($this->tenant);
    $this->listing = ListingVariant::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->where('seller_sku', 'AO-001')->sole();
    $this->remote = fn () => (int) DB::table('fake_marketplace_products')->where('shop_id', $this->shop->external_shop_id)->where('seller_sku', 'AO-001')->value('stock');
});

it('gives every overlap lock of the scheduler an expiry well under a day', function () {
    $locked = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->withoutOverlapping);

    expect($locked)->not->toBeEmpty();
    foreach ($locked as $event) {
        expect($event->expiresAt)->toBeLessThanOrEqual(120, (string) $event->command);
    }
});

it('runs long jobs on their own connection whose retry window outlasts them', function () {
    config(['queue.default' => 'redis']);
    $jobs = [new SyncListingsJob(1), new ReconcileShopJob(1), new ImportCustomersJob(1, ImportCustomersJob::CHECK)];

    foreach ($jobs as $job) {
        expect($job->connection)->toBe('redis-long')->and($job->queue)->toBe('sync-long')
            ->and(config('queue.connections.redis-long.retry_after'))->toBeGreaterThan($job->timeout);
    }
    expect(config('horizon.defaults.supervisor-long.queue'))->toBe(['sync-long'])
        ->and(config('horizon.defaults.supervisor-long.timeout'))->toBeGreaterThanOrEqual(1800);
});

it('never pushes the stock of a SKU after its listing was unlinked', function () {
    expect(($this->remote)())->toBe(10);

    // The marketplace is down: the new stock waits in the outbox.
    Cache::put("fake-marketplace:fail:{$this->shop->external_shop_id}", true);
    stockIn($this->tenant, $this->shirt, 5);
    $row = InventorySyncOutbox::withoutGlobalScopes()->where('listing_variant_id', $this->listing->id)->sole();
    expect($row->status)->toBe(InventorySyncOutbox::PENDING);

    actingInTenant($this->owner, $this->tenant)->deleteJson("/api/v1/listings/{$this->listing->ulid}/mapping")->assertOk();
    expect($row->refresh()->status)->toBe(InventorySyncOutbox::CANCELLED);

    Cache::forget("fake-marketplace:fail:{$this->shop->external_shop_id}");
    $this->travel(1)->hours();
    $this->artisan('ecso:marketplace:push-due')->assertSuccessful();
    expect(($this->remote)())->toBe(10);
});

it('drops a queued push whose listing lost its SKU before the job ran', function () {
    InventorySyncOutbox::withoutGlobalScopes()->where('listing_variant_id', $this->listing->id)
        ->update(['status' => InventorySyncOutbox::PENDING, 'desired_qty' => 99, 'next_attempt_at' => now()->subMinute()]);
    $this->listing->forceFill(['mapping_status' => ListingVariant::UNMAPPED, 'product_variant_id' => null])->save();

    PushInventoryJob::dispatchSync($this->shop->id);

    expect(($this->remote)())->toBe(10)
        ->and(InventorySyncOutbox::withoutGlobalScopes()->where('listing_variant_id', $this->listing->id)->value('status'))->toBe(InventorySyncOutbox::CANCELLED);
});

it('does not keep the body of a webhook', function () {
    fakeOrder($this->shop, [[$this->shirt, 1]]);

    $events = WebhookEvent::query()->where('marketplace', 'fake')->get();
    expect($events)->not->toBeEmpty()->and($events->pluck('payload')->filter())->toBeEmpty()
        ->and($events->first()->payload_hash)->not->toBeEmpty();
});

it('closes passes left running and processes webhooks whose job was lost', function () {
    $order = fakeOrder($this->shop, [[$this->shirt, 2]]);
    $event = WebhookEvent::query()->where('external_order_id', $order->external_order_id)->firstOrFail();
    // The job was lost: the event is still "received" an hour and a half later.
    $event->forceFill(['status' => WebhookEvent::RECEIVED, 'processed_at' => null, 'received_at' => now()->subMinutes(90)])->save();
    $stuck = TenantContext::run($this->tenant, fn () => SyncRun::query()->create([
        'marketplace_account_id' => $this->shop->id, 'resource' => 'orders', 'trigger' => 'schedule', 'status' => SyncRun::RUNNING,
        'started_at' => now()->subHour(),
    ]));
    $fresh = TenantContext::run($this->tenant, fn () => SyncRun::query()->create([
        'marketplace_account_id' => $this->shop->id, 'resource' => 'orders', 'trigger' => 'user', 'status' => SyncRun::RUNNING,
        'started_at' => now()->subMinutes(5),
    ]));

    $this->artisan('ecso:sync:recover')->assertSuccessful();

    expect(SyncRun::withoutGlobalScopes()->find($stuck->id)->status)->toBe(SyncRun::FAILED)
        ->and(SyncRun::withoutGlobalScopes()->find($stuck->id)->finished_at)->not->toBeNull()
        ->and(SyncRun::withoutGlobalScopes()->find($fresh->id)->status)->toBe(SyncRun::RUNNING)
        ->and($event->refresh()->status)->toBe(WebhookEvent::PROCESSED)
        // Processing it again changed nothing: the order still holds its two units once.
        ->and(balanceOf(defaultWarehouse($this->tenant), $this->shirt)->reserved)->toBe(2);
});

it('drops sync logs older than 30 days but keeps open errors', function () {
    fakeOrder($this->shop, [[$this->shirt, 1]]);
    WebhookEvent::query()->update(['received_at' => now()->subDays(31)]);
    [$oldRun, $newRun, $openError, $closedError] = TenantContext::run($this->tenant, fn () => [
        SyncRun::query()->create(['marketplace_account_id' => $this->shop->id, 'resource' => 'orders', 'trigger' => 'schedule', 'status' => SyncRun::OK, 'started_at' => now()->subDays(40)]),
        SyncRun::query()->create(['marketplace_account_id' => $this->shop->id, 'resource' => 'orders', 'trigger' => 'schedule', 'status' => SyncRun::OK, 'started_at' => now()->subDays(2)]),
        SyncError::query()->create(['marketplace_account_id' => $this->shop->id, 'resource' => 'order', 'external_id' => 'A', 'category' => SyncError::API, 'message' => 'x', 'first_seen_at' => now()->subDays(60), 'last_seen_at' => now()->subDays(60)]),
        SyncError::query()->create(['marketplace_account_id' => $this->shop->id, 'resource' => 'order', 'external_id' => 'B', 'category' => SyncError::API, 'message' => 'x', 'first_seen_at' => now()->subDays(60), 'last_seen_at' => now()->subDays(60), 'resolved_at' => now()->subDays(45)]),
    ]);

    $this->artisan('ecso:sync:prune')->assertSuccessful();

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(SyncRun::withoutGlobalScopes()->whereKey($oldRun->id)->exists())->toBeFalse()
        ->and(SyncRun::withoutGlobalScopes()->whereKey($newRun->id)->exists())->toBeTrue()
        ->and(SyncError::withoutGlobalScopes()->whereKey($openError->id)->exists())->toBeTrue()
        ->and(SyncError::withoutGlobalScopes()->whereKey($closedError->id)->exists())->toBeFalse();
});

it('polls each shop in its own queued job', function () {
    Queue::fake();
    $this->artisan('ecso:orders:reconcile')->assertSuccessful();

    Queue::assertPushed(ReconcileShopJob::class, fn ($job) => $job->accountId === $this->shop->id);
});

it('flags a shop whose order poll has stopped', function () {
    $health = app(SyncHealth::class);
    expect($health->overdueReconciles())->toBe(0);

    $this->travel(2)->hours();
    expect($health->overdueReconciles())->toBe(1);

    $this->artisan('ecso:orders:reconcile')->assertSuccessful();
    expect($health->overdueReconciles())->toBe(0);
});

it('publishes the current stock again when stock sync is turned back on, and audits the switch', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->patchJson("/api/v1/marketplace-accounts/{$this->shop->ulid}", ['settings' => ['sync_stock' => false]])->assertOk();
    stockIn($this->tenant, $this->shirt, 5);
    expect(($this->remote)())->toBe(10);

    $api->patchJson("/api/v1/marketplace-accounts/{$this->shop->ulid}", ['settings' => ['sync_stock' => true]])->assertOk();
    expect(($this->remote)())->toBe(15)
        ->and(DB::table('audit_logs')->where('action', 'marketplace.updated')->latest('id')->value('changes'))->toContain('sync_stock');
});
