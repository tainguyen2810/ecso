<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Concerns\RunsOnLongQueue;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * "Lấy lại đơn theo ngày": reads a past date range of one shop, in windows of at most 15 days (Shopee lists 15 days
 * per call), oldest first. Each window is its own sync run, so "Lịch sử lấy đơn" shows the progress. A window the
 * marketplace refuses ends the job: the windows before it stay, and running it again only updates them.
 */
class BackfillOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public const WINDOW_DAYS = 15;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $accountId,
        public readonly string $from,
        public readonly string $to,
        public readonly bool $withStock = false,
    ) {
        $this->onLongQueue();
    }

    public function uniqueId(): string
    {
        return 'backfill-'.$this->accountId;
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    public static function windows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $windows = [];
        for ($start = $from; $start->lessThan($to); $start = $end) {
            $end = $start->addDays(self::WINDOW_DAYS)->min($to);
            $windows[] = [$start, $end];
        }

        return $windows;
    }

    public function handle(OrderSync $sync): void
    {
        $account = MarketplaceAccount::query()->withoutGlobalScopes()->find($this->accountId);
        $tenant = $account ? Tenant::query()->find($account->tenant_id) : null;
        if (! $account || ! $tenant || ! $account->isActive()) {
            return;
        }

        TenantContext::run($tenant, function () use ($sync) {
            $account = MarketplaceAccount::query()->findOrFail($this->accountId);
            foreach (self::windows(CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to)) as [$from, $to]) {
                try {
                    $sync->syncWindow($account, $from, $to, $this->withStock);
                } catch (ConnectorException) {
                    return; // Logged on that window's sync run.
                }
            }
        });
    }
}
