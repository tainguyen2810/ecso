<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class VariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'options' => $this->options ?? (object) [],
            'ref_price' => $this->ref_price,
            'cost_price' => $this->cost_price,
            'weight_gram' => $this->weight_gram,
            'status' => $this->status,
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->ulid, 'name' => $this->product->name]),
            'stock' => $this->when(isset($this->on_hand), fn () => [
                'on_hand' => (int) $this->on_hand,
                'reserved' => (int) $this->reserved,
                'available' => (int) $this->on_hand - (int) $this->reserved,
            ]),
        ];
    }
}
