<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\OrderReturnLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderReturn */
class ReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'type' => $this->type,
            'status' => $this->status,
            'reason' => $this->reason,
            'refund_amount' => $this->refund_amount,
            'note' => $this->note,
            'expects_goods' => $this->expectsGoods(),
            'next' => OrderReturn::FLOW[$this->status] ?? [],
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->ulid,
                'external_order_id' => $this->order->external_order_id,
                'status' => $this->order->status,
                'marketplace' => $this->order->marketplace,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (OrderReturnLine $l) => [
                'id' => $l->id,
                'name' => $l->item?->name,
                'variant_name' => $l->item?->variant_name,
                'sku' => $l->variant?->sku ?? $l->item?->seller_sku,
                'mapped' => $l->product_variant_id !== null,
                'quantity' => $l->quantity,
                'received_qty' => $l->received_qty,
                'sellable_qty' => $l->sellable_qty,
                'damaged_qty' => $l->damaged_qty,
            ])),
            'lines_count' => $this->whenCounted('lines'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'inspected_by' => $this->whenLoaded('inspector', fn () => $this->inspector?->name),
            'received_at' => $this->received_at?->toIso8601String(),
            'inspected_at' => $this->inspected_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
