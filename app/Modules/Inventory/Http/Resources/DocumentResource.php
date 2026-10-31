<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Receipts, adjustments and transfers share one shape so the UI can reuse list and detail components.
 *
 * @mixin StockReceipt|InventoryAdjustment|StockTransfer
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $doc = $this->resource;
        $warehouse = fn ($w) => $w ? ['id' => $w->ulid, 'code' => $w->code, 'name' => $w->name] : null;

        $base = [
            'id' => $doc->ulid,
            'number' => $doc->number,
            'note' => $doc->note,
            'created_by' => $doc->creator?->name,
            'is_mine' => $doc->created_by === $request->user()?->getKey(),
            'created_at' => $doc->created_at?->toIso8601String(),
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $doc->lines->map(fn ($line) => array_filter([
                'variant' => [
                    'id' => $line->variant->ulid,
                    'sku' => $line->variant->sku,
                    'name' => $line->variant->displayName(),
                ],
                'quantity' => $line->quantity,
                'unit_cost' => $line->unit_cost ?? null,
                'mode' => $line->mode ?? null,
                'on_hand_before' => $line->on_hand_before ?? null,
                'applied_delta' => $line->applied_delta ?? null,
            ], fn ($v) => $v !== null))),
        ];

        return $base + match (true) {
            $doc instanceof StockReceipt => [
                'kind' => 'receipt',
                'source' => $doc->source,
                'warehouse' => $warehouse($doc->warehouse),
                'total_quantity' => $doc->relationLoaded('lines') ? $doc->lines->sum('quantity') : null,
                'total_value' => $doc->relationLoaded('lines') ? (string) $doc->lines->sum(fn ($l) => $l->quantity * (float) $l->unit_cost) : null,
            ],
            $doc instanceof InventoryAdjustment => [
                'kind' => 'adjustment',
                'status' => $doc->status,
                'reason' => $doc->reason,
                'warehouse' => $warehouse($doc->warehouse),
                'decided_by' => $doc->decider?->name,
                'decided_at' => $doc->decided_at?->toIso8601String(),
                'decision_note' => $doc->decision_note,
            ],
            $doc instanceof StockTransfer => [
                'kind' => 'transfer',
                'status' => $doc->status,
                'from_warehouse' => $warehouse($doc->fromWarehouse),
                'to_warehouse' => $warehouse($doc->toWarehouse),
                'dispatched_at' => $doc->dispatched_at?->toIso8601String(),
                'received_at' => $doc->received_at?->toIso8601String(),
            ],
            default => [],
        };
    }
}
