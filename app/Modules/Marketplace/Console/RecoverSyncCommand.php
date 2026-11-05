<?php

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Services\SyncHealth;
use Illuminate\Console\Command;

/** Scheduled every 10 minutes: closes sync passes left running and re-queues webhook events whose job was lost. */
class RecoverSyncCommand extends Command
{
    protected $signature = 'ecso:sync:recover';

    protected $description = 'Khôi phục tác vụ đồng bộ bị treo (lượt chạy dở, webhook chưa xử lý)';

    public function handle(SyncHealth $health): int
    {
        $closed = $health->closeStuckRuns();
        $requeued = $health->requeueLostWebhooks();
        $this->info("Đóng {$closed} lượt đồng bộ treo, xếp lại {$requeued} webhook.");

        return self::SUCCESS;
    }
}
