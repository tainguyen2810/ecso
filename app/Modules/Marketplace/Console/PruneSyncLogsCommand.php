<?php

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Services\SyncHealth;
use Illuminate\Console\Command;

/** Daily: drops webhook events, sync passes and resolved sync errors older than 30 days. */
class PruneSyncLogsCommand extends Command
{
    protected $signature = 'ecso:sync:prune';

    protected $description = 'Xóa nhật ký đồng bộ cũ hơn 30 ngày';

    public function handle(SyncHealth $health): int
    {
        $this->info('Đã xóa: '.json_encode($health->prune()));

        return self::SUCCESS;
    }
}
