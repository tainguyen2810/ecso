<?php

namespace App\Modules\Marketplace\Connectors\Data;

use Carbon\CarbonImmutable;

/** An order as the marketplace API reports it now (the source of truth, not the webhook payload). */
final class OrderData
{
    /**
     * @param  array<int, OrderItemData>  $items
     * @param  array<string, mixed>  $raw
     * @param  array{billing?: array<string, string>, shipping?: array<string, string>}|null  $addresses
     * @param  list<string>  $couponCodes
     * @param  list<array{id: string, amount: string, reason: string|null, refunded_at: CarbonImmutable|null, lines: list<array{external_item_id: string, quantity: int, amount: string}>}>|null  $refunds
     *                                                                                                                                                                                                      null = not read (keep what ECSO has)
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
        // The fields below are filled by connectors of the seller's own stores (WooCommerce), where the seller owns
        // the customer data; marketplaces leave them empty and only the masked phone is kept.
        public readonly ?string $externalNumber = null,
        public readonly ?string $buyerEmail = null,
        public readonly ?array $addresses = null,
        public readonly ?string $externalCustomerId = null,
        public readonly string $taxTotal = '0',
        public readonly string $feeTotal = '0',
        public readonly array $couponCodes = [],
        public readonly ?string $transactionId = null,
        public readonly ?string $refundedTotal = null,
        /** Payment status from the store's data (paid online, or null when it cannot be known, e.g. COD). */
        public readonly ?string $paymentStatus = null,
        public readonly bool $ownsCustomer = false,
        public readonly ?array $refunds = null,
    ) {}
}
