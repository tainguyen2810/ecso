<?php

namespace App\Modules\Marketplace\Connectors\Shopee;

use App\Modules\Marketplace\Connectors\UnverifiedConnector;

/**
 * Shopee Open Platform connector — SKELETON (Phase 4).
 *
 * Status: every capability is UNKNOWN. The official documentation (open.shopee.com) could not be read from the
 * build environment and no partner credentials / test shop exist yet, so nothing is implemented from guesswork:
 * authorization, signing, order/product/stock endpoints and the push (webhook) signature are left to be written
 * and verified once SHOPEE_PARTNER_ID / SHOPEE_PARTNER_KEY and a sandbox shop are available.
 * See docs/API-CAPABILITY-MATRIX.md §Shopee for the checklist.
 */
class ShopeeConnector extends UnverifiedConnector
{
    public const MARKETPLACE = 'shopee';

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }
}
