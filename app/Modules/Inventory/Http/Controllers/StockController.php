<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductComponent;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ComboResolver;
use App\Modules\Inventory\Http\Resources\MovementResource;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\LowStockQuery;
use App\Modules\Inventory\Services\StockReader;
use App\Modules\Purchasing\Services\OnOrderQuery;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
            ->whereHas('product', fn ($q) => $q->where('status', '!=', Product::STATUS_ARCHIVED)->where('type', '!=', Product::TYPE_COMBO))
            ->whereDoesntHave('components') // pack sizes have no stock of their own
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
                default => $q,
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

    /** SKUs at or below their safety stock, most urgent first (dashboard alert). */
    public function lowStock(TenantContext $context, LowStockQuery $query): JsonResponse
    {
        $this->authorize('viewAny', StockBalance::class);
        $items = $query->forTenant($context->id(), 200);

        return response()->json(['data' => array_slice($items, 0, 10), 'meta' => ['total' => count($items)]]);
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

        $skus = ProductVariant::query()->whereHas('product', fn ($q) => $q->where('type', '!=', Product::TYPE_COMBO))->whereDoesntHave('components')->count();

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

    /**
     * Stock of every SKU of one product in every active warehouse (product page): on hand, held, available,
     * safety stock and goods still on order from suppliers.
     */
    public function productStock(Product $product, OnOrderQuery $onOrder): JsonResponse
    {
        $this->authorize('viewAny', StockBalance::class);

        $variants = $product->variants()->get();
        $warehouses = Warehouse::query()->where('status', Warehouse::STATUS_ACTIVE)->orderByDesc('is_default')->orderBy('name')->get();

        if ($product->isCombo()) {
            return response()->json([
                'data' => $this->comboStock($variants->first(), $warehouses),
                'warehouses' => $warehouses->map(fn (Warehouse $w) => ['id' => $w->ulid, 'name' => $w->name]),
            ]);
        }

        $balances = StockBalance::query()->whereIn('product_variant_id', $variants->pluck('id'))->get()
            ->groupBy('product_variant_id');
        $incoming = $onOrder->byVariantAndWarehouse($variants->pluck('id')->all());
        $packs = app(ComboResolver::class)->componentsOf($variants->pluck('id')->all());

        $rows = [];
        foreach ($variants as $variant) {
            if (isset($packs[$variant->id])) {
                // A pack size: whole packs the base stock makes, in each warehouse.
                array_push($rows, ...$this->comboStock($variant, $warehouses));

                continue;
            }
            $byWarehouse = ($balances->get($variant->id) ?? collect())->keyBy('warehouse_id');
            foreach ($warehouses as $warehouse) {
                $b = $byWarehouse->get($warehouse->id);
                $rows[] = [
                    'variant' => ['id' => $variant->ulid, 'sku' => $variant->sku, 'name' => $variant->name],
                    'warehouse' => ['id' => $warehouse->ulid, 'code' => $warehouse->code, 'name' => $warehouse->name],
                    'on_hand' => (int) ($b->on_hand ?? 0),
                    'reserved' => (int) ($b->reserved ?? 0),
                    'available' => $b ? $b->available() : 0,
                    'safety_stock' => (int) ($b->safety_stock ?? 0),
                    'max_stock' => $b?->max_stock,
                    'bin_location' => $b?->bin_location,
                    'incoming' => $incoming[$variant->id][$warehouse->id] ?? 0,
                    'cost_price' => $variant->cost_price,
                ];
            }
        }

        return response()->json([
            'data' => $rows,
            'warehouses' => $warehouses->map(fn (Warehouse $w) => ['id' => $w->ulid, 'name' => $w->name]),
        ]);
    }

    /**
     * A combo per warehouse: how many whole combos the components make (available) and the cost of one combo.
     *
     * @param  Collection<int, Warehouse>  $warehouses
     * @return array<int, array<string, mixed>>
     */
    private function comboStock(?ProductVariant $combo, Collection $warehouses): array
    {
        if ($combo === null) {
            return [];
        }
        $reader = app(StockReader::class);
        $cost = $combo->components()->with('component')->get()
            ->reduce(fn (string $sum, ProductComponent $c) => bcadd($sum, bcmul((string) $c->component->cost_price, (string) $c->quantity, 4), 4), '0');

        return $warehouses->map(function (Warehouse $warehouse) use ($combo, $reader, $cost) {
            $available = $reader->available([$combo->id], $warehouse->id)[$combo->id] ?? 0;

            return [
                'variant' => ['id' => $combo->ulid, 'sku' => $combo->sku, 'name' => $combo->name],
                'warehouse' => ['id' => $warehouse->ulid, 'code' => $warehouse->code, 'name' => $warehouse->name],
                'on_hand' => $available,
                'reserved' => 0,
                'available' => $available,
                'safety_stock' => 0,
                'incoming' => 0,
                'cost_price' => $cost,
            ];
        })->values()->all();
    }

    /** Ledger rows of all SKUs of one product, newest first (product page, "lịch sử kho"). A combo shows its components'. */
    public function productMovements(Request $request, Product $product): JsonResponse
    {
        $this->authorize('viewAny', StockBalance::class);
        $filters = $request->validate(['warehouse' => ['nullable', 'string']]);
        $warehouse = $this->warehouse($filters['warehouse'] ?? null);
        $variantIds = $product->variants()->withTrashed()->pluck('id')->all();
        if ($product->isCombo()) {
            $variantIds = array_merge(...array_values(array_map('array_keys', app(ComboResolver::class)->componentsOf($variantIds))) ?: [[]]);
        }

        $movements = StockMovement::query()->with(['warehouse', 'actor:id,name', 'variant:id,ulid,sku,name'])
            ->whereIn('product_variant_id', $variantIds)
            ->where('type', '!=', 'MARKER')
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->orderByDesc('id')
            ->paginate(20);

        $this->attachReferenceNumbers($movements->getCollection());

        return response()->json([
            'data' => $movements->getCollection()->map(fn (StockMovement $m) => [
                ...(new MovementResource($m))->resolve(),
                'variant' => ['id' => $m->variant->ulid, 'sku' => $m->variant->sku, 'name' => $m->variant->name],
            ]),
            'meta' => ['current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'total' => $movements->total()],
        ]);
    }

    /** Bin location (staff who adjust stock) and maximum stock (approvers: it drives purchasing). */
    public function location(Request $request, ProductVariant $variant, InventoryService $inventory): JsonResponse
    {
        $this->authorize('adjust', StockBalance::class);
        $data = $request->validate([
            'warehouse' => ['required', 'string'],
            'bin_location' => ['sometimes', 'nullable', 'string', 'max:50'],
            'max_stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        if (array_key_exists('max_stock', $data)) {
            $this->authorize('configure', StockBalance::class);
        }
        $balance = $inventory->setLocation($this->warehouse($data['warehouse']), $variant, $data);

        return response()->json(['data' => ['bin_location' => $balance->bin_location, 'max_stock' => $balance->max_stock]]);
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
