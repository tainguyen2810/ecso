<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Controllers\Concerns\ResolvesLines;
use App\Modules\Inventory\Models\CostAdjustment;
use App\Modules\Inventory\Models\CostAdjustmentLine;
use App\Modules\Inventory\Services\InventoryDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cost price adjustments (điều chỉnh giá vốn). Changing cost changes reported profit, so approvers only. */
class CostAdjustmentController extends Controller
{
    use ResolvesLines;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CostAdjustment::class);
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        $page = CostAdjustment::query()->with(['creator:id,name', 'lines.variant.product'])->withCount('lines')
            ->when($q, fn ($query, $term) => $query->where(fn ($w) => $w->where('number', 'like', "%{$term}%")
                ->orWhereHas('lines.variant', fn ($v) => $v->where('sku', 'like', "%{$term}%"))))
            ->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (CostAdjustment $a) => $this->present($a)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request, InventoryDocumentService $documents): JsonResponse
    {
        $this->authorize('configure', CostAdjustment::class);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->lineRules(200),
            'lines.*.new_cost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        $adjustment = $documents->createCostAdjustment($this->resolveLines($data['lines']), $data['note'] ?? null);

        return response()->json(['data' => $this->present($adjustment->load(['creator:id,name', 'lines.variant.product']))], 201);
    }

    public function show(CostAdjustment $costAdjustment): JsonResponse
    {
        $this->authorize('view', $costAdjustment);

        return response()->json(['data' => $this->present($costAdjustment->load(['creator:id,name', 'lines.variant.product']))]);
    }

    /** @return array<string, mixed> */
    private function present(CostAdjustment $a): array
    {
        return [
            'id' => $a->ulid,
            'number' => $a->number,
            'note' => $a->note,
            'creator' => $a->creator?->name,
            'created_at' => $a->created_at?->toIso8601String(),
            'lines' => $a->lines->map(fn (CostAdjustmentLine $l) => [
                'variant' => ['id' => $l->variant->ulid, 'sku' => $l->variant->sku, 'name' => $l->variant->displayName()],
                'old_cost' => $l->old_cost,
                'new_cost' => $l->new_cost,
                'on_hand' => $l->on_hand,
                // Change in the value of the stock on hand when the cost was set.
                'value_change' => bcmul(bcsub((string) $l->new_cost, (string) $l->old_cost, 4), (string) max(0, $l->on_hand), 2),
            ]),
        ];
    }
}
