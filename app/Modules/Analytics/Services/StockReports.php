<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Stock on hand at any past date (rebuilt from the movement ledger) and SKUs outside their safety and maximum stock. */
class StockReports
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Quantity per SKU and warehouse at the end of $date, valued at the current weighted average cost.
     *
     * @return array<string, mixed>
     */
    public function onHand(CarbonImmutable $date, ?int $warehouseId = null, ?string $q = null, bool $includeZero = false): array
    {
        $warehouses = DB::table('warehouses')->where('tenant_id', $this->tenant())
            ->when($warehouseId, fn ($w) => $w->where('id', $warehouseId))
            ->orderByDesc('is_default')->orderBy('name')->get(['id', 'ulid', 'name']);

        $cells = DB::table('stock_movements as m')
            ->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('m.tenant_id', $this->tenant())
            ->where('m.created_at', '<=', $date->endOfDay())
            ->when($warehouseId, fn ($w) => $w->where('m.warehouse_id', $warehouseId))
            ->when($q, fn ($w) => $w->where(fn ($s) => $s->where('v.sku', 'like', "%{$q}%")->orWhere('p.name', 'like', "%{$q}%")))
            ->groupBy('m.product_variant_id', 'm.warehouse_id')
            ->selectRaw('m.product_variant_id as variant_id, m.warehouse_id, SUM(m.on_hand_delta) as qty')
            ->get();

        $variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.id', $cells->pluck('variant_id')->unique())
            ->get(['v.id', 'v.ulid', 'v.sku', 'v.name', 'p.unit', 'v.cost_price', 'p.name as product_name'])->keyBy('id');

        $whUlid = $warehouses->pluck('ulid', 'id');
        $rows = [];
        foreach ($cells->groupBy('variant_id') as $variantId => $group) {
            $v = $variants->get($variantId);
            $byWarehouse = [];
            foreach ($group as $cell) {
                if (isset($whUlid[$cell->warehouse_id])) {
                    $byWarehouse[$whUlid[$cell->warehouse_id]] = (int) $cell->qty;
                }
            }
            $qty = array_sum($byWarehouse);
            if ($qty === 0 && ! $includeZero) {
                continue;
            }
            $rows[] = [
                'variant' => ['id' => $v->ulid, 'sku' => $v->sku, 'name' => trim($v->product_name.($v->name ? ' - '.$v->name : ''))],
                'unit' => $v->unit,
                'by_warehouse' => $byWarehouse,
                'quantity' => $qty,
                'unit_cost' => round((float) $v->cost_price, 2),
                'value' => round($qty * (float) $v->cost_price, 2),
            ];
        }
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value'] ?: strcmp((string) $a['variant']['sku'], (string) $b['variant']['sku']));

        $totalValue = array_sum(array_column($rows, 'value'));
        foreach ($rows as &$row) {
            $row['share'] = $totalValue > 0 ? round($row['value'] / $totalValue * 100, 2) : null;
        }
        unset($row);

        $warehouseTotals = [];
        foreach ($warehouses as $w) {
            $warehouseTotals[] = [
                'id' => $w->ulid,
                'name' => $w->name,
                'quantity' => array_sum(array_map(fn ($r) => $r['by_warehouse'][$w->ulid] ?? 0, $rows)),
                'value' => round(array_sum(array_map(fn ($r) => ($r['by_warehouse'][$w->ulid] ?? 0) * $r['unit_cost'], $rows)), 2),
            ];
        }

        return [
            'date' => $date->toDateString(),
            'warehouses' => $warehouseTotals,
            'rows' => $rows,
            'totals' => ['skus' => count($rows), 'quantity' => array_sum(array_column($rows, 'quantity')), 'value' => round($totalValue, 2)],
        ];
    }

    /**
     * Balances whose available quantity (on hand less reserved) is at or below the safety stock set for them,
     * with what sold in the last 30 days to judge how much to reorder.
     *
     * @return array<string, mixed>
     */
    public function lowStock(?int $warehouseId = null): array
    {
        $sold = $this->soldLast30Days($warehouseId);

        $rows = DB::table('stock_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoinSub($sold, 's', fn ($j) => $j->on('s.product_variant_id', '=', 'b.product_variant_id')->on('s.warehouse_id', '=', 'b.warehouse_id'))
            ->where('b.tenant_id', $this->tenant())
            ->where('b.safety_stock', '>', 0)
            ->whereRaw('b.on_hand - b.reserved <= CAST(b.safety_stock AS SIGNED)')
            ->when($warehouseId, fn ($w) => $w->where('b.warehouse_id', $warehouseId))
            ->orderByRaw('(b.on_hand - b.reserved) - CAST(b.safety_stock AS SIGNED)')
            ->limit(2000)
            ->get(['v.ulid', 'v.sku', 'v.name as variant_name', 'v.cost_price', 'p.name as product_name', 'w.ulid as warehouse_id', 'w.name as warehouse', 'b.on_hand', 'b.reserved', 'b.safety_stock', 's.units'])
            ->map(function ($r) {
                $available = (int) $r->on_hand - (int) $r->reserved;
                $sold = (int) ($r->units ?? 0);

                return [
                    'variant' => ['id' => $r->ulid, 'sku' => $r->sku, 'name' => trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : ''))],
                    'warehouse' => ['id' => $r->warehouse_id, 'name' => $r->warehouse],
                    'on_hand' => (int) $r->on_hand,
                    'reserved' => (int) $r->reserved,
                    'available' => $available,
                    'safety_stock' => (int) $r->safety_stock,
                    'shortfall' => (int) $r->safety_stock - $available,
                    'sold_30d' => $sold,
                    // Days of stock left at the last 30 days' pace; null when nothing sold.
                    'days_left' => $sold > 0 ? round(max(0, $available) / ($sold / 30), 1) : null,
                    'out_of_stock' => $available <= 0,
                ];
            })->all();

        return [
            'rows' => $rows,
            'totals' => ['skus' => count($rows), 'out_of_stock' => count(array_filter($rows, fn ($r) => $r['out_of_stock']))],
        ];
    }

    /**
     * Balances holding more than the maximum stock set for them: the excess and the money it ties up.
     *
     * @return array<string, mixed>
     */
    public function overStock(?int $warehouseId = null): array
    {
        $rows = DB::table('stock_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoinSub($this->soldLast30Days($warehouseId), 's', fn ($j) => $j->on('s.product_variant_id', '=', 'b.product_variant_id')->on('s.warehouse_id', '=', 'b.warehouse_id'))
            ->where('b.tenant_id', $this->tenant())
            ->whereNotNull('b.max_stock')
            ->whereRaw('b.on_hand > CAST(b.max_stock AS SIGNED)')
            ->when($warehouseId, fn ($w) => $w->where('b.warehouse_id', $warehouseId))
            ->limit(2000)
            ->get(['v.ulid', 'v.sku', 'v.name as variant_name', 'v.cost_price', 'p.name as product_name', 'w.ulid as warehouse_id', 'w.name as warehouse', 'b.on_hand', 'b.max_stock', 's.units'])
            ->map(function ($r) {
                $excess = (int) $r->on_hand - (int) $r->max_stock;
                $sold = (int) ($r->units ?? 0);

                return [
                    'variant' => ['id' => $r->ulid, 'sku' => $r->sku, 'name' => trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : ''))],
                    'warehouse' => ['id' => $r->warehouse_id, 'name' => $r->warehouse],
                    'on_hand' => (int) $r->on_hand,
                    'max_stock' => (int) $r->max_stock,
                    'excess' => $excess,
                    'excess_value' => round($excess * (float) $r->cost_price, 2),
                    'sold_30d' => $sold,
                    'days_left' => $sold > 0 ? round((int) $r->on_hand / ($sold / 30), 1) : null,
                ];
            })->sortByDesc('excess_value')->values()->all();

        return [
            'rows' => $rows,
            'totals' => ['skus' => count($rows), 'excess' => array_sum(array_column($rows, 'excess')), 'excess_value' => round(array_sum(array_column($rows, 'excess_value')), 2)],
        ];
    }

    /** Units shipped per SKU and warehouse in the last 30 days, as a subquery. */
    private function soldLast30Days(?int $warehouseId)
    {
        return DB::table('stock_movements')->where('tenant_id', $this->tenant())->where('created_at', '>=', CarbonImmutable::now()->subDays(30))
            ->whereIn('type', BusinessReports::STOCK_COLUMNS['sale_out'])
            ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId))
            ->groupBy('product_variant_id', 'warehouse_id')
            ->selectRaw('product_variant_id, warehouse_id, -SUM(on_hand_delta) as units');
    }

    private function tenant(): int
    {
        return $this->context->id();
    }
}
