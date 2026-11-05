<?php

namespace App\Modules\Orders\Services;

use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncCursor;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Marketplace\Services\SyncErrorLog;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Fetches orders from the marketplace API and ingests them: one order (after a webhook or a user action) or every
 * order changed since the last poll (reconcile, to recover lost webhooks). Runs in the account's tenant context.
 */
class OrderSync
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly OrderIngestor $ingestor,
        private readonly SyncErrorLog $errors,
    ) {}

    /**
     * Reads one order from the store and takes it in. A failure opens (or repeats) a sync error for the order; a
     * later successful read closes it. An order gone from the store is flagged in ECSO, never deleted.
     *
     * @return array{result: string, order: Order|null} order is null when the order is skipped
     */
    public function syncOne(MarketplaceAccount $account, string $externalOrderId, string $source, ?int $webhookEventId = null, ?int $actorId = null): array
    {
        try {
            $data = $this->connectors->for($account->marketplace)->getOrder($account, $externalOrderId);
        } catch (ConnectorException $e) {
            if ($e->notFound) {
                $this->errors->resolve($account, 'order', $externalOrderId);

                return $this->ingestor->markDeletedOnChannel($account, $externalOrderId, $source, $webhookEventId)
                    ?? ['result' => OrderIngestor::SKIPPED, 'order' => null];
            }
            $this->errors->fromException($account, 'order', $externalOrderId, $e);

            throw $e;
        }

        $result = $this->ingestor->ingest($account, $data, $source, $webhookEventId, $actorId);
        if ($result['result'] === OrderIngestor::IGNORED_TRANSITION) {
            $this->errors->record($account, 'order', $externalOrderId, SyncError::CONFLICT, (string) $result['order']?->sync_warning);
        } else {
            $this->errors->resolve($account, 'order', $externalOrderId);
        }

        return $result;
    }

    /**
     * Polls orders updated since the cursor (minus an overlap), then moves the cursor. Every pass is logged in
     * sync_runs. One order that cannot be read does not stop the others; after a temporary failure the cursor stays, so the
     * next pass reads the window again (ingesting is idempotent). A page that cannot be listed ends the pass.
     *
     * @return array<string, int> result => count
     */
    public function syncRecent(MarketplaceAccount $account, ?CarbonImmutable $since = null, string $trigger = 'schedule'): array
    {
        $connector = $this->connectors->for($account->marketplace);
        $cursor = SyncCursor::query()->firstOrCreate(['marketplace_account_id' => $account->id, 'resource' => 'orders']);
        $to = CarbonImmutable::now();
        $from = ($since ?? ($cursor->last_synced_at ? CarbonImmutable::parse($cursor->last_synced_at) : $to->subDays(3)))
            ->subMinutes(config('marketplaces.order_sync_overlap_minutes'));
        $run = SyncRun::query()->create([
            'marketplace_account_id' => $account->id, 'resource' => 'orders', 'trigger' => $trigger, 'status' => SyncRun::RUNNING,
            'window_from' => $from, 'window_to' => $to, 'started_at' => now(),
        ]);

        $counts = [];
        $page = null;
        $pages = 0;
        $lastError = null;
        $retry = false;
        try {
            do {
                $result = $connector->listOrders($account, $from, $to, $page);
                $pages++;
                foreach ($result->orderIds as $orderId) {
                    try {
                        $outcome = $this->syncOne($account, $orderId, 'poll')['result'];
                    } catch (ConnectorException $e) {
                        $outcome = 'failed';
                        $lastError = "Đơn {$orderId}: {$e->getMessage()}";
                        $retry = $retry || $e->temporary;
                    }
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                }
                $page = $result->nextCursor;
            } while ($page !== null);
        } catch (Throwable $e) {
            // Any failure (not only the API's) closes the pass, so it is never left "running".
            $message = $e instanceof ConnectorException ? $e->getMessage() : 'Lỗi hệ thống khi đồng bộ ('.class_basename($e).')';
            $run->forceFill(['status' => SyncRun::FAILED, 'pages' => $pages, 'counts' => $counts, 'error' => mb_substr($message, 0, 500), 'finished_at' => now()])->save();

            throw $e;
        }

        $failed = $counts['failed'] ?? 0;
        // A temporary failure (timeout, 429, 5xx) keeps the cursor so the next pass reads those orders again; a
        // permanent one (order gone) would fail forever, so it does not hold the cursor back.
        if (! $retry) {
            $cursor->forceFill(['last_synced_at' => $to])->save();
        }
        $account->forceFill(['last_synced_at' => $to])->save();
        $run->forceFill([
            'status' => $failed ? SyncRun::PARTIAL : SyncRun::OK, 'pages' => $pages, 'counts' => $counts,
            'error' => $lastError !== null ? mb_substr($lastError, 0, 500) : null, 'finished_at' => now(),
        ])->save();

        return $counts;
    }
}
