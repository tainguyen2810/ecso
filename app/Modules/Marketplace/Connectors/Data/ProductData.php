<?php

namespace App\Modules\Marketplace\Connectors\Data;

/** A marketplace listing with its SKUs. */
final class ProductData
{
    /**
     * @param  array<int, array{external_sku_id: string, seller_sku: ?string, name: ?string, price: string, stock: ?int, regular_price?: ?string, sale_price?: ?string, options?: array<string, string>, weight_gram?: ?int, barcode?: ?string}>  $variants
     * @param  array{description?: ?string, categories?: array<int, string>, weight_gram?: ?int}  $details  what a new ECSO product is created with
     */
    public function __construct(
        public readonly string $externalProductId,
        public readonly string $title,
        public readonly array $variants,
        public readonly ?string $status = null,
        public readonly ?string $imageUrl = null,
        public readonly array $details = [],
    ) {}
}
