<?php

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Marketplace\Models\ListingVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ListingVariant */
class ListingVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'external_product_id' => $this->listing?->external_product_id,
            'external_sku_id' => $this->external_sku_id,
            'title' => $this->listing?->title ?? $this->name,
            'name' => $this->name,
            'seller_sku' => $this->seller_sku,
            'price' => $this->price,
            'marketplace_stock' => $this->marketplace_stock,
            'mapping_status' => $this->mapping_status,
            'variant' => $this->variant ? [
                'id' => $this->variant->ulid,
                'sku' => $this->variant->sku,
                'name' => $this->variant->displayName(),
            ] : null,
            'last_pushed_qty' => $this->last_pushed_qty,
            'last_pushed_at' => $this->last_pushed_at?->toIso8601String(),
            'push_status' => $this->push_status,
            'push_error' => $this->push_error,
            'mapped_at' => $this->mapped_at?->toIso8601String(),
        ];
    }
}
