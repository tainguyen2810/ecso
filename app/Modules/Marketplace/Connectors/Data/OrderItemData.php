<?php

namespace App\Modules\Marketplace\Connectors\Data;

final class OrderItemData
{
    public function __construct(
        public readonly string $externalItemId,
        public readonly ?string $externalProductId,
        public readonly ?string $externalSkuId,
        public readonly ?string $sellerSku,
        public readonly string $name,
        public readonly ?string $variantName,
        public readonly int $quantity,
        public readonly string $unitPrice,
        public readonly string $discount = '0',
        public readonly string $tax = '0',
        /** Units refunded on the store (WooCommerce refunds by line). */
        public readonly int $refundedQuantity = 0,
    ) {}
}
