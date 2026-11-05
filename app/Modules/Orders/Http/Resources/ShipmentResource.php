<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shipment */
class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'carrier' => $this->carrier,
            'carrier_name' => $this->carrier_name,
            'source' => $this->source,
            'tracking_number' => $this->tracking_number,
            'status' => $this->status,
            'status_label' => ShipmentStatus::label($this->status),
            'packages' => $this->packages,
            'weight_g' => $this->weight_g,
            'cod_amount' => $this->cod_amount,
            'cod_collected' => $this->cod_collected,
            'cod_status' => $this->cod_status,
            // Display only: the profit report takes carrier fees from vouchers, never from here.
            'fee_estimated' => $this->fee_estimated,
            'fee_actual' => $this->fee_actual,
            'fee_status' => $this->fee_status,
            'attempts' => $this->attempts,
            'failure_reason' => $this->failure_reason,
            'ship_by_at' => $this->ship_by_at?->toIso8601String(),
            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'last_event_at' => $this->last_event_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->ulid,
                'external_order_id' => $this->order->external_order_id,
                'marketplace' => $this->order->marketplace,
                'status' => $this->order->status,
                'is_cod' => $this->order->is_cod,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn (ShipmentEvent $e) => [
                'status' => $e->status,
                'status_label' => ShipmentStatus::label($e->status),
                'description' => $e->description,
                'source' => $e->source,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->all()),
        ];
    }
}
