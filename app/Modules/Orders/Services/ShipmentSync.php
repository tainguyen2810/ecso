<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Orders\Support\Tracking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps an order's main shipment (vận đơn) in step with the order (shipping round S2).
 *
 * The order stays the source of truth for now: every order status change, and every carrier/tracking number edit, is
 * mirrored onto its main shipment (the newest one that is not cancelled), creating it when the order first gets a
 * tracking number or is handed over. A shipment only moves forward (ShipmentStatus::TRANSITIONS); it never changes
 * the order, its stock or its money. Idempotent: applying the same order twice changes nothing.
 * Must run inside the transaction that holds the order row lock.
 */
class ShipmentSync
{
    public function apply(Order $order): ?Shipment
    {
        $shipment = $this->main($order);
        $target = $this->target($order, $shipment?->status);

        if ($shipment === null) {
            if ($target === null || ! $this->wentByCarrier($order)) {
                return null;
            }
            $shipment = $this->create($order);
        }

        $this->mirrorCarrier($order, $shipment);

        if ($target !== null && $target !== $shipment->status && ShipmentStatus::canMove($shipment->status, $target)) {
            $this->move($order, $shipment, $target);
        }

        if (in_array($shipment->status, [ShipmentStatus::PENDING, ShipmentStatus::AWAITING_PICKUP], true)) {
            // Not with the carrier yet: the amount to collect follows the order (payments, edits).
            $shipment->cod_amount = $this->codAmount($order);
            $shipment->cod_status = bccomp((string) $shipment->cod_amount, '0', 2) > 0 ? Shipment::COD_PENDING : Shipment::COD_NONE;
        }
        $shipment->save();

        return $shipment;
    }

    public function main(Order $order): ?Shipment
    {
        return Shipment::query()->where('order_id', $order->id)->where('status', '!=', ShipmentStatus::CANCELLED)->latest('id')->first();
    }

    /**
     * Whether the goods left with a carrier (or will): a tracking number, a carrier step, a marketplace order (always
     * shipped), or a handover in the order's history. A counter sale completed on the spot has no shipment.
     */
    private function wentByCarrier(Order $order): bool
    {
        return filled($order->tracking_number)
            || in_array($order->status, [OrderStatus::HANDED_OVER, OrderStatus::IN_TRANSIT, OrderStatus::FAILED_DELIVERY], true)
            || ! $order->ownsCustomerData()
            || $order->history()->where('to_status', OrderStatus::HANDED_OVER)->exists();
    }

    /** The shipment status the order's status means, given where the shipment is now; null = nothing to change. */
    private function target(Order $order, ?string $current): ?string
    {
        return match ($order->status) {
            OrderStatus::UNPAID, OrderStatus::PENDING_CONFIRMATION, OrderStatus::READY_TO_PACK, OrderStatus::PACKED => filled($order->tracking_number) ? ShipmentStatus::AWAITING_PICKUP : null,
            OrderStatus::HANDED_OVER => ShipmentStatus::PICKED_UP,
            OrderStatus::IN_TRANSIT => in_array($current, [ShipmentStatus::FAILED, ShipmentStatus::REDELIVERING], true) ? ShipmentStatus::REDELIVERING : ShipmentStatus::IN_TRANSIT,
            OrderStatus::DELIVERED, OrderStatus::COMPLETED => ShipmentStatus::DELIVERED,
            OrderStatus::FAILED_DELIVERY => ShipmentStatus::FAILED,
            // Delivered and returned later is a customer return, not a parcel coming back.
            OrderStatus::RETURNED => $current === ShipmentStatus::DELIVERED ? null : ShipmentStatus::RETURNED,
            OrderStatus::CANCELLED => match (true) {
                $current === null => null,
                in_array($current, ShipmentStatus::WITH_CARRIER, true) => ShipmentStatus::RETURNING,
                default => ShipmentStatus::CANCELLED,
            },
            default => null,
        };
    }

    private function create(Order $order): Shipment
    {
        return Shipment::query()->create([
            'order_id' => $order->id,
            'source' => $order->ownsCustomerData() ? Shipment::SOURCE_MANUAL : Shipment::SOURCE_MARKETPLACE,
            'status' => ShipmentStatus::PENDING,
            'warehouse_id' => $order->warehouse_id,
            'ship_by_at' => $order->ship_by_at,
            'cod_amount' => $this->codAmount($order),
            'cod_status' => Shipment::COD_NONE,
            'created_by' => Auth::id(),
        ]);
    }

    /** Carrier and tracking number follow the order (typed at handover, corrected later, or sent by the marketplace). */
    private function mirrorCarrier(Order $order, Shipment $shipment): void
    {
        $tracking = Tracking::normalize($order->tracking_number);
        $name = filled($order->shipping_carrier) ? mb_substr(trim((string) $order->shipping_carrier), 0, 100) : null;

        if ($tracking !== null && $shipment->exists && $shipment->tracking_number !== null && $shipment->tracking_number !== $tracking) {
            $this->event($shipment, $shipment->status, now(), "Sửa mã vận đơn {$shipment->tracking_number} → {$tracking}", 'tracking:'.$tracking);
        }
        if ($tracking !== null) {
            $shipment->tracking_number = $tracking;
        }
        if ($name !== null) {
            $shipment->carrier_name = $name;
            $shipment->carrier = Tracking::carrierKey($name);
        }
    }

    private function move(Order $order, Shipment $shipment, string $target): void
    {
        $at = $this->occurredAt($order, $target);
        $shipment->status = $target;
        $shipment->last_event_at = $at;

        match ($target) {
            ShipmentStatus::DELIVERED => $shipment->delivered_at = $at,
            ShipmentStatus::FAILED => $shipment->failed_at = $at,
            ShipmentStatus::RETURNED => $shipment->returned_at = $at,
            ShipmentStatus::CANCELLED => $shipment->cancelled_at = $at,
            default => null,
        };
        if ($target === ShipmentStatus::FAILED) {
            $shipment->attempts++;
        }
        if ($shipment->picked_up_at === null && ! in_array($target, [ShipmentStatus::AWAITING_PICKUP, ShipmentStatus::CANCELLED], true)) {
            // Handed to the carrier now, or earlier without the shipment knowing (an older order being filled in).
            $shipment->picked_up_at = $order->handed_over_at ? Carbon::instance($order->handed_over_at) : $at;
            $shipment->cod_amount = $this->codAmount($order);
        }
        $shipment->cod_status = $this->codStatus($shipment);

        $shipment->save();
        // The attempt number keeps each failure / redelivery distinct, so a second failure is its own step.
        $this->event($shipment, $target, $at, null, "order:{$target}:{$shipment->attempts}");
    }

    private function codStatus(Shipment $shipment): string
    {
        if (bccomp((string) $shipment->cod_amount, '0', 2) <= 0) {
            return Shipment::COD_NONE;
        }
        if ($shipment->cod_status === Shipment::COD_REMITTED) {
            return Shipment::COD_REMITTED;
        }

        return match ($shipment->status) {
            ShipmentStatus::DELIVERED => Shipment::COD_COLLECTED,
            ShipmentStatus::RETURNED, ShipmentStatus::RETURNING => Shipment::COD_RETURNED,
            ShipmentStatus::CANCELLED => Shipment::COD_NONE,
            default => Shipment::COD_PENDING,
        };
    }

    /** What the carrier collects: what the buyer still owes on a COD order, nothing otherwise. */
    private function codAmount(Order $order): string
    {
        if (! $order->is_cod) {
            return '0';
        }
        $due = $order->ownsCustomerData() ? $order->balanceDue() : (string) $order->total;

        return bccomp($due, '0', 2) > 0 ? $due : '0';
    }

    /** When it happened, from the order's own timestamps where it has them (not when ECSO processed it). */
    private function occurredAt(Order $order, string $target): Carbon
    {
        $at = match ($target) {
            ShipmentStatus::PICKED_UP => $order->handed_over_at,
            ShipmentStatus::DELIVERED => $order->delivered_at,
            ShipmentStatus::CANCELLED => $order->cancelled_at,
            default => null,
        };

        return $at ? Carbon::instance($at) : now();
    }

    private function event(Shipment $shipment, string $status, Carbon $at, ?string $description, string $key): void
    {
        ShipmentEvent::query()->firstOrCreate(
            ['shipment_id' => $shipment->id, 'dedupe_key' => mb_substr($key, 0, 120)],
            ['status' => $status, 'occurred_at' => $at, 'source' => ShipmentEvent::SOURCE_ORDER, 'description' => $description, 'actor_id' => Auth::id()],
        );
    }
}
