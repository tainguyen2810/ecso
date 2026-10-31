<?php

use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockVerifier;
use Symfony\Component\Process\Process;

/**
 * Real multi-process races against MySQL: each worker is a separate PHP process with its own connection.
 */
function runWorkers(array $jobs): array
{
    $startAt = microtime(true) + 2.0;
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')];

    $processes = array_map(function (array $job) use ($startAt, $env) {
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', json_encode($job + ['start_at' => $startAt])], base_path(), $env, null, 60);
        $process->start();

        return $process;
    }, $jobs);

    return array_map(function (Process $p) {
        $p->wait();
        $line = trim(collect(explode("\n", trim($p->getOutput())))->last() ?? '');

        return json_decode($line, true) ?? ['ok' => false, 'error' => 'no-output', 'message' => $p->getErrorOutput()];
    }, $processes);
}

function stockFixture(int $onHand): array
{
    [$tenant] = tenantWithOwner();
    inTenant($tenant);
    $warehouse = defaultWarehouse($tenant);
    $variant = makeVariant($tenant, 'HOT-1');
    app(InventoryService::class)->receive($warehouse, [['variant_id' => $variant->id, 'quantity' => $onHand, 'unit_cost' => 1]], 'fixture');

    return [$tenant, $warehouse, $variant];
}

it('lets 20 concurrent orders reserve a SKU with 10 in stock without over-reserving', function () {
    [$tenant, $warehouse, $variant] = stockFixture(10);

    $results = runWorkers(array_map(fn ($i) => [
        'op' => 'reserve', 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'variant_id' => $variant->id,
        'quantity' => 1, 'reference_id' => 1000 + $i, 'key' => "order-{$i}",
    ], range(1, 20)));

    expect(collect($results)->where('ok', false)->all())->toBe([]);
    expect(collect($results)->sum('reserved'))->toBe(10)
        ->and(collect($results)->sum('shortage'))->toBe(10);

    $balance = balanceOf($warehouse, $variant);
    expect($balance->on_hand)->toBe(10)->and($balance->reserved)->toBe(10)
        ->and(StockReservation::query()->count())->toBe(20)
        ->and(app(StockVerifier::class)->verify($tenant->id))->toBe([]);
});

it('processes the same webhook delivered 10 times concurrently exactly once', function () {
    [$tenant, $warehouse, $variant] = stockFixture(10);

    $results = runWorkers(array_fill(0, 10, [
        'op' => 'reserve', 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'variant_id' => $variant->id,
        'quantity' => 3, 'reference_id' => 777, 'key' => 'webhook-event-777',
    ]));

    expect(collect($results)->where('ok', false)->all())->toBe([])
        ->and(collect($results)->pluck('reservation_id')->unique())->toHaveCount(1);
    expect(balanceOf($warehouse, $variant)->reserved)->toBe(3)
        ->and(StockReservation::query()->count())->toBe(1)
        ->and(app(StockVerifier::class)->verify($tenant->id))->toBe([]);
});

it('does not deadlock when documents lock the same SKUs in opposite order', function () {
    [$tenant, $warehouse, $a] = stockFixture(100);
    $b = makeVariant($tenant, 'HOT-2');
    app(InventoryService::class)->receive($warehouse, [['variant_id' => $b->id, 'quantity' => 100, 'unit_cost' => 1]], 'fixture-b');

    $jobs = [];
    foreach (range(1, 16) as $i) {
        $lines = [['variant_id' => $a->id, 'quantity' => 1], ['variant_id' => $b->id, 'quantity' => 1]];
        $jobs[] = [
            'op' => $i % 2 ? 'issue' : 'receive', 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id,
            'lines' => $i % 4 < 2 ? $lines : array_reverse($lines), 'key' => "doc-{$i}",
        ];
        if ($jobs[array_key_last($jobs)]['op'] === 'receive') {
            $jobs[array_key_last($jobs)]['lines'] = array_map(fn ($l) => $l + ['unit_cost' => 1], $jobs[array_key_last($jobs)]['lines']);
        }
    }

    $results = runWorkers($jobs);

    expect(collect($results)->where('ok', false)->all())->toBe([]);
    // 8 issues (−1 each) and 8 receipts (+1 each) per SKU.
    expect(balanceOf($warehouse, $a)->on_hand)->toBe(100)
        ->and(balanceOf($warehouse, $b)->on_hand)->toBe(100)
        ->and(app(StockVerifier::class)->verify($tenant->id))->toBe([]);
});

it('never lets concurrent issues take more than available', function () {
    [$tenant, $warehouse, $variant] = stockFixture(5);

    $results = runWorkers(array_map(fn ($i) => [
        'op' => 'issue', 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id,
        'lines' => [['variant_id' => $variant->id, 'quantity' => 2]], 'key' => "issue-{$i}",
    ], range(1, 12)));

    expect(collect($results)->where('ok', true)->count())->toBe(2)
        ->and(collect($results)->where('error', 'InsufficientStock')->count())->toBe(10)
        ->and(balanceOf($warehouse, $variant)->on_hand)->toBe(1)
        ->and(app(StockVerifier::class)->verify($tenant->id))->toBe([]);
});
