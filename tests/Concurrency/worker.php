<?php

/**
 * Separate PHP process used by the concurrency tests. Boots the app against the test database,
 * waits for a shared start time (to maximise contention) and runs one inventory operation.
 * Prints a JSON result line. Usage: php worker.php '<json payload>'
 */

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

while (microtime(true) < $job['start_at']) {
    usleep(500);
}

try {
    $tenant = Tenant::query()->findOrFail($job['tenant_id']);
    app(TenantContext::class)->set($tenant);
    $inventory = app(InventoryService::class);
    $warehouse = Warehouse::query()->findOrFail($job['warehouse_id']);

    $result = match ($job['op']) {
        'reserve' => (function () use ($inventory, $warehouse, $job) {
            $r = $inventory->reserve($warehouse, ProductVariant::query()->findOrFail($job['variant_id']), $job['quantity'], 'order_item', $job['reference_id'], $job['key']);

            return ['reserved' => $r->quantity, 'shortage' => $r->shortage, 'reservation_id' => $r->id];
        })(),
        'issue' => (function () use ($inventory, $warehouse, $job) {
            $res = $inventory->issue($warehouse, $job['lines'], $job['key'], null, StockMovement::DAMAGE_OUT);

            return ['applied' => $res->applied];
        })(),
        // Delivers fake-marketplace webhooks through the full pipeline (inbox, job, API fetch, ingest).
        'webhooks' => (function () use ($job) {
            $fake = app(FakeMarketplace::class);
            $failed = 0;
            foreach ($job['orders'] as $orderSn) {
                $r = $fake->notify($orderSn, $job['event_prefix'].$orderSn);
                $failed += $r['event_id'] === null ? 1 : 0;
            }

            return ['delivered' => count($job['orders']), 'failed' => $failed];
        })(),
        'receive' => (function () use ($inventory, $warehouse, $job) {
            $res = $inventory->receive($warehouse, $job['lines'], $job['key']);

            return ['applied' => $res->applied];
        })(),
    };

    echo json_encode(['ok' => true] + $result), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e), 'message' => $e->getMessage()]), PHP_EOL;
}
