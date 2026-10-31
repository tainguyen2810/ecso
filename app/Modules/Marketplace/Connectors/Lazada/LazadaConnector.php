<?php

namespace App\Modules\Marketplace\Connectors\Lazada;

use App\Modules\Marketplace\Connectors\UnverifiedConnector;

/**
 * Lazada Open Platform connector — SKELETON (Phase 7).
 *
 * Status: every capability is UNKNOWN. open.lazada.com could not be reached from the build environment
 * (proxy refused) and no app key / secret or seller test account exist. Secondary sources describe LazOP
 * REST calls signed with HMAC-SHA256 and OAuth code → token; none of it is implemented until verified.
 * See docs/API-CAPABILITY-MATRIX.md §3.4.
 */
class LazadaConnector extends UnverifiedConnector
{
    public const MARKETPLACE = 'lazada';

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }
}
