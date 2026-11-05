<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Inventory\Domain\AllocationCalculator;
use App\Modules\Marketplace\Models\MarketplaceAllocation;

/**
 * Which allocation rule applies to each SKU of a shop: the SKU override, else the shop default, else "publish
 * everything" (the behaviour before allocations existed). Runs in the tenant context.
 */
class AllocationPolicy
{
    public const DEFAULT_RULE = ['mode' => AllocationCalculator::MODE_ALL, 'value' => null, 'buffer_qty' => 0, 'max_qty' => null];

    /**
     * @param  array<int, int>  $variantIds
     * @return array<int, array{mode: string, value: int|null, buffer_qty: int, max_qty: int|null}> variant id => rule
     */
    public function rules(int $accountId, array $variantIds): array
    {
        $rows = MarketplaceAllocation::query()->where('marketplace_account_id', $accountId)
            ->where(fn ($q) => $q->whereNull('product_variant_id')->orWhereIn('product_variant_id', $variantIds))->get();
        $shop = $rows->firstWhere('product_variant_id', null)?->rule() ?? self::DEFAULT_RULE;
        $overrides = $rows->whereNotNull('product_variant_id')->keyBy('product_variant_id');

        $rules = [];
        foreach ($variantIds as $id) {
            $rules[$id] = $overrides->get($id)?->rule() ?? $shop;
        }

        return $rules;
    }
}
