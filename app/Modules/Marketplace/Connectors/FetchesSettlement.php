<?php

namespace App\Modules\Marketplace\Connectors;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;

/**
 * "Tiền thực nhận": what the marketplace pays the shop for one order, with each fee, read from its finance API.
 * Fees are positive amounts; lines are what the order page lists (amount signed: + to the shop, − taken by the sàn).
 * final: true once the marketplace has settled the order, false while the figures are its estimate, null when the
 * connector cannot tell (the order status decides then). Null when the marketplace has no figures for it yet.
 */
interface FetchesSettlement
{
    /**
     * @return array{order_amount: float, commission_fee: float, payment_fee: float, service_fee: float, fixed_fee: float, shipping_fee: float, net_amount: float, final: bool|null, settled_at: CarbonImmutable|null, lines: list<array{label: string, amount: float}>}|null
     */
    public function settlement(MarketplaceAccount $account, string $externalOrderId): ?array;
}
