<?php

namespace App\Modules\Shipping\Connectors\Data;

use Illuminate\Support\Carbon;

/** What the carrier answered when the parcel was made (or found again by our own code). */
final class CreatedShipment
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly ?string $fee = null,
        public readonly ?Carbon $expectedAt = null,
        public readonly ?string $externalId = null,
    ) {}
}
