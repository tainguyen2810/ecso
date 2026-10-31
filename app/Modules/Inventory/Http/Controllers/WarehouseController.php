<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    public function index(TenantContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Warehouse::class);

        $totals = DB::table('stock_balances')->where('tenant_id', $context->id())
            ->selectRaw('warehouse_id, SUM(CASE WHEN on_hand <> 0 THEN 1 ELSE 0 END) as sku_count, SUM(on_hand) as total_on_hand')
            ->groupBy('warehouse_id');

        $warehouses = Warehouse::query()
            ->leftJoinSub($totals, 't', 't.warehouse_id', '=', 'warehouses.id')
            ->select('warehouses.*', DB::raw('COALESCE(t.sku_count, 0) as sku_count'), DB::raw('COALESCE(t.total_on_hand, 0) as total_on_hand'))
            ->orderByDesc('is_default')->orderBy('code')->get();

        $limit = $context->entitlements()->limit('limit.warehouses');

        return WarehouseResource::collection($warehouses)->additional(['meta' => ['limit' => $limit, 'used' => $warehouses->count()]]);
    }

    public function store(Request $request, WarehouseService $service, TenantContext $context): JsonResponse
    {
        $this->authorize('create', Warehouse::class);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('warehouses', 'code')->where('tenant_id', $context->id())],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        $data['code'] = strtoupper($data['code']);

        return (new WarehouseResource($service->create($data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Warehouse $warehouse, WarehouseService $service): WarehouseResource
    {
        $this->authorize('update', $warehouse);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([Warehouse::STATUS_ACTIVE, Warehouse::STATUS_INACTIVE])],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        return new WarehouseResource($service->update($warehouse, $data));
    }

    public function destroy(Warehouse $warehouse, WarehouseService $service): JsonResponse
    {
        $this->authorize('delete', $warehouse);
        $service->delete($warehouse);

        return response()->json(null, 204);
    }
}
