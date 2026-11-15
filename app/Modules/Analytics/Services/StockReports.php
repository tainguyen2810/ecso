<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Access\Support\DataAccess;
use App\Modules\Analytics\Support\Search;
use App\Modules\Inventory\Services\CostHistory;
use App\Modules\Inventory\Services\LotLedger;
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
            ->when($q, fn ($w) => $w->where(fn ($s) => $s->where('v.sku', 'like', Search::contains($q))->orWhere('p.name', 'like', Search::contains($q))))
            ->groupBy('m.product_variant_id', 'm.warehouse_id')
            ->selectRaw('m.product_variant_id as variant_id, m.warehouse_id, SUM(m.on_hand_delta) as qty')
            ->get();

        $variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.id', $cells->pluck('variant_id')->unique())
            ->get(['v.id', 'v.ulid', 'v.sku', 'v.name', 'p.unit', 'v.cost_price', 'p.name as product_name'])->keyBy('id');

        // Value at the cost of that date, not today's (CostHistory).
        $costs = app(CostHistory::class)->at($this->tenant(), $date->endOfDay(), $variants->keys()->map(fn ($id) => (int) $id)->all(), $variants->map(fn ($v) => $v->cost_price)->all());

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
                'unit_cost' => round($costs[$variantId] ?? (float) $v->cost_price, 2),
                'value' => round($qty * ($costs[$variantId] ?? (float) $v->cost_price), 2),
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

    /**
     * Approved stock adjustments (stocktakes, write-offs, corrections) decided in the range: what went up, what went
     * down and its value at the current average cost, per document, per SKU or per reason.
     *
     * @return array<string, mixed>
     */
    public function adjustments(CarbonImmutable $from, CarbonImmutable $to, string $by = 'document', ?int $warehouseId = null): array
    {
        $base = DB::table('inventory_adjustment_lines as l')
            ->join('inventory_adjustments as a', 'a.id', '=', 'l.inventory_adjustment_id')
            ->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')
            ->where('a.tenant_id', $this->tenant())
            ->where('a.status', 'approved')
            ->whereBetween('a.decided_at', [$from, $to])
            ->when($warehouseId, fn ($w) => $w->where('a.warehouse_id', $warehouseId));
        $metrics = 'COUNT(DISTINCT a.id) as documents, SUM(CASE WHEN l.applied_delta > 0 THEN l.applied_delta ELSE 0 END) as increase, '
            .'SUM(CASE WHEN l.applied_delta < 0 THEN -l.applied_delta ELSE 0 END) as decrease, SUM(COALESCE(l.applied_delta, 0) * v.cost_price) as value';

        $rows = match ($by) {
            'product' => (clone $base)->join('products as p', 'p.id', '=', 'v.product_id')
                ->groupBy('l.product_variant_id', 'v.ulid', 'v.sku', 'v.name', 'p.name')
                ->selectRaw("v.ulid as id, v.sku, TRIM(CONCAT(p.name, CASE WHEN v.name IS NULL OR v.name = '' THEN '' ELSE CONCAT(' - ', v.name) END)) as label, NULL as warehouse, NULL as decided_at, {$metrics}")
                ->orderBy('value')->limit(2000)->get(),
            'reason' => (clone $base)->groupBy('a.reason')
                ->selectRaw("a.reason as label, NULL as id, NULL as sku, NULL as warehouse, NULL as decided_at, {$metrics}")->orderBy('value')->get(),
            default => (clone $base)->join('warehouses as w', 'w.id', '=', 'a.warehouse_id')
                ->groupBy('a.id', 'a.ulid', 'a.number', 'a.reason', 'a.decided_at', 'w.name')
                ->selectRaw("a.ulid as id, a.number as label, a.reason as sku, w.name as warehouse, a.decided_at, {$metrics}")
                ->orderByDesc('a.decided_at')->limit(2000)->get(),
        };

        $out = $rows->map(fn ($r) => [
            'id' => $r->id,
            'label' => (string) $r->label,
            'sku' => $r->sku,
            'warehouse' => $r->warehouse,
            'decided_at' => $r->decided_at ? CarbonImmutable::parse($r->decided_at)->toIso8601String() : null,
            'documents' => (int) $r->documents,
            'increase' => (int) $r->increase,
            'decrease' => (int) $r->decrease,
            'value' => round((float) $r->value, 2) + 0.0,
        ])->all();

        $totals = ['documents' => (int) (clone $base)->distinct()->count('a.id')];
        foreach (['increase', 'decrease', 'value'] as $k) {
            $totals[$k] = round(array_sum(array_column($out, $k)), 2);
        }

        return ['range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'by' => $by, 'rows' => $out, 'totals' => $totals];
    }

    /** Units shipped per SKU and warehouse in the last 30 days, as a subquery. */
    /** Tabs of the "Hàng sắp hết hạn" report: days left up to (inclusive), null = no upper bound. */
    public const EXPIRY_TABS = ['expired' => null, 'd30' => 30, 'd60' => 60, 'd90' => 90, 'unknown' => null, 'all' => null];

    /**
     * Lots of lot-tracked products still in stock, by how soon they expire (Báo cáo › Hàng sắp hết hạn).
     * "Tồn khi hết hạn" estimates what will be left on the expiry date: lots sell first-expiry-first, so the
     * earlier lots of the same SKU and warehouse use up the sales pace (last 30 days) before this one.
     *
     * @return array<string, mixed>
     */
    public function expiry(string $tab = 'd30', ?int $warehouseId = null, ?string $q = null): array
    {
        $today = LotLedger::today();
        $sold = $this->soldLast30Days($warehouseId);
        $lots = DB::table('stock_lots as l')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoinSub($sold, 's', fn ($j) => $j->on('s.product_variant_id', '=', 'l.product_variant_id')->on('s.warehouse_id', '=', 'l.warehouse_id'))
            ->where('l.tenant_id', $this->tenant())->where('p.track_lots', true)->where('l.on_hand', '>', 0)
            ->whereNull('v.deleted_at')
            ->when($warehouseId, fn ($w) => $w->where('l.warehouse_id', $warehouseId))
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('v.sku', 'like', Search::contains($q))->orWhere('p.name', 'like', Search::contains($q))->orWhere('l.lot_no', 'like', Search::contains($q))))
            ->orderByRaw("l.warehouse_id, l.product_variant_id, l.lot_no <> '', l.expiry_date IS NULL, l.expiry_date, l.id")
            ->limit(5000)
            ->get(['l.id', 'l.lot_no', 'l.mfg_date', 'l.expiry_date', 'l.on_hand', 'l.reserved', 'l.warehouse_id', 'l.product_variant_id',
                'v.ulid', 'v.sku', 'v.name as variant_name', 'v.cost_price', 'p.ulid as product_id', 'p.name as product_name',
                'p.expiry_warn_days', 'p.expiry_block_days', 'w.ulid as warehouse_ulid', 'w.name as warehouse', 's.units']);

        $all = [];
        $before = [];
        foreach ($lots as $r) {
            $pair = "{$r->warehouse_id}:{$r->product_variant_id}";
            $earlier = $before[$pair] ?? 0;
            $before[$pair] = $earlier + (int) $r->on_hand;
            $daysLeft = $r->expiry_date ? (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($r->expiry_date), false) : null;
            $pace = ((int) ($r->units ?? 0)) / 30;
            $leftAtExpiry = $daysLeft === null ? null : (int) max(0, min((int) $r->on_hand, ceil($earlier + (int) $r->on_hand - $pace * max(0, $daysLeft))));
            $block = (int) ($r->expiry_block_days ?? 0);
            $all[] = [
                'lot_id' => (int) $r->id,
                'variant' => ['id' => $r->ulid, 'product_id' => $r->product_id, 'sku' => $r->sku, 'name' => trim($r->product_name.($r->variant_name ? ' - '.$r->variant_name : ''))],
                'warehouse' => ['id' => $r->warehouse_ulid, 'name' => $r->warehouse],
                'lot_no' => $r->lot_no === '' ? null : $r->lot_no,
                'mfg_date' => $r->mfg_date,
                'expiry_date' => $r->expiry_date,
                'days_left' => $daysLeft,
                'on_hand' => (int) $r->on_hand,
                'reserved' => (int) $r->reserved,
                'value' => app(DataAccess::class)->cost(bcmul((string) $r->on_hand, (string) ($r->cost_price ?? '0'), 2)),
                'sold_per_day' => round($pace, 1),
                'left_at_expiry' => $leftAtExpiry,
                'blocked' => $daysLeft !== null && $daysLeft < $block,
                'warn' => $daysLeft !== null && $daysLeft <= (int) ($r->expiry_warn_days ?? LotLedger::DEFAULT_WARN_DAYS),
            ];
        }

        $bucket = fn (array $r) => match (true) {
            $r['days_left'] === null => 'unknown',
            $r['days_left'] < 0 => 'expired',
            $r['days_left'] <= 30 => 'd30',
            $r['days_left'] <= 60 => 'd60',
            $r['days_left'] <= 90 => 'd90',
            default => 'later',
        };
        $totals = [];
        foreach (['expired', 'd30', 'd60', 'd90', 'unknown'] as $k) {
            $in = array_filter($all, fn ($r) => $bucket($r) === $k);
            $totals[$k] = [
                'lots' => count($in),
                'units' => array_sum(array_column($in, 'on_hand')),
                'value' => app(DataAccess::class)->cost(array_reduce($in, fn ($c, $r) => bcadd($c, (string) ($r['value'] ?? '0'), 2), '0')),
            ];
        }

        $rows = array_values(array_filter($all, fn ($r) => match ($tab) {
            'all' => true,
            'expired', 'unknown' => $bucket($r) === $tab,
            default => $r['days_left'] !== null && $r['days_left'] >= 0 && $r['days_left'] <= self::EXPIRY_TABS[$tab],
        }));
        usort($rows, fn ($a, $b) => [$a['days_left'] === null, $a['days_left']] <=> [$b['days_left'] === null, $b['days_left']]);

        return [
            'rows' => $rows,
            'totals' => $totals + [
                'on_hand' => array_sum(array_column($rows, 'on_hand')),
                'value' => app(DataAccess::class)->cost(array_reduce($rows, fn ($c, $r) => bcadd($c, (string) ($r['value'] ?? '0'), 2), '0')),
            ],
        ];
    }

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
