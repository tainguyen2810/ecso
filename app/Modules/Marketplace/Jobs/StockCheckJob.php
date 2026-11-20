<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\StockReconciler;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Concerns\RunsOnLongQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Đợt 16: "Đối soát tồn với sàn" for one shop (reads every listing again, so it runs on the long queue). */
class StockCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $accountId, public readonly string $trigger = 'nightly')
    {
        $this->onLongQueue();
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(StockReconciler $reconciler): void
    {
        $account = MarketplaceAccount::query()->withoutGlobalScopes()->find($this->accountId);
        $tenant = $account ? Tenant::query()->find($account->tenant_id) : null;
        if (! $account || ! $tenant) {
            return;
        }
        TenantContext::run($tenant, function () use ($reconciler) {
            $account = MarketplaceAccount::query()->findOrFail($this->accountId);
            if (StockReconciler::eligible($account)) {
                $reconciler->run($account, $this->trigger);
            }
        });
    }
}
