<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Domain\AllocationCalculator;
use App\Modules\Inventory\Models\StockBalance;

/** Read-only stock figures for other modules (marketplace publishing). */
class StockReader
{
    /**
     * Sellable quantity per variant over a set of warehouses (INVENTORY-ENGINE §1).
     *
     * @param  array<int, int>  $warehouseIds
     * @param  array<int, int>  $variantIds
     * @return array<int, int> variant id => sellable (variants without a balance row are 0)
     */
    public function sellable(array $warehouseIds, array $variantIds): array
    {
        $rows = StockBalance::query()->whereIn('warehouse_id', $warehouseIds)->whereIn('product_variant_id', $variantIds)
            ->get(['product_variant_id', 'on_hand', 'reserved', 'safety_stock'])->groupBy('product_variant_id');

        $result = [];
        foreach ($variantIds as $id) {
            $result[$id] = AllocationCalculator::sellable(($rows[$id] ?? collect())->map(fn (StockBalance $b) => [
                'on_hand' => $b->on_hand, 'reserved' => $b->reserved, 'safety_stock' => $b->safety_stock,
            ])->all());
        }

        return $result;
    }
}
