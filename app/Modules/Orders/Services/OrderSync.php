<?php

namespace App\Modules\Orders\Services;

use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncCursor;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Fetches orders from the marketplace API and ingests them: one order (after a webhook or a user action) or every
 * order changed since the last poll (reconcile, to recover lost webhooks). Runs in the account's tenant context.
 */
class OrderSync
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly OrderIngestor $ingestor,
    ) {}

    /** @return array{result: string, order: Order} */
    public function syncOne(MarketplaceAccount $account, string $externalOrderId, string $source, ?int $webhookEventId = null, ?int $actorId = null): array
    {
        $data = $this->connectors->for($account->marketplace)->getOrder($account, $externalOrderId);

        return $this->ingestor->ingest($account, $data, $source, $webhookEventId, $actorId);
    }

    /**
     * Polls orders updated since the cursor (minus an overlap), then moves the cursor.
     *
     * @return array<string, int> result => count
     */
    public function syncRecent(MarketplaceAccount $account, ?CarbonImmutable $since = null): array
    {
        $connector = $this->connectors->for($account->marketplace);
        $cursor = SyncCursor::query()->firstOrCreate(['marketplace_account_id' => $account->id, 'resource' => 'orders']);
        $to = CarbonImmutable::now();
        $from = ($since ?? ($cursor->last_synced_at ? CarbonImmutable::parse($cursor->last_synced_at) : $to->subDays(3)))
            ->subMinutes(config('marketplaces.order_sync_overlap_minutes'));

        $counts = [];
        $page = null;
        do {
            $result = $connector->listOrders($account, $from, $to, $page);
            foreach ($result->orderIds as $orderId) {
                $outcome = $this->syncOne($account, $orderId, 'poll')['result'];
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
            $page = $result->nextCursor;
        } while ($page !== null);

        $cursor->forceFill(['last_synced_at' => $to])->save();
        $account->forceFill(['last_synced_at' => $to])->save();

        return $counts;
    }
}
