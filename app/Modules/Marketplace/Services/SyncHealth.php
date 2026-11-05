<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncError;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Marketplace\Models\WebhookEvent;
use App\Modules\Orders\Jobs\ProcessWebhookEvent;
use Illuminate\Support\Carbon;

/**
 * Housekeeping of the sync machinery, across tenants (scheduler / monitoring only; no tenant context).
 * It never syncs anything itself: it closes what a dead worker left open and re-queues work that was lost.
 */
class SyncHealth
{
    /** A pass still "running" after this long lost its worker (ReconcileShopJob times out after 15 minutes). */
    public const STUCK_RUN_MINUTES = 30;

    /** A webhook still "received" after this long lost its job (its retries span about 45 minutes). */
    public const LOST_WEBHOOK_MINUTES = 60;

    /** Lost webhooks older than this are left alone: the order poll has long caught up. */
    public const LOST_WEBHOOK_MAX_DAYS = 3;

    public const KEEP_DAYS = 30;

    public function __construct(private readonly ConnectorRegistry $connectors) {}

    /** @return int passes closed as failed */
    public function closeStuckRuns(): int
    {
        return SyncRun::query()->withoutGlobalScopes()->where('status', SyncRun::RUNNING)
            ->where('started_at', '<', now()->subMinutes(self::STUCK_RUN_MINUTES))
            ->update(['status' => SyncRun::FAILED, 'error' => 'Lượt đồng bộ bị dừng giữa chừng (tiến trình xử lý đã dừng).', 'finished_at' => now()]);
    }

    /** @return int webhook events queued again */
    public function requeueLostWebhooks(int $limit = 500): int
    {
        $ids = WebhookEvent::query()->where('status', WebhookEvent::RECEIVED)
            ->where('received_at', '<', now()->subMinutes(self::LOST_WEBHOOK_MINUTES))
            ->where('received_at', '>=', now()->subDays(self::LOST_WEBHOOK_MAX_DAYS))
            ->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            ProcessWebhookEvent::dispatch((int) $id);
        }

        return $ids->count();
    }

    /**
     * Drops webhook events and sync passes older than KEEP_DAYS, in batches. Sync errors are kept while open.
     *
     * @return array{webhook_events: int, sync_runs: int, sync_errors: int}
     */
    public function prune(): array
    {
        $before = now()->subDays(self::KEEP_DAYS);
        $deleted = ['webhook_events' => 0, 'sync_runs' => 0, 'sync_errors' => 0];
        $batches = [
            'webhook_events' => fn () => WebhookEvent::query()->where('received_at', '<', $before),
            'sync_runs' => fn () => SyncRun::query()->withoutGlobalScopes()->where('started_at', '<', $before)->where('status', '!=', SyncRun::RUNNING),
            'sync_errors' => fn () => SyncError::query()->withoutGlobalScopes()->whereNotNull('resolved_at')->where('resolved_at', '<', $before),
        ];
        foreach ($batches as $table => $query) {
            do {
                $ids = $query()->orderBy('id')->limit(1000)->pluck('id');
                $n = $ids->isEmpty() ? 0 : $query()->whereIn('id', $ids)->delete();
                $deleted[$table] += $n;
            } while ($ids->count() === 1000);
        }

        return $deleted;
    }

    /**
     * Shops whose scheduled order poll has not started for three of their intervals (at least 30 minutes): webhooks
     * lost in that time are not being recovered.
     */
    public function overdueReconciles(): int
    {
        $accounts = MarketplaceAccount::query()->withoutGlobalScopes()
            ->where('status', MarketplaceAccount::CONNECTED)->where('sync_enabled', true)->get();

        return $accounts->filter(function (MarketplaceAccount $account) {
            if (! $account->syncs('sync_orders') || ! $this->connectors->has($account->marketplace)
                || ($this->connectors->for($account->marketplace)->capabilities()[Capability::ORDERS_LIST] ?? null) !== Capability::SUPPORTED) {
                return false;
            }
            $limit = now()->subMinutes(max(30, 3 * $account->orderSyncInterval()));
            $last = SyncRun::query()->withoutGlobalScopes()->where('marketplace_account_id', $account->id)
                ->where('resource', 'orders')->where('trigger', 'schedule')->max('started_at');

            return $last === null
                ? ($account->connected_at === null || $account->connected_at->lt($limit))
                : Carbon::parse($last)->lt($limit);
        })->count();
    }
}
