<?php

namespace App\Modules\Shipping\Listeners;

use App\Modules\Messaging\Models\PushSubscription;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Shipping\Jobs\CancelCarrierShipments;
use App\Modules\Shipping\Jobs\SendShipmentAlert;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Support\Facades\DB;

/**
 * Vận chuyển › Cấu hình giao hàng › Tự động: tell the phones when a parcel fails or starts coming back, and take back
 * the carrier's parcels of an own order that was cancelled before pickup. Both run after the change is saved.
 */
class ShipmentAutomation
{
    /** Older steps (a backfill, a first pull of a shop) are history, not news. */
    public const RECENT_HOURS = 48;

    public function eventRecorded(ShipmentEvent $event): void
    {
        if (! in_array($event->status, [ShipmentStatus::FAILED, ShipmentStatus::RETURNING], true)
            || $event->occurred_at->lt(now()->subHours(self::RECENT_HOURS))) {
            return;
        }
        // The carrier's report and the order following it both write a step: tell once per failure.
        $seen = ShipmentEvent::query()->withoutGlobalScopes()->where('shipment_id', $event->shipment_id)->where('status', $event->status)
            ->whereKeyNot($event->id)->where('occurred_at', '>=', $event->occurred_at->copy()->subHour())->exists();
        $tenant = Tenant::query()->find($event->tenant_id);
        if ($seen || $tenant === null || ! TenantSettings::for($tenant)->get('shipping.notify_problems')
            || ! PushSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }
        $shipmentId = (int) $event->shipment_id;
        $status = $event->status;
        DB::afterCommit(fn () => SendShipmentAlert::dispatch($tenant->id, $shipmentId, $status));
    }

    public function orderSaved(Order $order): void
    {
        if (! $order->wasChanged('status') || $order->status !== OrderStatus::CANCELLED || ! $order->ownsCustomerData()) {
            return;
        }
        $tenant = Tenant::query()->find($order->tenant_id);
        if ($tenant === null || ! TenantSettings::for($tenant)->get('shipping.cancel_with_order')) {
            return;
        }
        // Read now: right after this save the parcel follows the order and is marked cancelled here.
        $waiting = Shipment::query()->withoutGlobalScopes()->where('order_id', $order->id)->where('source', Shipment::SOURCE_API)
            ->whereNotNull('tracking_number')->whereIn('status', [ShipmentStatus::PENDING, ShipmentStatus::AWAITING_PICKUP])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($waiting !== []) {
            DB::afterCommit(fn () => CancelCarrierShipments::dispatch($tenant->id, $waiting));
        }
    }
}
