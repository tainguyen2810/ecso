<?php

namespace App\Modules\Marketplace\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A listing variant was linked to (or unlinked from) an internal SKU. */
class ListingMappingChanged
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $listingVariantIds
     */
    public function __construct(public readonly int $tenantId, public readonly array $listingVariantIds) {}
}
