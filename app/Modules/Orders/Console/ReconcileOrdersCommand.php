<?php

namespace App\Modules\Orders\Console;

use App\Modules\Marketplace\Connectors\Capability;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

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
            if (! $connectors->has($account->marketplace)
                || ($connectors->for($account->marketplace)->capabilities()[Capability::ORDERS_LIST] ?? null) !== Capability::SUPPORTED) {
                continue;
            }

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
}
