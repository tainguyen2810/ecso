<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use App\Modules\Sales\Services\ManualOrderService;
use App\Modules\Shipping\Connectors\Data\CarrierEvent;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What a carrier reports (webhook or tracking) moves the shipment, and on the shop's own orders the order too, through
 * the order's own steps: picked up = "Đã bàn giao" (stock leaves), delivered = "Đã giao", failed = "Giao thất bại"
 * (the automatic return opens). A parcel coming back never adds stock: that waits for the return to be inspected.
 * Each report counts once (its key), and a report older than where the parcel is now only goes into the history.
 */
class CarrierEventApplier
{
    public function __construct(private readonly ManualOrderService $orders) {}

    /**
     * @param  array<int, CarrierEvent>  $events
     * @return int reports that were new
     */
    public function apply(CarrierAccount $account, array $events): int
    {
        usort($events, fn (CarrierEvent $a, CarrierEvent $b) => $a->occurredAt <=> $b->occurredAt);
        $new = 0;
        foreach ($events as $event) {
            $shipment = Shipment::query()->where('carrier_account_id', $account->id)
                ->where(fn ($q) => $q->where('tracking_number', $event->trackingNumber)->when($event->clientCode !== null, fn ($q) => $q->orWhere('client_code', $event->clientCode)))
                ->latest('id')->first();
            if ($shipment === null) {
                continue;
            }
            try {
                if ($shipment->tracking_number === null && $shipment->source === Shipment::SOURCE_API && $shipment->status === ShipmentStatus::PENDING && filled($event->trackingNumber)) {
                    $shipment = app(CarrierShipmentService::class)->adopt($shipment, $event->trackingNumber);
                }
                $new += $this->one($shipment, $event) ? 1 : 0;
            } catch (Throwable $e) {
                Log::error('carrier.event_failed', ['shipment' => $shipment->ulid, 'status' => $event->rawStatus, 'error' => $e->getMessage()]);
            }
        }
        if ($new > 0) {
            $account->forceFill(['last_event_at' => now()])->save();
        }

        return $new;
    }

    private function one(Shipment $shipment, CarrierEvent $event): bool
    {
        $recorded = DB::transaction(function () use ($shipment, $event) {
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $row = ShipmentEvent::query()->firstOrCreate(
                ['shipment_id' => $shipment->id, 'dedupe_key' => mb_substr($event->key, 0, 120)],
                ['status' => $event->status ?? $shipment->status, 'raw_status' => $event->rawStatus !== null ? mb_substr($event->rawStatus, 0, 64) : null, 'occurred_at' => $event->occurredAt,
                    'source' => ShipmentEvent::SOURCE_WEBHOOK, 'description' => $event->description !== null ? mb_substr($event->description, 0, 255) : null],
            );
            if (! $row->wasRecentlyCreated) {
                return false;
            }
            if ($event->fee !== null) {
                $shipment->fee_actual = $event->fee;
                $shipment->fee_status = 'confirmed';
            }
            if ($event->weightG !== null) {
                $shipment->weight_g = $event->weightG;
            }
            if ($event->codCollected !== null) {
                $shipment->cod_collected = $event->codCollected;
            }
            if ($event->rawStatus !== null && $event->status !== null) {
                $shipment->raw_status = mb_substr($event->rawStatus, 0, 64);
            }
            $shipment->save();

            return true;
        });
        if (! $recorded || $event->status === null) {
            return $recorded;
        }

        $shipment->refresh();
        if ($event->status === $shipment->status || ! ShipmentStatus::canMove($shipment->status, $event->status)) {
            return true; // old or repeated news: history only
        }

        $order = Order::query()->findOrFail($shipment->order_id);
        if ($order->isManual() && $this->isMain($shipment, $order)) {
            $this->moveOrder($shipment, $order, $event);
        }
        // Steps the order has no status for (in transit, out for delivery, returning...), or a website order.
        $this->moveShipment($shipment->refresh(), $event);

        return true;
    }

    /** Runs the order steps the parcel's new status means, in order (a late "picked" may arrive with "delivered"). */
    private function moveOrder(Shipment $shipment, Order $order, CarrierEvent $event): void
    {
        $steps = match ($event->status) {
            ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT, ShipmentStatus::OUT_FOR_DELIVERY => ['confirm', 'handover'],
            ShipmentStatus::DELIVERED => ['confirm', 'handover', 'deliver'],
            ShipmentStatus::FAILED, ShipmentStatus::RETURNING => ['confirm', 'handover', 'fail_delivery'],
            default => [],
        };
        foreach ($steps as $step) {
            $order->refresh();
            if (! in_array($order->status, ManualOrderService::ACTIONS[$step][0], true)) {
                continue;
            }
            try {
                $this->orders->transition($order, $step, array_filter(['reason' => $step === 'fail_delivery' ? $event->description : null]));
            } catch (ValidationException $e) {
                // e.g. stock short at pickup: the parcel still moves, the shop sees why the order did not.
                $shipment->forceFill(['api_error' => mb_substr('Không tự chuyển được trạng thái đơn: '.collect($e->errors())->flatten()->first(), 0, 255)])->save();

                return;
            }
        }
        $order->refresh();
        if ($order->status === OrderStatus::DELIVERED || $order->status === OrderStatus::FAILED_DELIVERY || $order->status === OrderStatus::HANDED_OVER) {
            $shipment->refresh();
            if ($shipment->api_error !== null && str_starts_with($shipment->api_error, 'Không tự chuyển')) {
                $shipment->forceFill(['api_error' => null])->save();
            }
        }
    }

    private function moveShipment(Shipment $shipment, CarrierEvent $event): void
    {
        $target = $event->status;
        if ($target === null || $target === $shipment->status || ! ShipmentStatus::canMove($shipment->status, $target)) {
            return;
        }
        DB::transaction(function () use ($shipment, $event, $target) {
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if (! ShipmentStatus::canMove($shipment->status, $target)) {
                return;
            }
            $at = $event->occurredAt;
            $shipment->status = $target;
            $shipment->last_event_at = $at;
            if ($shipment->picked_up_at === null && ! in_array($target, [ShipmentStatus::AWAITING_PICKUP, ShipmentStatus::CANCELLED], true)) {
                $shipment->picked_up_at = $at;
            }
            match ($target) {
                ShipmentStatus::DELIVERED => $shipment->delivered_at = $at,
                ShipmentStatus::FAILED => $shipment->failed_at = $at,
                ShipmentStatus::RETURNED => $shipment->returned_at = $at,
                ShipmentStatus::CANCELLED => $shipment->cancelled_at = $at,
                default => null,
            };
            if ($target === ShipmentStatus::FAILED) {
                $shipment->attempts++;
                $shipment->failure_reason = $event->description !== null ? mb_substr($event->description, 0, 255) : null;
            }
            $hasCod = bccomp((string) $shipment->cod_amount, '0', 2) > 0;
            $shipment->cod_status = match (true) {
                ! $hasCod || $target === ShipmentStatus::CANCELLED => Shipment::COD_NONE,
                $shipment->cod_status === Shipment::COD_REMITTED => Shipment::COD_REMITTED,
                $target === ShipmentStatus::DELIVERED => Shipment::COD_COLLECTED,
                in_array($target, [ShipmentStatus::RETURNING, ShipmentStatus::RETURNED], true) => Shipment::COD_RETURNED,
                default => Shipment::COD_PENDING,
            };
            $shipment->save();
            if ($target === ShipmentStatus::CANCELLED) {
                // Cancelled at the carrier: the order no longer carries this number and can be pushed again.
                Order::query()->whereKey($shipment->order_id)->where('tracking_number', $shipment->tracking_number)->update(['tracking_number' => null]);
            }
        });
    }

    private function isMain(Shipment $shipment, Order $order): bool
    {
        return Shipment::query()->where('order_id', $order->id)->where('status', '!=', ShipmentStatus::CANCELLED)->latest('id')->value('id') === $shipment->id;
    }
}
