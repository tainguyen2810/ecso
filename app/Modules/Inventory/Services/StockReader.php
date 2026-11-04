<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Services\ComboResolver;
use App\Modules\Inventory\Domain\AllocationCalculator;
use App\Modules\Inventory\Models\StockBalance;

/** Read-only stock figures for other modules (marketplace publishing). */
class StockReader
{
    public function __construct(private readonly ComboResolver $combos) {}

    /**
     * Sellable quantity per variant over a set of warehouses (INVENTORY-ENGINE §1). A combo's sellable quantity is
     * the number of whole combos its components' sellable stock makes.
     *
     * @param  array<int, int>  $warehouseIds
     * @param  array<int, int>  $variantIds
     * @return array<int, int> variant id => sellable (variants without a balance row are 0)
     */
    public function sellable(array $warehouseIds, array $variantIds): array
    {
        $combos = $this->combos->componentsOf($variantIds);
        $components = array_merge(...array_values(array_map('array_keys', $combos)) ?: [[]]);
        $ids = array_values(array_unique([...array_diff($variantIds, array_keys($combos)), ...$components]));

        $rows = StockBalance::query()->whereIn('warehouse_id', $warehouseIds)->whereIn('product_variant_id', $ids)
            ->get(['product_variant_id', 'on_hand', 'reserved', 'safety_stock'])->groupBy('product_variant_id');

        $single = [];
        foreach ($ids as $id) {
            $single[$id] = AllocationCalculator::sellable(($rows[$id] ?? collect())->map(fn (StockBalance $b) => [
                'on_hand' => $b->on_hand, 'reserved' => $b->reserved, 'safety_stock' => $b->safety_stock,
            ])->all());
        }
        $buildable = ComboResolver::buildable($combos, $single);

        $result = [];
        foreach ($variantIds as $id) {
            $result[$id] = $buildable[$id] ?? $single[$id] ?? 0;
        }

        return $result;
    }

    /**
     * On-hand quantity per variant over all warehouses of the current tenant (reports).
     *
     * @return array<int, int> variant id => on hand (only variants with a balance row)
     */
    public function onHandByVariant(): array
    {
        return StockBalance::query()->groupBy('product_variant_id')->selectRaw('product_variant_id, SUM(on_hand) as qty')
            ->pluck('qty', 'product_variant_id')->map(fn ($q) => (int) $q)->all();
    }

    /**
     * On-hand quantity per variant over all warehouses (combos: whole combos their components make).
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, int>
     */
    public function onHand(array $variantIds): array
    {
        $combos = $this->combos->componentsOf($variantIds);
        $components = array_merge(...array_values(array_map('array_keys', $combos)) ?: [[]]);
        $ids = array_values(array_unique([...$variantIds, ...$components]));
        $single = StockBalance::query()->whereIn('product_variant_id', $ids)->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(on_hand) as qty')->pluck('qty', 'product_variant_id')->map(fn ($q) => (int) $q)->all();
        $buildable = ComboResolver::buildable($combos, $single);

        $result = [];
        foreach ($variantIds as $id) {
            $result[$id] = $buildable[$id] ?? $single[$id] ?? 0;
        }

        return $result;
    }

    /**
     * Available (on hand − held) per variant, in one warehouse or all; combos as whole combos buildable.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, int>
     */
    public function available(array $variantIds, ?int $warehouseId = null): array
    {
        $combos = $this->combos->componentsOf($variantIds);
        $components = array_merge(...array_values(array_map('array_keys', $combos)) ?: [[]]);
        $ids = array_values(array_unique([...$variantIds, ...$components]));

        $single = StockBalance::query()->whereIn('product_variant_id', $ids)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('product_variant_id')->selectRaw('product_variant_id, SUM(on_hand) - SUM(reserved) as qty')
            ->pluck('qty', 'product_variant_id')->map(fn ($q) => (int) $q)->all();
        $buildable = ComboResolver::buildable($combos, $single);

        $result = [];
        foreach ($variantIds as $id) {
            $result[$id] = $buildable[$id] ?? $single[$id] ?? 0;
        }

        return $result;
    }
}
