<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Services\ShipmentBackfill;
use Illuminate\Console\Command;

/** Creates shipments for existing orders (see ShipmentBackfill): only inserts, never changes an order. */
class BackfillShipmentsCommand extends Command
{
    protected $signature = 'ecso:backfill-shipments {--tenant= : Chỉ chạy cho một doanh nghiệp (id)}';

    protected $description = 'Tạo vận đơn cho các đơn cũ đã có mã vận đơn hoặc đã giao cho hãng (chỉ thêm, không sửa đơn)';

    public function handle(ShipmentBackfill $backfill): int
    {
        $tenant = $this->option('tenant');
        $created = $backfill->run($tenant !== null ? (int) $tenant : null);
        $this->info("Đã tạo {$created} vận đơn.");

        return self::SUCCESS;
    }
}
