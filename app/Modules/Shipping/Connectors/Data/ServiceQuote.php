<?php

namespace App\Modules\Shipping\Connectors\Data;

use Illuminate\Support\Carbon;

/** One service a carrier offers for a parcel, with its estimated fee and delivery date. */
final class ServiceQuote
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $fee,
        public readonly ?Carbon $expectedAt = null,
    ) {}

    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'fee' => $this->fee, 'expected_at' => $this->expectedAt?->toIso8601String()];
    }
}
