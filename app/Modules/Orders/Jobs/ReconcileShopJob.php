<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Concerns\RunsOnLongQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One scheduled order poll of one shop (OrderSync::syncRecent). Shops are polled in their own jobs so a slow or
 * unreachable store never holds up the others, and the scheduler only dispatches. One job per shop at a time.
 * A failed pass is already logged in sync_runs and keeps the cursor, so it is not retried here: the next pass
 * reads the same window again.
 */
class ReconcileShopJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1800;

    /** @param  string  $trigger  'schedule' (ecso:orders:reconcile) or 'user' (Đồng bộ ngay) */
    public function __construct(public readonly int $accountId, public readonly string $trigger = 'schedule')
    {
        $this->onLongQueue();
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(OrderSync $sync): void
    {
        $account = MarketplaceAccount::query()->withoutGlobalScopes()->find($this->accountId);
        if (! $account || ! $account->isActive() || ! $account->sync_enabled || ! $account->syncs('sync_orders')) {
            return;
        }
        $tenant = Tenant::query()->find($account->tenant_id);
        if (! $tenant) {
            return;
        }

        try {
            TenantContext::run($tenant, fn () => $sync->syncRecent(MarketplaceAccount::query()->findOrFail($account->id), trigger: $this->trigger));
        } catch (ConnectorException) {
            // Logged on the sync run; the next pass tries again.
        }
    }
}
