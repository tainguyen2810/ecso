<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Models\ShipmentEvent;
use Illuminate\Support\Carbon;

/**
 * What a carrier's COD statement says about a shipment (shipping round S4): the COD it collected and paid, and the fee
 * it charged. Recorded on the shipment for display only: the money is booked by the COD reconciliation (order
 * payment, "Phí vận chuyển" voucher), and the profit report takes fees from that voucher, never from the shipment.
 */
class ShipmentCod
{
    public function __construct(private readonly ShipmentSync $sync) {}

    /** The shipment a statement line is about: the order's shipment with that number, else its main shipment. */
    public function find(Order $order, string $tracking): ?Shipment
    {
        return Shipment::query()->where('order_id', $order->id)->where('tracking_number', $tracking)->latest('id')->first()
            ?? $this->sync->main($order);
    }

    public function remitted(Shipment $shipment, string $cod, string $fee, string $reference, Carbon $at): void
    {
        $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
        $shipment->cod_collected = bcadd((string) ($shipment->cod_collected ?? '0'), $cod, 2);
        if (bccomp($fee, '0', 2) > 0) {
            $shipment->fee_actual = bcadd((string) ($shipment->fee_actual ?? '0'), $fee, 2);
            $shipment->fee_status = 'confirmed';
        }
        if (bccomp($cod, '0', 2) > 0) {
            $shipment->cod_status = Shipment::COD_REMITTED;
        }
        $shipment->save();
        $this->sync->event($shipment, $shipment->status, $at, $this->describe("Đối soát COD {$reference}", $cod, $fee), "cod:{$reference}", ShipmentEvent::SOURCE_COD);
    }

    /** The statement was voided: the shipment goes back to before it. */
    public function voided(Shipment $shipment, string $cod, string $fee, string $reference): void
    {
        $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
        $collected = bcsub((string) ($shipment->cod_collected ?? '0'), $cod, 2);
        $shipment->cod_collected = bccomp($collected, '0', 2) > 0 ? $collected : null;
        if (bccomp($fee, '0', 2) > 0) {
            $actual = bcsub((string) ($shipment->fee_actual ?? '0'), $fee, 2);
            $shipment->fee_actual = bccomp($actual, '0', 2) > 0 ? $actual : null;
            if ($shipment->fee_actual === null) {
                $shipment->fee_status = $shipment->fee_estimated !== null ? 'estimated' : 'unknown';
            }
        }
        if ($shipment->cod_collected === null && $shipment->cod_status === Shipment::COD_REMITTED) {
            $shipment->cod_status = match (true) {
                bccomp((string) $shipment->cod_amount, '0', 2) <= 0 => Shipment::COD_NONE,
                $shipment->status === ShipmentStatus::DELIVERED => Shipment::COD_COLLECTED,
                in_array($shipment->status, [ShipmentStatus::RETURNING, ShipmentStatus::RETURNED], true) => Shipment::COD_RETURNED,
                default => Shipment::COD_PENDING,
            };
        }
        $shipment->save();
        $this->sync->event($shipment, $shipment->status, now(), "Hủy đối soát COD {$reference}", "cod-void:{$reference}", ShipmentEvent::SOURCE_COD);
    }

    private function describe(string $what, string $cod, string $fee): string
    {
        $money = fn (string $v) => number_format((float) $v, 0, ',', '.').' ₫';
        $parts = [$what];
        if (bccomp($cod, '0', 2) > 0) {
            $parts[] = 'hãng trả '.$money($cod);
        }
        if (bccomp($fee, '0', 2) > 0) {
            $parts[] = 'phí '.$money($fee);
        }

        return implode(' · ', $parts);
    }
}
