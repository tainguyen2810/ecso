<?php

namespace App\Modules\Shipping\Connectors\Data;

use Illuminate\Support\Carbon;

/**
 * One thing a carrier reported about a parcel (webhook or tracking), already translated to our shipment status.
 * $status null = nothing to move (weight or fee update only). $key makes the same report count once.
 */
final class CarrierEvent
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly ?string $status,
        public readonly ?string $rawStatus,
        public readonly Carbon $occurredAt,
        public readonly string $key,
        public readonly ?string $description = null,
        public readonly ?string $fee = null,
        public readonly ?string $codCollected = null,
        public readonly ?int $weightG = null,
        public readonly ?string $clientCode = null,
    ) {}
}
