<?php

namespace App\Modules\Shipping\Jobs;

use App\Modules\Messaging\Push\PushNotifier;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** "Giao thất bại" / "Đang hoàn": one notification to the phones of members who handle orders. */
class SendShipmentAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $tenantId, public readonly int $shipmentId, public readonly string $status) {}

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        TenantContext::run($tenant, function () {
            $shipment = Shipment::query()->with('order')->find($this->shipmentId);
            if ($shipment === null || $shipment->order === null) {
                return;
            }
            app(PushNotifier::class)->send('orders.view', ...self::message($shipment, $this->status));
        });
    }

    /** @return array{0: string, 1: string, 2: string} title, body, url */
    public static function message(Shipment $shipment, string $status): array
    {
        $order = $shipment->order;
        $what = $status === ShipmentStatus::FAILED ? 'Giao thất bại' : 'Đang hoàn hàng';
        $carrier = $shipment->carrier_name ?? 'Vận đơn';
        $reason = $shipment->failure_reason ? ' · '.$shipment->failure_reason : '';

        return [
            $what.' · '.$order->displayCode(),
            trim($carrier.($shipment->tracking_number ? ' '.$shipment->tracking_number : '').($order->buyer_name ? ' · '.$order->buyer_name : '').$reason),
            '/shipments?shipment='.$shipment->ulid,
        ];
    }
}
