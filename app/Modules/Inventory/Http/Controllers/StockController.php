<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Http\Resources\MovementResource;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    /**
     * Stock per SKU, for one warehouse or summed over all warehouses.
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorize('viewAny', StockBalance::class);
        $filters = $request->validate([
            'warehouse' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(['low', 'out', 'in', 'short'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $warehouse = $this->warehouse($filters['warehouse'] ?? null);

        $balances = DB::table('stock_balances')->where('tenant_id', $context->id())
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->selectRaw('product_variant_id, SUM(on_hand) as on_hand, SUM(reserved) as reserved, SUM(safety_stock) as safety_stock')
            ->groupBy('product_variant_id');

        $available = 'COALESCE(s.on_hand, 0) - COALESCE(s.reserved, 0)';

        $rows = ProductVariant::query()
            ->with('product:id,ulid,name')
            ->whereHas('product', fn ($q) => $q->where('status', '!=', Product::STATUS_ARCHIVED))
            ->leftJoinSub($balances, 's', 's.product_variant_id', '=', 'product_variants.id')
            ->select('product_variants.*',
                DB::raw('COALESCE(s.on_hand, 0) as on_hand'),
                DB::raw('COALESCE(s.reserved, 0) as reserved'),
                DB::raw('COALESCE(s.safety_stock, 0) as safety_stock'))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('sku', 'like', "%{$term}%")->orWhere('barcode', $term)
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"))))
            ->when($filters['state'] ?? null, fn (Builder $q, string $state) => match ($state) {
                'out' => $q->whereRaw("{$available} <= 0"),
                'short' => $q->whereRaw("{$available} < 0"),
                'low' => $q->whereRaw("{$available} > 0 AND {$available} <= COALESCE(s.safety_stock, 0)"),
                'in' => $q->whereRaw("{$available} > 0"),
            })
            ->orderBy('sku')
            ->paginate($filters['per_page'] ?? 50);

        $data = collect($rows->items())->map(fn (ProductVariant $v) => [
            'variant' => ['id' => $v->ulid, 'sku' => $v->sku, 'name' => $v->name, 'barcode' => $v->barcode],
            'product' => ['id' => $v->product->ulid, 'name' => $v->product->name],
            'on_hand' => (int) $v->on_hand,
            'reserved' => (int) $v->reserved,
            'available' => (int) $v->on_hand - (int) $v->reserved,
            'safety_stock' => (int) $v->safety_stock,
            'sellable' => max(0, (int) $v->on_hand - (int) $v->reserved - (int) $v->safety_stock),
            'cost_price' => $v->cost_price,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
        ]);
    }

    public function summary(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorize('viewAny', StockBalance::class);
        $warehouse = $this->warehouse($request->query('warehouse'));

        $base = DB::table('stock_balances')
            ->join('product_variants', 'product_variants.id', '=', 'stock_balances.product_variant_id')
            ->where('stock_balances.tenant_id', $context->id())
            ->whereNull('product_variants.deleted_at')
            ->when($warehouse, fn ($q) => $q->where('stock_balances.warehouse_id', $warehouse->id));

        $totals = (clone $base)->selectRaw('SUM(on_hand) as on_hand, SUM(reserved) as reserved, SUM(GREATEST(on_hand, 0) * product_variants.cost_price) as value')->first();
        $perVariant = (clone $base)->groupBy('product_variant_id')
            ->selectRaw('SUM(on_hand) - SUM(reserved) as available, SUM(safety_stock) as safety');
        $states = DB::query()->fromSub($perVariant, 'v')
            ->selectRaw('SUM(CASE WHEN available > 0 THEN 1 ELSE 0 END) as in_stock, SUM(CASE WHEN available > 0 AND available <= safety THEN 1 ELSE 0 END) as low_stock')
            ->first();

        $skus = ProductVariant::query()->count();

        return response()->json(['data' => [
            'skus' => $skus,
            'on_hand' => (int) ($totals->on_hand ?? 0),
            'reserved' => (int) ($totals->reserved ?? 0),
            'inventory_value' => number_format((float) ($totals->value ?? 0), 0, '.', ''),
            // SKUs without any balance row count as out of stock too.
            'out_of_stock' => max(0, $skus - (int) ($states->in_stock ?? 0)),
            'low_stock' => (int) ($states->low_stock ?? 0),
        ]]);
    }

    /**
     * Stock card (thẻ kho): ledger rows of one SKU, newest first, with balance per warehouse.
     */
    public function card(Request $request, ProductVariant $variant): JsonResponse
    {
        $this->authorize('viewAny', StockBalance::class);
        $filters = $request->validate([
            'warehouse' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $warehouse = $this->warehouse($filters['warehouse'] ?? null);

        $movements = StockMovement::query()->with(['warehouse', 'actor:id,name'])
            ->where('product_variant_id', $variant->id)
            ->where('type', '!=', 'MARKER')
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()))
            ->orderByDesc('id')
            ->paginate(50);

        $this->attachReferenceNumbers($movements->getCollection());

        $balances = StockBalance::query()->with('warehouse')->where('product_variant_id', $variant->id)->get()
            ->map(fn (StockBalance $b) => [
                'warehouse' => ['id' => $b->warehouse->ulid, 'code' => $b->warehouse->code, 'name' => $b->warehouse->name],
                'on_hand' => $b->on_hand,
                'reserved' => $b->reserved,
                'available' => $b->available(),
                'safety_stock' => $b->safety_stock,
            ]);

        $variant->load('product');

        return response()->json([
            'variant' => ['id' => $variant->ulid, 'sku' => $variant->sku, 'name' => $variant->displayName(), 'cost_price' => $variant->cost_price],
            'balances' => $balances,
            'reservations' => StockReservation::query()->where('product_variant_id', $variant->id)->where('status', StockReservation::ACTIVE)->count(),
            'data' => MovementResource::collection($movements->getCollection()),
            'meta' => ['current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'total' => $movements->total()],
        ]);
    }

    public function safetyStock(Request $request, ProductVariant $variant, InventoryService $inventory): JsonResponse
    {
        $this->authorize('configure', StockBalance::class);
        $data = $request->validate([
            'warehouse' => ['required', 'string'],
            'safety_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $warehouse = $this->warehouse($data['warehouse']);
        $balance = $inventory->setSafetyStock($warehouse, $variant, $data['safety_stock']);

        return response()->json(['data' => ['safety_stock' => $balance->safety_stock, 'available' => $balance->available()]]);
    }

    private function warehouse(?string $ulid): ?Warehouse
    {
        return $ulid ? Warehouse::query()->where('ulid', $ulid)->firstOrFail() : null;
    }

    private function attachReferenceNumbers($movements): void
    {
        $models = [
            'StockReceipt' => StockReceipt::class,
            'InventoryAdjustment' => InventoryAdjustment::class,
            'StockTransfer' => StockTransfer::class,
        ];

        foreach ($movements->groupBy('reference_type') as $type => $group) {
            if (! isset($models[$type])) {
                continue;
            }
            $numbers = $models[$type]::query()->whereIn('id', $group->pluck('reference_id'))->pluck('number', 'id');
            $group->each(fn ($m) => $m->setAttribute('reference_number', $numbers[$m->reference_id] ?? null));
        }
    }
}
