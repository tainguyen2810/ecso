<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Services\DemoStoreService;
use Illuminate\Console\Command;

/** Daily: deletes sample stores ("Cửa hàng mẫu") older than 30 days. */
class PruneDemoStoresCommand extends Command
{
    protected $signature = 'ecso:demo-stores:prune';

    protected $description = 'Xóa cửa hàng mẫu đã quá 30 ngày';

    public function handle(DemoStoreService $demo): int
    {
        $this->info('Đã xóa '.$demo->pruneExpired().' cửa hàng mẫu.');

        return self::SUCCESS;
    }
}
