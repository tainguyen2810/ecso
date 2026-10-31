<?php

namespace App\Modules\Marketplace\Connectors\Tiki;

use App\Modules\Marketplace\Connectors\UnverifiedConnector;

/**
 * Tiki Open Platform connector — SKELETON (Phase 7).
 *
 * Status: every capability is UNKNOWN. In Phase 0 the public Guides showed OAuth 2.0 and an "event queue"
 * (polling) for order events, but the endpoint reference sits behind the seller console; in Phase 7
 * open.tiki.vn was not reachable at all from the build environment. Whether sellers can update stock
 * through the API is UNKNOWN: if it turns out to be unsupported, the inventory.update capability becomes
 * `unsupported` and ECSO shows the manual-update guidance instead of pretending the push succeeded.
 * See docs/API-CAPABILITY-MATRIX.md §3.5.
 */
class TikiConnector extends UnverifiedConnector
{
    public const MARKETPLACE = 'tiki';

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }
}
