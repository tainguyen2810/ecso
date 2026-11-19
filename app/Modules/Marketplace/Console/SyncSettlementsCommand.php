<?php

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\FetchesSettlement;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\SettlementSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/** Đợt 5: reads "tiền thực nhận" (settlement) of delivered and completed marketplace orders from the marketplaces. */
class SyncSettlementsCommand extends Command
{
    protected $signature = 'ecso:marketplace:settlements {--tenant= : Only this tenant id} {--limit=100 : Orders per shop per pass}';

    protected $description = 'Lấy tiền thực nhận (phí sàn, đối soát) của đơn sàn đã giao.';

    public function handle(ConnectorRegistry $connectors): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            TenantContext::run($tenant, function () use ($connectors) {
                $accounts = MarketplaceAccount::query()->where('status', MarketplaceAccount::CONNECTED)->get()
                    ->filter(fn (MarketplaceAccount $a) => $connectors->has($a->marketplace) && $connectors->for($a->marketplace) instanceof FetchesSettlement && ! $a->circuitOpen());
                foreach ($accounts as $account) {
                    try {
                        $c = app(SettlementSync::class)->syncAccount($account, (int) $this->option('limit'));
                        if ($c['read'] > 0) {
                            $this->line("{$account->shop_name}: {$c['read']} đơn, có số liệu {$c['found']}, lỗi {$c['failed']}");
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
