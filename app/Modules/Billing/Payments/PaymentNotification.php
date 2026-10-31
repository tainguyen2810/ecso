<?php

namespace App\Modules\Billing\Payments;

use Carbon\CarbonImmutable;

/** A verified payment notification from a gateway (IPN). */
final class PaymentNotification
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $transactionId,
        public readonly string $transferCode,
        public readonly float $amount,
        public readonly CarbonImmutable $receivedAt,
        public readonly array $raw = [],
    ) {}
}
