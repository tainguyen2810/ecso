<?php

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Concerns\RunsOnLongQueue;
use Database\Seeders\Demo\DemoHistory;
use Database\Seeders\DemoSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * A2: fills a user's sample store with the demo catalog, stock, customers, suppliers, purchases and
 * `ecso.demo_store_days` (60) days of own-channel sales (the same data as `ecso:demo-tenant`), then marks it ready.
 */
class PopulateDemoStore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnLongQueue;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $tenantId, public readonly int $userId)
    {
        $this->onLongQueue();
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $owner = User::query()->find($this->userId);
        if (! $tenant || ! $owner || ! $tenant->is_demo || $tenant->demo_status !== 'preparing') {
            return;
        }

        $days = max(7, (int) config('ecso.demo_store_days', 60));
        $realNow = Carbon::now();
        // Catalog, opening stock and the fixed sample documents are dated at the start of the history.
        Carbon::setTestNow($realNow->copy()->subDays($days + 1)->setTime(9, 0));
        try {
            Auth::setUser($owner);
            app(DemoSeeder::class)->populate($tenant, $owner, null, paidPlan: false);
        } finally {
            Carbon::setTestNow();
        }

        try {
            Auth::setUser($owner);
            TenantContext::run($tenant, fn () => app(DemoHistory::class)->run($days));
        } finally {
            Carbon::setTestNow();
        }

        $tenant->forceFill(['demo_status' => 'ready'])->save();
    }

    public function failed(?Throwable $e): void
    {
        Tenant::query()->whereKey($this->tenantId)->where('is_demo', true)->update(['demo_status' => 'failed']);
    }
}
