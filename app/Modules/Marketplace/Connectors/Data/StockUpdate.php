<?php

namespace App\Modules\Marketplace\Connectors\Data;

final class StockUpdate
{
    public function __construct(
        public readonly string $externalProductId,
        public readonly string $externalSkuId,
        public readonly int $quantity,
    ) {}
}
