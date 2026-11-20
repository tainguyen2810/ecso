<?php

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Jobs\StockCheckJob;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\StockReconciler;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/** Đợt 16: every night, each shop's stock on the marketplace is compared with ECSO's and set right. */
class StockCheckCommand extends Command
{
    protected $signature = 'ecso:marketplace:stock-check {--tenant= : Only this tenant id}';

    protected $description = 'Đối soát tồn trên sàn với tồn trong phần mềm, báo và sửa chỗ lệch.';

    public function handle(): int
    {
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        $queued = 0;
        foreach ($tenants as $tenant) {
            TenantContext::run($tenant, function () use (&$queued) {
                foreach (MarketplaceAccount::query()->where('status', MarketplaceAccount::CONNECTED)->get() as $account) {
                    if (StockReconciler::eligible($account)) {
                        StockCheckJob::dispatch($account->id);
                        $queued++;
                    }
                }
            });
        }
        $this->line("{$queued} shop đang đối soát tồn.");

        return self::SUCCESS;
    }
}
