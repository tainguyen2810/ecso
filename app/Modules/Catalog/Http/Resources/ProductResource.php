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
            'status' => $this->status,
            'category' => $this->category ? ['id' => $this->category->ulid, 'name' => $this->category->name] : null,
            'brand' => $this->brand ? ['id' => $this->brand->ulid, 'name' => $this->brand->name] : null,
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
            'variants_count' => $this->whenCounted('variants'),
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
