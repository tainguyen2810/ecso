<?php

namespace App\Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A SKU started or stopped being sold (product or variant status changed). The marketplaces get the new published
 * stock: 0 for a stopped SKU, the real stock again when it is active. Dispatched after commit.
 */
class SaleStatusChanged
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $variantIds
     */
    public function __construct(public readonly int $tenantId, public readonly array $variantIds) {}
}
