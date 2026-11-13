<?php

namespace App\Modules\Invoicing\Jobs;

use App\Modules\Invoicing\Services\EInvoiceService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** "Tự xuất hóa đơn": issues the invoice of an order that just got fully paid or completed. */
class IssueEInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $orderId, public readonly string $trigger) {}

    public function handle(EInvoiceService $service): void
    {
        $order = Order::query()->withoutGlobalScopes()->find($this->orderId);
        $tenant = $order ? Tenant::query()->find($order->tenant_id) : null;
        if ($order && $tenant) {
            TenantContext::run($tenant, fn () => $service->autoIssue(Order::query()->findOrFail($order->id), $this->trigger));
        }
    }
}
