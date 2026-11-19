<?php

namespace App\Modules\Marketplace\Connectors;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;

/**
 * Đợt 6 "Hàng hoàn từ sàn": the shop's return and refund requests changed since a moment, read page by page.
 *
 * status is normalised: requested (waiting for the seller), approved (accepted, the buyer has to send the goods),
 * shipping (the buyer sent them back), refunded (the marketplace paid the buyer back), rejected, cancelled.
 * goods: whether the buyer sends goods back (false: refund only). items: one row per SKU with its quantity.
 */
interface FetchesReturns
{
    /**
     * @return array{returns: list<array{id: string, order_id: string, status: string, raw_status: string, goods: bool, reason: string|null, refund: float|null, tracking_number: string|null, respond_by: CarbonImmutable|null, items: list<array{external_sku_id: string|null, seller_sku: string|null, name: string, quantity: int}>, images: list<string>}>, next: string|null}
     */
    public function listReturns(MarketplaceAccount $account, CarbonImmutable $since, ?string $cursor = null): array;
}
