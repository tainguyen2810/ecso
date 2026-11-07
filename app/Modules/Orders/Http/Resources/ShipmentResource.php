<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Geo\Models\Ward;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Orders\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin Shipment */
class ShipmentResource extends JsonResource
{
    /** @var array<string, string> ward code => "Ward, Province" for the shipments being shown */
    private static array $areas = [];

    /**
     * Loads the area (ward, province) of the given shipments' orders in one query.
     *
     * @param  Collection<int, Shipment>  $shipments
     */
    public static function rememberAreas(Collection $shipments): void
    {
        $codes = $shipments->map(fn (Shipment $s) => $s->order?->shipping_ward_code)->filter()->unique()->values()->all();
        self::$areas = $codes === [] ? [] : Ward::query()->with('province')->whereIn('code', $codes)->get()
            ->mapWithKeys(fn (Ward $w) => [$w->code => trim($w->name.', '.($w->province->name ?? ''), ', ')])->all();
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'carrier' => $this->carrier,
            'carrier_name' => $this->carrier_name,
            'source' => $this->source,
            'tracking_number' => $this->tracking_number,
            'carrier_account_id' => $this->carrierAccount?->ulid,
            'service' => $this->service,
            'service_name' => $this->service_name,
            'expected_delivery_at' => $this->expected_delivery_at?->toIso8601String(),
            // Why the carrier refused the parcel, or why the order could not follow it.
            'api_error' => $this->api_error,
            'raw_status' => $this->raw_status,
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
            'payer' => $this->payer,
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
                'channel' => $this->order->relationLoaded('channel') ? $this->order->channel?->name : null,
                'status' => $this->order->status,
                'is_cod' => $this->order->is_cod,
                // Marketplace orders only carry what the marketplace shows (masked).
                'recipient' => $this->order->buyer_name,
                'phone' => $this->order->ownsCustomerData() ? ($this->order->buyer_phone ?? $this->order->buyer_phone_masked) : $this->order->buyer_phone_masked,
                'address' => $this->order->shipping_address,
                'area' => self::$areas[(string) $this->order->shipping_ward_code] ?? null,
            ]),
            'actions' => $this->whenLoaded('order', fn () => app(ShipmentService::class)->actions($this->resource, $this->order)),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'events' => $this->whenLoaded('events', fn () => $this->events->sortByDesc(fn (ShipmentEvent $e) => [$e->occurred_at->getTimestamp(), $e->id])->values()->map(fn (ShipmentEvent $e) => [
                'status' => $e->status,
                'status_label' => ShipmentStatus::label($e->status),
                'description' => $e->description,
                'source' => $e->source,
                'actor' => $e->relationLoaded('actor') ? $e->actor?->name : null,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->all()),
        ];
    }
}
