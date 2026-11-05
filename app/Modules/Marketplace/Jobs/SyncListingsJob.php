<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Concerns\RunsOnLongQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Imports every listing of a shop in the background (a store can have thousands of products). */
class SyncListingsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $accountId)
    {
        $this->onLongQueue();
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ListingService $listings): void
    {
        $account = MarketplaceAccount::query()->withoutGlobalScopes()->find($this->accountId);
        if (! $account || ! $account->isActive()) {
            return;
        }

        TenantContext::run(Tenant::query()->findOrFail($account->tenant_id), function () use ($listings) {
            $account = MarketplaceAccount::query()->findOrFail($this->accountId);
            try {
                $listings->sync($account);
            } catch (ConnectorException $e) {
                $account->forceFill(['last_error' => mb_substr('Lấy sản phẩm: '.$e->getMessage(), 0, 500)])->save();
                if ($e->temporary) {
                    throw $e;
                }
            }
        });
    }
}
