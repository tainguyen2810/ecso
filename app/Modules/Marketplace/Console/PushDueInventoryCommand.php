<?php

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Jobs\PushInventoryJob;
use App\Modules\Marketplace\Models\InventorySyncOutbox;
use Illuminate\Console\Command;

/** Scheduled every minute: dispatches a push job for each shop that has due outbox rows (retries, debounced). */
class PushDueInventoryCommand extends Command
{
    protected $signature = 'ecso:marketplace:push-due';

    protected $description = 'Đẩy tồn kho đang chờ (outbox) lên các sàn';

    public function handle(): int
    {
        $accounts = InventorySyncOutbox::query()->withoutGlobalScopes()
            ->where('status', InventorySyncOutbox::PENDING)->where('next_attempt_at', '<=', now())
            ->distinct()->pluck('marketplace_account_id');

        foreach ($accounts as $accountId) {
            PushInventoryJob::dispatch((int) $accountId);
        }

        $this->info("Đã xếp hàng {$accounts->count()} shop.");

        return self::SUCCESS;
    }
}
