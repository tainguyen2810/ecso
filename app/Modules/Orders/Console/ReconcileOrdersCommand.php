<?php

namespace App\Modules\Orders\Console;

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\SyncRun;
use App\Modules\Orders\Jobs\ReconcileShopJob;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Polls recently changed orders of every active shop, to recover webhooks that never arrived. */
class ReconcileOrdersCommand extends Command
{
    protected $signature = 'ecso:orders:reconcile {--account= : ULID of one shop}';

    protected $description = 'Đối soát đơn hàng với sàn (bù webhook bị mất)';

    public function handle(ConnectorRegistry $connectors, OrderSync $sync): int
    {
        $accounts = MarketplaceAccount::query()->withoutGlobalScopes()
            ->where('status', MarketplaceAccount::CONNECTED)->where('sync_enabled', true)
            ->when($this->option('account'), fn ($q, $ulid) => $q->where('ulid', $ulid))->get();

        foreach ($accounts as $account) {
            if (! $account->syncs('sync_orders') || ! $connectors->has($account->marketplace)
                || ($connectors->for($account->marketplace)->capabilities()[Capability::ORDERS_LIST] ?? null) !== Capability::SUPPORTED) {
                continue;
            }

            // Runs every 5 minutes; each shop is read at its own interval (5, 15 or 30 minutes, set on the store page).
            if (! $this->option('account') && ! $this->due($account)) {
                continue;
            }

            // Each shop is polled in its own queued job; one slow store never delays the others.
            if (! $this->option('account')) {
                ReconcileShopJob::dispatch($account->id);
                $this->line("{$account->shop_name}: đã xếp hàng");

                continue;
            }

            // One shop asked for by hand: run it here and show the counts.
            $tenant = Tenant::query()->find($account->tenant_id);
            try {
                $counts = TenantContext::run($tenant, fn () => $sync->syncRecent(MarketplaceAccount::query()->findOrFail($account->id)));
                $this->line("{$account->shop_name}: ".json_encode($counts));
            } catch (ConnectorException $e) {
                $this->warn("{$account->shop_name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function due(MarketplaceAccount $account): bool
    {
        $last = SyncRun::query()->withoutGlobalScopes()->where('marketplace_account_id', $account->id)
            ->where('resource', 'orders')->where('trigger', 'schedule')->max('started_at');

        // A minute of slack so a pass that started a little late does not skip a whole interval.
        return $last === null || Carbon::parse($last)->addMinutes($account->orderSyncInterval() - 1)->lessThanOrEqualTo(now());
    }
}
