<?php

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\StockVerifier;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\Support\TenantContext;
use Symfony\Component\Process\Process;

/**
 * ROADMAP Phase 3 acceptance: 1,000 orders arriving concurrently on 50 SKUs never oversell.
 * 10 worker processes deliver webhooks through the full pipeline; every order is also delivered a second time by
 * another worker (different event id) to race duplicates.
 */
function runWebhookWorkers(array $jobs): array
{
    $startAt = microtime(true) + 3.0;
    $env = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'),
        'QUEUE_CONNECTION' => 'sync', 'FAKE_MARKETPLACE_ENABLED' => 'true',
    ];

    $processes = array_map(function (array $job) use ($startAt, $env) {
        $p = new Process([PHP_BINARY, __DIR__.'/worker.php', json_encode($job + ['start_at' => $startAt])], base_path(), $env, null, 600);
        $p->start();

        return $p;
    }, $jobs);

    return array_map(function (Process $p) {
        $p->wait();
        $line = trim(collect(explode("\n", trim($p->getOutput())))->last() ?? '');

        return json_decode($line, true) ?? ['ok' => false, 'error' => 'no-output', 'message' => mb_substr($p->getErrorOutput().$p->getOutput(), 0, 2000)];
    }, $processes);
}

it('ingests 1,000 concurrent orders on 50 SKUs without overselling', function () {
    [$tenant] = tenantWithOwner();
    TenantContext::run($tenant, fn () => Subscription::query()
        ->update(['plan_id' => Plan::query()->where('key', 'business')->value('id')]));
    $warehouse = defaultWarehouse($tenant);

    $variants = collect(range(1, 50))->map(fn ($i) => makeVariant($tenant, sprintf('SKU-%03d', $i), ['ref_price' => 100000]));
    foreach ($variants as $v) {
        stockIn($tenant, $v, 15); // 750 units for ~2,250 demanded: most SKUs run out
    }
    $shop = fakeShop($tenant, map: false);
    $fake = app(FakeMarketplace::class);
    $fake->putProducts($shop->external_shop_id, $variants->map(fn ($v) => [
        'product_id' => 'CP'.$v->id, 'sku_id' => 'CS'.$v->id, 'seller_sku' => $v->sku, 'title' => 'SP '.$v->sku, 'variant_name' => null, 'price' => 100000,
    ])->all());
    TenantContext::run($tenant, function () use ($shop) {
        app(ListingService::class)->sync($shop);
        app(ListingService::class)->confirmSuggestions($shop);
    });
    $skuIds = $variants->map(fn ($v) => 'CS'.$v->id)->all();

    $orders = [];
    mt_srand(42);
    for ($i = 0; $i < 1000; $i++) {
        $lines = [];
        foreach ((array) array_rand($skuIds, mt_rand(1, 2)) as $k) {
            $lines[] = ['sku_id' => $skuIds[$k], 'quantity' => mt_rand(1, 2)];
        }
        $orders[] = $fake->createOrder($shop->external_shop_id, $lines, 'fake_ready', ['order_sn' => sprintf('CC%05d', $i)], notify: false)['order_sn'];
    }

    $slices = array_chunk($orders, 100);
    $jobs = [];
    foreach ($slices as $w => $slice) {
        $jobs[] = ['op' => 'webhooks', 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'orders' => $slice, 'event_prefix' => 'a-'];
        // Duplicate deliveries of the next worker's orders, racing the first delivery.
        $jobs[] = ['op' => 'webhooks', 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'orders' => $slices[($w + 1) % count($slices)], 'event_prefix' => 'b-'];
    }

    $started = microtime(true);
    $results = runWebhookWorkers($jobs);
    $elapsed = round(microtime(true) - $started, 1);
    fwrite(STDERR, "\n  1,000 orders × 2 deliveries by 20 processes in {$elapsed}s\n");

    expect(collect($results)->where('ok', false)->all())->toBe([])
        ->and(collect($results)->sum('failed'))->toBe(0);

    inTenant($tenant);
    expect(Order::query()->count())->toBe(1000)
        ->and(WebhookEvent::query()->where('status', '!=', WebhookEvent::PROCESSED)->count())->toBe(0);

    // Never oversold: per SKU, reserved <= on hand, and nothing went negative.
    foreach (StockBalance::query()->get() as $balance) {
        expect($balance->reserved)->toBeLessThanOrEqual($balance->on_hand)->and($balance->on_hand)->toBe(15);
    }
    // One reservation per order item; held + shortage = ordered quantity.
    $items = OrderItem::query()->get()->keyBy('id');
    $reservations = StockReservation::query()->where('reference_type', 'order_item')->get();
    expect($reservations->count())->toBe($items->count())
        ->and($reservations->sum(fn ($r) => $r->quantity + $r->shortage))->toBe($items->sum('quantity'))
        ->and($reservations->sum('quantity'))->toBe((int) StockBalance::query()->sum('reserved'))
        ->and(app(StockVerifier::class)->verify($tenant->id))->toBe([]);
})->group('concurrency');
