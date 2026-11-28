<?php

namespace App\Modules\Orders\Jobs;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderSync;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Right after "Chuẩn bị hàng" Shopee still answers READY_TO_SHIP: it books the carrier in the background and moves the
 * order to PROCESSED a few seconds later. This reads the order again a few times until its status moves on, so the
 * list, the tab counts and "Cần chuẩn bị hàng" catch up even when the webhook is late or lost.
 */
class ResyncArrangedOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds before each read (first dispatch, then each retry). */
    public const DELAYS = [8, 20, 45, 90, 180];

    public int $tries = 5;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(OrderSync $sync): void
    {
        $order = Order::query()->withoutGlobalScopes()->find($this->orderId);
        $tenant = $order ? Tenant::query()->find($order->tenant_id) : null;
        if (! $order || ! $tenant || $order->status !== OrderStatus::READY_TO_PACK || $order->arranged_at === null) {
            return;
        }
        $status = TenantContext::run($tenant, function () use ($sync) {
            $order = Order::query()->with('account')->find($this->orderId);
            if (! $order || ! $order->account || ! $order->account->isActive()) {
                return null;
            }
            try {
                return ($sync->syncOne($order->account, $order->external_order_id, 'user')['order'] ?? $order)->status;
            } catch (ConnectorException) {
                return OrderStatus::READY_TO_PACK; // try again on the next round
            }
        });
        if ($status === OrderStatus::READY_TO_PACK && $this->attempts() < $this->tries) {
            $this->release(self::DELAYS[min($this->attempts(), count(self::DELAYS) - 1)]);
        }
    }
}
