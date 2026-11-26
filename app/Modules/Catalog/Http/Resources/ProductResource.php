<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type ?? Product::TYPE_STANDARD,
            'subscription' => $this->type === Product::TYPE_SUBSCRIPTION ? $this->resource->subscriptionRules() : null,
            'unit' => $this->unit,
            'tags' => $this->tags ?? [],
            'vat_rate' => $this->vat_rate,
            'status' => $this->status,
            'track_lots' => (bool) $this->track_lots,
            'expiry_warn_days' => $this->expiry_warn_days,
            'expiry_block_days' => $this->expiry_block_days,
            'shelf_life_months' => $this->shelf_life_months,
            'require_mfg_date' => (bool) $this->require_mfg_date,
            'category' => $this->category ? ['id' => $this->category->ulid, 'name' => $this->category->name] : null,
            'brand' => $this->brand ? ['id' => $this->brand->ulid, 'name' => $this->brand->name] : null,
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
            'variants_count' => $this->whenCounted('variants'),
            // "Bán trên": linked marketplace shops (product list only).
            'sold_on' => $this->when($this->getAttribute('sold_on') !== null, fn () => $this->getAttribute('sold_on')),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url()])),
            'stock' => $this->when(isset($this->on_hand), fn () => [
                'on_hand' => (int) $this->on_hand,
                'reserved' => (int) $this->reserved,
                'available' => (int) $this->on_hand - (int) $this->reserved,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
