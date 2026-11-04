<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Access\Support\DataAccess;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryAdjustmentLine;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
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

        // A count not applied yet shows today's stock next to the stock seen when counting: the difference is what
        // was sold or received in between, which approval keeps.
        $onHandNow = null;
        if ($doc instanceof InventoryAdjustment && in_array($doc->status, [InventoryAdjustment::DRAFT, InventoryAdjustment::PENDING], true) && $doc->relationLoaded('lines')) {
            $onHandNow = StockBalance::query()->where('warehouse_id', $doc->warehouse_id)
                ->whereIn('product_variant_id', $doc->lines->pluck('product_variant_id'))->pluck('on_hand', 'product_variant_id');
        }

        $base = [
            'id' => $doc->ulid,
            'number' => $doc->number,
            'note' => $doc->note,
            'created_by' => $doc->creator?->name,
            'is_mine' => $doc->created_by === $request->user()?->getKey(),
            'created_at' => $doc->created_at?->toIso8601String(),
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $doc->lines->map(fn ($line) => array_filter([
                'line_id' => $line instanceof StockTransferLine ? $line->id : null,
                'variant' => [
                    'id' => $line->variant->ulid,
                    'sku' => $line->variant->sku,
                    'name' => $line->variant->displayName(),
                ],
                'quantity' => $line->quantity,
                'received_qty' => $line->received_qty ?? null,
                'unit_cost' => app(DataAccess::class)->cost($line->unit_cost ?? null),
                'mode' => $line->mode ?? null,
                'system_qty' => $line->system_qty ?? null,
                'on_hand_now' => $line instanceof InventoryAdjustmentLine && $line->mode === InventoryAdjustmentLine::MODE_COUNT && $onHandNow !== null ? (int) ($onHandNow[$line->product_variant_id] ?? 0) : null,
                'on_hand_before' => $line->on_hand_before ?? null,
                'applied_delta' => $line->applied_delta ?? null,
                'reason' => $line->reason ?? null,
                'line_note' => $line->note ?? null,
            ], fn ($v) => $v !== null))),
        ];

        return $base + match (true) {
            $doc instanceof StockReceipt => [
                'kind' => 'receipt',
                'source' => $doc->source,
                'warehouse' => $warehouse($doc->warehouse),
                'total_quantity' => $doc->relationLoaded('lines') ? $doc->lines->sum('quantity') : null,
                'total_value' => $doc->relationLoaded('lines') ? app(DataAccess::class)->cost((string) $doc->lines->sum(fn ($l) => $l->quantity * (float) $l->unit_cost)) : null,
            ],
            $doc instanceof InventoryAdjustment => [
                'kind' => 'adjustment',
                'status' => $doc->status,
                'reason' => $doc->reason,
                'warehouse' => $warehouse($doc->warehouse),
                'decided_by' => $doc->decider?->name,
                'decided_at' => $doc->decided_at?->toIso8601String(),
                'decision_note' => $doc->decision_note,
                'counted_at' => $doc->counted_at?->toIso8601String(),
            ],
            $doc instanceof StockTransfer => [
                'kind' => 'transfer',
                'status' => $doc->status,
                'from_warehouse' => $warehouse($doc->fromWarehouse),
                'to_warehouse' => $warehouse($doc->toWarehouse),
                'dispatched_at' => $doc->dispatched_at?->toIso8601String(),
                'received_at' => $doc->received_at?->toIso8601String(),
                // Pending "thất lạc" adjustment for what did not arrive.
                'shortage' => $doc->shortage_adjustment_id ? InventoryAdjustment::query()->whereKey($doc->shortage_adjustment_id)->first(['ulid', 'number', 'status'])?->only(['ulid', 'number', 'status']) : null,
            ],
            default => [],
        };
    }
}
