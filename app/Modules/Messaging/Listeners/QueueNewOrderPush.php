<?php

namespace App\Modules\Messaging\Listeners;

use App\Modules\Messaging\Jobs\SendNewOrderPush;
use App\Modules\Messaging\Models\PushSubscription;
use App\Modules\Orders\Events\OrderImported;
use Illuminate\Support\Facades\Cache;

/**
 * A new order: phones that asked are told within half a minute. Orders arriving together (a marketplace pull, a busy
 * hour) make one notification "N đơn mới", not one per order.
 */
class QueueNewOrderPush
{
    public const WINDOW = 30;

    public function handle(OrderImported $event): void
    {
        if (! PushSubscription::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->exists()) {
            return;
        }
        $key = "push:new-orders:{$event->tenantId}";
        $ids = (array) Cache::get($key, []);
        $ids[] = $event->orderId;
        Cache::put($key, array_slice($ids, -200), self::WINDOW * 4);
        if (Cache::add("push:new-orders-window:{$event->tenantId}", 1, self::WINDOW)) {
            SendNewOrderPush::dispatch($event->tenantId)->delay(now()->addSeconds(self::WINDOW - 5));
        }
    }
}
