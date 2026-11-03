<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Tenancy\Events\TenantCreated;

/** Every business starts with a shop-counter channel so manual orders and POS work out of the box. */
class CreateDefaultSalesChannel
{
    public function handle(TenantCreated $event): void
    {
        SalesChannel::query()->firstOrCreate(['name' => SalesChannel::DEFAULT_NAME], ['type' => 'pos', 'is_active' => true]);
    }
}
