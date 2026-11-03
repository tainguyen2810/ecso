<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $linesLoaded = $this->relationLoaded('lines');

        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'status' => $this->status,
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->ulid, 'code' => $this->supplier->code, 'name' => $this->supplier->name]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'expected_at' => $this->expected_at?->toDateString(),
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'total' => $this->total,
            'received_value' => $this->received_value,
            'returned_value' => $this->returned_value,
            'paid_amount' => $this->paid_amount,
            'balance_due' => $this->balanceDue(),
            'note' => $this->note,
            'editable' => app(PurchaseService::class)->editable($this->resource),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'lines_count' => $this->whenCounted('lines'),
            'total_quantity' => $linesLoaded ? $this->lines->sum('quantity') : null,
            'received_quantity' => $linesLoaded ? $this->lines->sum('received_qty') : null,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'variant' => $l->relationLoaded('variant') ? ['id' => $l->variant->ulid, 'sku' => $l->variant->sku, 'name' => $l->variant->displayName()] : null,
                'quantity' => $l->quantity,
                'received_qty' => $l->received_qty,
                'returned_qty' => $l->returned_qty,
                'remaining' => $l->remaining(),
                'unit_cost' => $l->unit_cost,
            ])),
            'receipts' => $this->whenLoaded('receipts', fn () => $this->receipts->map(fn ($r) => [
                'id' => $r->ulid,
                'number' => $r->number,
                'created_at' => $r->created_at?->toIso8601String(),
                'created_by' => $r->creator?->name,
                'total_quantity' => $r->lines->sum('quantity'),
                'total_value' => (string) $r->lines->sum(fn ($l) => $l->quantity * (float) $l->unit_cost),
            ])),
            'payments' => SupplierPaymentResource::collection($this->whenLoaded('payments')),
            'returns' => SupplierReturnResource::collection($this->whenLoaded('returns')),
        ];
    }
}
