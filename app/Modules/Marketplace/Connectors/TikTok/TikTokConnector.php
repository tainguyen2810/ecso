<?php

namespace App\Modules\Marketplace\Connectors\TikTok;

use App\Modules\Marketplace\Connectors\UnverifiedConnector;

/**
 * TikTok Shop Partner API connector — SKELETON (Phase 5).
 *
 * Status: every capability is UNKNOWN. The Partner Center documentation could not be read from the build
 * environment and no app key / secret or test shop exist yet. Secondary sources mention an HMAC-SHA256 webhook
 * signature and dated API versions; none of that is implemented until verified against the official docs.
 * See docs/API-CAPABILITY-MATRIX.md §TikTok Shop.
 */
class TikTokConnector extends UnverifiedConnector
{
    public const MARKETPLACE = 'tiktok';

    public function marketplace(): string
    {
        return self::MARKETPLACE;
    }
}
