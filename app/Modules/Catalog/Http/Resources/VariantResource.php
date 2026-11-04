<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Access\Support\DataAccess;
use App\Modules\Catalog\Models\ProductComponent;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

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
            'price_source' => $this->price_source ?? 'auto',
            'wholesale_price' => $this->wholesale_price,
            'cost_price' => app(DataAccess::class)->cost($this->cost_price),
            'weight_gram' => $this->weight_gram,
            'supplier' => $this->supplier_id ? DB::table('suppliers')->where('id', $this->supplier_id)->first(['ulid as id', 'name']) : null,
            'supplier_sku' => $this->supplier_sku,
            'length_mm' => $this->length_mm,
            'width_mm' => $this->width_mm,
            'height_mm' => $this->height_mm,
            'status' => $this->status,
            'sellable' => $this->whenLoaded('product', fn () => $this->isSellable()),
            'components' => $this->whenLoaded('components', fn () => $this->components->map(fn (ProductComponent $c) => [
                'variant' => ['id' => $c->component->ulid, 'sku' => $c->component->sku, 'name' => $c->component->displayName()],
                'quantity' => $c->quantity,
            ])),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->ulid,
                'name' => $this->product->name,
                // First photo, for the order form's picker (only when the caller loaded product.images).
                'image' => $this->product->relationLoaded('images') ? $this->product->images->first()?->url() : null,
            ]),
            'stock' => $this->when(isset($this->on_hand), fn () => [
                'on_hand' => (int) $this->on_hand,
                'reserved' => (int) $this->reserved,
                'available' => (int) $this->on_hand - (int) $this->reserved,
            ]),
        ];
    }
}
