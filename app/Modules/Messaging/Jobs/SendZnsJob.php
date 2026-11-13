<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Zalo\ZaloService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends the Zalo ZNS message of one order event (C6), after the change that caused it is saved. */
class SendZnsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $orderId, public readonly string $event) {}

    public function handle(ZaloService $zalo): void
    {
        $order = Order::query()->withoutGlobalScopes()->find($this->orderId);
        $tenant = $order ? Tenant::query()->find($order->tenant_id) : null;
        if ($order && $tenant) {
            TenantContext::run($tenant, fn () => $zalo->send(Order::query()->findOrFail($order->id), $this->event));
        }
    }
}
