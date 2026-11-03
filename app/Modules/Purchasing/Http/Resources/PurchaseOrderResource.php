<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
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
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->ulid,
                'code' => $this->supplier->code,
                'name' => $this->supplier->name,
                'contact_name' => $this->supplier->contact_name,
                'phone' => $this->supplier->phone,
                'email' => $this->supplier->email,
                'address' => $this->supplier->address,
                'tax_code' => $this->supplier->tax_code,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->ulid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'expected_at' => $this->expected_at?->toDateString(),
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'extra_cost' => $this->extra_cost,
            'extra_cost_note' => $this->extra_cost_note,
            'extra_cost_to_supplier' => $this->extra_cost_to_supplier,
            'vat_in_cost' => $this->vat_in_cost,
            'total' => $this->total,
            'received_value' => $this->received_value,
            'received_tax' => $this->received_tax,
            'received_extra_cost' => $this->received_extra_cost,
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
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (PurchaseOrderLine $l) => [
                'variant' => $l->relationLoaded('variant') ? ['id' => $l->variant->ulid, 'sku' => $l->variant->sku, 'name' => $l->variant->displayName()] : null,
                'quantity' => $l->quantity,
                'received_qty' => $l->received_qty,
                'returned_qty' => $l->returned_qty,
                'remaining' => $l->remaining(),
                'unit_cost' => $l->unit_cost,
                'tax_rate' => $l->tax_rate,
                // Cost per unit that goes into stock at the ordered price: + VAT when carried in cost + share of costs.
                'landed_unit_cost' => bcadd(bcadd((string) $l->unit_cost, $this->vat_in_cost ? bcdiv(bcmul((string) $l->unit_cost, (string) $l->tax_rate, 6), '100', 4) : '0', 4), app(PurchaseService::class)->extraCostPerUnit($this->resource, $l, $this->lines), 2),
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
