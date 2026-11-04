<?php

namespace App\Modules\Marketplace\Connectors;

use App\Modules\Marketplace\Connectors\Data\PriceUpdate;
use App\Modules\Marketplace\Connectors\Data\StockUpdateResult;
use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;

/** A connector that can set prices through its API (WooCommerce now; marketplaces once their apps are approved). */
interface UpdatesPrices
{
    /**
     * @param  array<int, PriceUpdate>  $updates
     * @return array<int, StockUpdateResult> one result per SKU (same OK / RETRY / REJECTED meaning as for stock)
     *
     * @throws ConnectorException
     */
    public function updatePrices(MarketplaceAccount $account, array $updates): array;
}
