<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Support\DataAccess;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Http\Controllers\Concerns\ResolvesLines;
use App\Modules\Inventory\Http\Resources\DocumentResource;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Services\InventoryDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReceiptController extends Controller
{
    use ResolvesLines;

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockReceipt::class);

        return DocumentResource::collection(
            StockReceipt::query()->with(['warehouse', 'creator:id,name', 'lines'])->withCount('lines')->orderByDesc('id')->paginate(20)
        );
    }

    public function store(Request $request, InventoryDocumentService $documents, DataAccess $access): JsonResponse
    {
        $this->authorize('receive', StockReceipt::class);
        $data = $request->validate([
            'warehouse_id' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->lineRules(),
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => [$access->seesCost() ? 'required' : 'nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        $lines = $this->resolveLines($data['lines']);
        // Without "products.cost.view" the unit cost is not the member's to set: goods come in at today's average cost,
        // which leaves the average unchanged.
        if (! $access->seesCost()) {
            $costs = ProductVariant::query()->whereIn('id', array_column($lines, 'variant_id'))->pluck('cost_price', 'id');
            $lines = array_map(fn ($l) => ['unit_cost' => (string) ($costs[$l['variant_id']] ?? '0')] + $l, $lines);
        }

        $receipt = $documents->receive($this->findWarehouse($data['warehouse_id']), $lines, $data['note'] ?? null);

        return (new DocumentResource($receipt->load(['warehouse', 'creator', 'lines.variant.product'])))->response()->setStatusCode(201);
    }

    public function show(StockReceipt $receipt): DocumentResource
    {
        $this->authorize('view', $receipt);

        return new DocumentResource($receipt->load(['warehouse', 'creator', 'lines.variant.product']));
    }
}
