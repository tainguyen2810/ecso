<?php

namespace App\Modules\Marketplace\Connectors;

use App\Modules\Marketplace\Connectors\Data\ProductData;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;

/** A connector that can re-read one listing (after a product webhook) instead of the whole catalog. */
interface FetchesSingleProduct
{
    /**
     * @return ProductData|null null when the product no longer exists on the channel
     *
     * @throws ConnectorException
     */
    public function getProduct(MarketplaceAccount $account, string $externalProductId): ?ProductData;
}
