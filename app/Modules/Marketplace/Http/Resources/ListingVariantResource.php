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
            'listing_id' => $this->listing?->ulid,
            'external_product_id' => $this->listing?->external_product_id,
            'external_sku_id' => $this->external_sku_id,
            'title' => $this->listing->title ?? $this->name,
            'name' => $this->name,
            'seller_sku' => $this->seller_sku,
            'price' => $this->price,
            'marketplace_stock' => $this->marketplace_stock,
            'mapping_status' => $this->mapping_status,
            'linked_by_sku' => (bool) $this->linked_by_sku,
            // A suggestion whose SKU is not exactly the seller SKU ("SKU khác nhau"): a near match to confirm.
            'near_match' => $this->mapping_status === ListingVariant::SUGGESTED && $this->variant !== null
                && mb_strtolower(trim((string) $this->seller_sku)) !== mb_strtolower($this->variant->sku),
            'image_url' => $this->listing?->raw['variants'][$this->external_sku_id]['image_url'] ?? $this->listing?->image_url,
            // Nothing of its product is linked yet: "Tạo sản phẩm" makes it from the store.
            'creatable' => $this->listing !== null && isset($this->listing->linked_count) && (int) $this->listing->linked_count === 0
                && ! in_array($this->listing->status, ['deleted', 'trash'], true),
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
