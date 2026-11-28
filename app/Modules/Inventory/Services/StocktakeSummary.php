<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryAdjustmentLine;
use App\Shared\Support\ReportRange;

/**
 * Figures above "Kiểm hàng" (mẫu 28/11): sheets per status, sheets being counted, what this month's balanced sheets
 * found short or over (and its value at today's cost), and variants nobody has counted for a while, by category.
 */
class StocktakeSummary
{
    public const STALE_DAYS = 60;

    /** @return array<string, mixed> */
    public function get(): array
    {
        $sheets = fn () => InventoryAdjustment::query()->where('reason', 'stocktake');
        $counts = $sheets()->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $month = ReportRange::now()->startOfMonth();
        $balanced = $sheets()->where('status', InventoryAdjustment::APPROVED)->where('decided_at', '>=', $month);
        $lines = InventoryAdjustmentLine::query()->whereIn('inventory_adjustment_id', (clone $balanced)->select('id'))
            ->join('product_variants as v', 'v.id', '=', 'inventory_adjustment_lines.product_variant_id')
            ->selectRaw('COUNT(*) as skus')
            ->selectRaw('COALESCE(SUM(CASE WHEN applied_delta < 0 THEN -applied_delta ELSE 0 END), 0) as short_qty')
            ->selectRaw('COALESCE(SUM(CASE WHEN applied_delta > 0 THEN applied_delta ELSE 0 END), 0) as surplus_qty')
            ->selectRaw('COALESCE(SUM(CASE WHEN applied_delta < 0 THEN -applied_delta * v.cost_price ELSE 0 END), 0) as short_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN applied_delta > 0 THEN applied_delta * v.cost_price ELSE 0 END), 0) as surplus_value')
            ->toBase()->first();

        // Counted recently = on a stocktake balanced in the last STALE_DAYS days.
        $recent = InventoryAdjustmentLine::query()->whereIn('inventory_adjustment_id', $sheets()
            ->where('status', InventoryAdjustment::APPROVED)->where('decided_at', '>=', ReportRange::now()->subDays(self::STALE_DAYS))->select('id'))
            ->select('product_variant_id');
        $stale = ProductVariant::query()->where('product_variants.status', 'active')->whereNotIn('product_variants.id', $recent)
            ->whereHas('product', fn ($p) => $p->where('type', Product::TYPE_STANDARD));
        $byCategory = (clone $stale)->join('products as p', 'p.id', '=', 'product_variants.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->groupBy('c.name')->selectRaw('c.name as name, COUNT(*) as n')->orderByDesc('n')->limit(4)->toBase()->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n])->all();

        return [
            'counts' => [
                'all' => (int) $counts->sum(),
                'draft' => $counts[InventoryAdjustment::DRAFT] ?? 0,
                'pending' => $counts[InventoryAdjustment::PENDING] ?? 0,
                'approved' => $counts[InventoryAdjustment::APPROVED] ?? 0,
                'cancelled' => ($counts[InventoryAdjustment::CANCELLED] ?? 0) + ($counts[InventoryAdjustment::REJECTED] ?? 0),
            ],
            'month' => [
                'sheets' => (clone $balanced)->count(),
                'skus' => (int) $lines->skus,
                'short_qty' => (int) $lines->short_qty,
                'surplus_qty' => (int) $lines->surplus_qty,
                'short_value' => round((float) $lines->short_value, 2),
                'surplus_value' => round((float) $lines->surplus_value, 2),
            ],
            'stale' => ['days' => self::STALE_DAYS, 'count' => (clone $stale)->count(), 'by_category' => $byCategory],
        ];
    }
}
