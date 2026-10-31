<?php

namespace App\Modules\Marketplace\Connectors\Data;

final class ProductPage
{
    /**
     * @param  array<int, ProductData>  $products
     */
    public function __construct(public readonly array $products, public readonly ?string $nextCursor = null) {}
}
