<?php

namespace App\Modules\Marketplace\Connectors\Data;

/** The regular (list) price ECSO wants on one channel SKU. */
final class PriceUpdate
{
    public function __construct(
        public readonly string $externalProductId,
        public readonly string $externalSkuId,
        public readonly string $price,
    ) {}
}
