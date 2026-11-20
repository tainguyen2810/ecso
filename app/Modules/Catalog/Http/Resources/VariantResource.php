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
            'location' => $this->location,
            'name' => $this->name,
            'options' => $this->options ?? (object) [],
            'ref_price' => $this->ref_price,
            'price_source' => $this->price_source ?? 'auto',
            'sourcing' => $this->sourcing ?? 'stock',
            'virtual_stock' => (int) ($this->virtual_stock ?? 0),
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
                'unit' => $this->product->unit,
                // Lot-tracked: receipts ask for lot number + expiry date.
                'track_lots' => (bool) $this->product->track_lots,
                'shelf_life_months' => $this->product->shelf_life_months,
                'require_mfg_date' => (bool) $this->product->require_mfg_date,
                'expiry_warn_days' => $this->product->expiry_warn_days,
                // First photo, for the order form's picker (only when the caller loaded product.images).
                'image' => $this->product->relationLoaded('images') ? $this->product->images->first()?->url() : null,
            ]),
            // The variant's own picture (a colour from the marketplace), when loaded.
            'image' => $this->whenLoaded('image', fn () => $this->image?->url()),
            'stock' => $this->when(isset($this->on_hand), fn () => [
                'on_hand' => (int) $this->on_hand,
                'reserved' => (int) $this->reserved,
                'available' => (int) $this->on_hand - (int) $this->reserved,
            ]),
        ];
    }
}
