<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Controllers\Concerns\ResolvesLines;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\ReorderSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReorderController extends Controller
{
    use ResolvesLines;

    public function index(Request $request, ReorderSuggestions $suggestions): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'string'],
            'cover_days' => ['nullable', 'integer', 'min:1', 'max:180'],
            'lookback_days' => ['nullable', 'integer', 'min:7', 'max:180'],
        ]);
        $warehouse = ($data['warehouse_id'] ?? null) ? $this->findWarehouse($data['warehouse_id'], assigned: false)
            : Warehouse::query()->where('is_default', true)->first() ?? Warehouse::query()->orderBy('id')->firstOrFail();
        $cover = (int) ($data['cover_days'] ?? 14);
        $lookback = (int) ($data['lookback_days'] ?? 30);

        $rows = $suggestions->compute($warehouse->id, $cover, $lookback);
        $suppliers = Supplier::query()->whereIn('id', array_filter(array_column($rows, 'supplier_id')))->get(['id', 'ulid', 'name'])->keyBy('id');

        return response()->json([
            'data' => array_map(fn ($r) => [
                'variant' => ['id' => $r['variant_ulid'], 'sku' => $r['sku'], 'name' => trim($r['name'].($r['variant_name'] ? ' - '.$r['variant_name'] : ''))],
                'sold' => $r['sold'],
                'daily' => $r['daily'],
                'available' => $r['available'],
                'safety_stock' => $r['safety_stock'],
                'on_order' => $r['on_order'],
                'suggested' => $r['suggested'],
                'unit_cost' => $r['unit_cost'],
                'supplier' => ($s = $suppliers->get($r['supplier_id'])) ? ['id' => $s->ulid, 'name' => $s->name] : null,
            ], $rows),
            'meta' => ['warehouse' => ['id' => $warehouse->ulid, 'name' => $warehouse->name], 'cover_days' => $cover, 'lookback_days' => $lookback],
        ]);
    }
}
