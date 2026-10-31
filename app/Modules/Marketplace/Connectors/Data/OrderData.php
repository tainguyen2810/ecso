<?php

namespace App\Modules\Marketplace\Connectors\Data;

use Carbon\CarbonImmutable;

/** An order as the marketplace API reports it now (the source of truth, not the webhook payload). */
final class OrderData
{
    /**
     * @param  array<int, OrderItemData>  $items
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalOrderId,
        public readonly string $rawStatus,
        public readonly CarbonImmutable $updatedAt,
        public readonly array $items,
        public readonly ?CarbonImmutable $placedAt = null,
        public readonly ?CarbonImmutable $paidAt = null,
        public readonly ?CarbonImmutable $shipByAt = null,
        public readonly ?string $paymentMethod = null,
        public readonly bool $isCod = false,
        public readonly string $currency = 'VND',
        public readonly string $subtotal = '0',
        public readonly string $shippingFee = '0',
        public readonly string $discount = '0',
        public readonly string $total = '0',
        public readonly ?string $buyerName = null,
        public readonly ?string $buyerPhone = null,
        public readonly ?string $shippingAddress = null,
        public readonly ?string $carrier = null,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $buyerNote = null,
        public readonly ?string $cancelReason = null,
        public readonly array $raw = [],
    ) {}
}
