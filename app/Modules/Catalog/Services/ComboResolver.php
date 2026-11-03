<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductComponent;
use App\Modules\Catalog\Models\ProductVariant;

/**
 * Combo lookups for other modules (stock holds, returns, marketplace publishing). A combo SKU has no stock of its
 * own; every stock operation on it is done on its components. Reads only, in the current tenant.
 */
class ComboResolver
{
    /**
     * @param  array<int, int>  $variantIds
     * @return array<int, array<int, int>> combo variant id => [component variant id => quantity per combo], sorted by component id
     */
    public function componentsOf(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $result = [];
        foreach (ProductComponent::query()->whereIn('product_variant_id', $variantIds)->orderBy('component_variant_id')->get() as $c) {
            $result[$c->product_variant_id][$c->component_variant_id] = $c->quantity;
        }

        return $result;
    }

    /**
     * Combo SKUs that use any of the given SKUs (their sellable quantity moves with the components).
     *
     * @param  array<int, int>  $componentIds
     * @return array<int, int>
     */
    public function combosContaining(array $componentIds): array
    {
        if ($componentIds === []) {
            return [];
        }

        return ProductComponent::query()->whereIn('component_variant_id', $componentIds)
            ->whereIn('product_variant_id', ProductVariant::query()->select('id'))->distinct()
            ->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Replaces combo lines with their components (merged per SKU). Other lines are kept as they are.
     *
     * @param  array<int, array{variant_id: int, quantity: int}>  $lines
     * @return array<int, array{variant_id: int, quantity: int}>
     */
    public function expand(array $lines): array
    {
        $combos = $this->componentsOf(array_map(fn ($l) => (int) $l['variant_id'], $lines));
        $merged = [];
        foreach ($lines as $line) {
            $parts = $combos[(int) $line['variant_id']] ?? [(int) $line['variant_id'] => 1];
            foreach ($parts as $variantId => $per) {
                $merged[$variantId] = ($merged[$variantId] ?? 0) + $per * (int) $line['quantity'];
            }
        }

        return array_map(fn ($id, $qty) => ['variant_id' => $id, 'quantity' => $qty], array_keys($merged), $merged);
    }

    /**
     * Quantity of each combo that the component quantities allow: min over components of floor(qty / per combo).
     *
     * @param  array<int, array<int, int>>  $combos  from componentsOf()
     * @param  array<int, int>  $componentQty  component variant id => quantity (missing = 0)
     * @return array<int, int> combo variant id => combos possible
     */
    public static function buildable(array $combos, array $componentQty): array
    {
        $result = [];
        foreach ($combos as $comboId => $parts) {
            $result[$comboId] = $parts === [] ? 0 : max(0, min(array_map(
                fn ($componentId, $per) => intdiv(max(0, $componentQty[$componentId] ?? 0), max(1, $per)),
                array_keys($parts), $parts,
            )));
        }

        return $result;
    }
}
