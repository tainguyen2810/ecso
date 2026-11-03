<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\SupplierReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierReturn */
class SupplierReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'total' => $this->total,
            'tax_amount' => $this->tax_amount,
            'reason' => $this->reason,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->ulid, 'code' => $this->supplier->code, 'name' => $this->supplier->name]),
            'order' => $this->whenLoaded('order', fn () => $this->order ? ['id' => $this->order->ulid, 'number' => $this->order->number] : null),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->ulid, 'name' => $this->warehouse->name]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'variant' => ['id' => $l->variant->ulid, 'sku' => $l->variant->sku, 'name' => $l->variant->displayName()],
                'quantity' => $l->quantity,
                'unit_cost' => $l->unit_cost,
                'tax_rate' => $l->tax_rate,
            ])),
        ];
    }
}
