<?php

namespace App\Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit whenever on_hand or reserved changes. Phase 4 listens to recompute
 * published stock per marketplace listing and enqueue the inventory outbox.
 */
class AvailableStockChanged
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $variantIds
     */
    public function __construct(public readonly int $tenantId, public readonly array $variantIds) {}
}
