<?php

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Services\UsageService;
use App\Modules\Orders\Events\OrderImported;

/**
 * Meters orders per month. The limit is soft on purpose: refusing to import a marketplace order would leave
 * stock wrong (overselling), so orders are always imported and the tenant is warned / asked to upgrade.
 */
class CountOrderUsage
{
    public function handle(OrderImported $event): void
    {
        UsageService::increment($event->tenantId, UsageService::ORDERS, now()->format('Y-m'));
    }
}
