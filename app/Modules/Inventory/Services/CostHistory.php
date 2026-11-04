<?php

namespace App\Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Weighted average cost of SKUs at a past moment, for stock value reports. The cost at a date is the latest of:
 * the average after the last quantity movement up to that date (stock_movements.avg_cost_after) and the last cost
 * adjustment (CAD) up to that date. SKUs with neither (history recorded before costs were kept on movements)
 * use the current cost.
 */
class CostHistory
{
    /**
     * @param  array<int, int>  $variantIds
     * @param  array<int, string|float|null>  $current  variant id => current cost (fallback)
     * @return array<int, float> variant id => cost at $at
     */
    public function at(int $tenantId, CarbonImmutable $at, array $variantIds, array $current): array
    {
        $result = array_map(fn ($c) => (float) $c, $current);
        // A moment not yet passed (end of today, a future date): today's cost is the cost at that time.
        if ($variantIds === [] || ! $at->isPast()) {
            return $result;
        }

        foreach (array_chunk(array_values(array_unique($variantIds)), 1000) as $ids) {
            // @tenant-bypass: report read filtered by tenant explicitly.
            $lastMovement = DB::table('stock_movements')->where('tenant_id', $tenantId)->whereIn('product_variant_id', $ids)
                ->where('created_at', '<=', $at)->whereNotNull('avg_cost_after')
                ->groupBy('product_variant_id')->selectRaw('MAX(id) as id');
            $movements = DB::table('stock_movements as m')->joinSub($lastMovement, 'x', 'x.id', '=', 'm.id')
                ->get(['m.product_variant_id', 'm.avg_cost_after as cost', 'm.created_at'])->keyBy('product_variant_id');

            $lastAdjustment = DB::table('cost_adjustment_lines as l')->join('cost_adjustments as a', 'a.id', '=', 'l.cost_adjustment_id')
                ->where('a.tenant_id', $tenantId)->whereIn('l.product_variant_id', $ids)->where('a.created_at', '<=', $at)
                ->groupBy('l.product_variant_id')->selectRaw('MAX(l.id) as id');
            $adjustments = DB::table('cost_adjustment_lines as l')->joinSub($lastAdjustment, 'x', 'x.id', '=', 'l.id')
                ->join('cost_adjustments as a', 'a.id', '=', 'l.cost_adjustment_id')
                ->get(['l.product_variant_id', 'l.new_cost as cost', 'a.created_at'])->keyBy('product_variant_id');

            foreach ($ids as $id) {
                $m = $movements->get($id);
                $a = $adjustments->get($id);
                $latest = match (true) {
                    $m && $a => $a->created_at > $m->created_at ? $a : $m,
                    default => $m ?? $a,
                };
                if ($latest !== null) {
                    $result[$id] = (float) $latest->cost;
                }
            }
        }

        return $result;
    }
}
