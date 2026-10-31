<?php

use App\Modules\Marketplace\Connectors\Fake\FakeConnector;
use App\Modules\Marketplace\Connectors\Lazada\LazadaConnector;
use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Marketplace\Connectors\Tiki\TikiConnector;
use App\Modules\Marketplace\Connectors\TikTok\TikTokConnector;

return [
    // Marketplaces shown in the UI. Without a connector class below they are listed as "not available yet".
    'catalog' => [
        'shopee' => 'Shopee',
        'tiktok' => 'TikTok Shop',
        'lazada' => 'Lazada',
        'tiki' => 'Tiki',
        'fake' => 'Sàn giả lập (Fake)',
    ],

    'connectors' => [
        'fake' => FakeConnector::class,
        // Skeleton: all capabilities UNKNOWN until verified with official docs and partner credentials.
        'shopee' => ShopeeConnector::class,
        'tiktok' => TikTokConnector::class,
        'lazada' => LazadaConnector::class,
        'tiki' => TikiConnector::class,
    ],

    // App credentials per marketplace (issued by the marketplace's developer portal). Never committed; set on the
    // server / CI secrets. A marketplace whose credentials are empty cannot start a shop authorization.
    'credentials' => [
        'shopee' => [
            'partner_id' => env('SHOPEE_PARTNER_ID'),
            'partner_key' => env('SHOPEE_PARTNER_KEY'),
        ],
        'tiktok' => [
            'app_key' => env('TIKTOK_SHOP_APP_KEY'),
            'app_secret' => env('TIKTOK_SHOP_APP_SECRET'),
        ],
        'lazada' => [
            'app_key' => env('LAZADA_APP_KEY'),
            'app_secret' => env('LAZADA_APP_SECRET'),
        ],
        'tiki' => [
            'client_id' => env('TIKI_CLIENT_ID'),
            'client_secret' => env('TIKI_CLIENT_SECRET'),
        ],
    ],

    // Lifetime of the one-time "state" that links a marketplace callback to the tenant and user who started it.
    'authorization_state_ttl' => 600,

    // The Fake marketplace simulates a marketplace for development, demos and tests. It is never a real
    // integration and is off unless FAKE_MARKETPLACE_ENABLED=true (never enable it on production).
    'fake' => [
        'enabled' => (bool) env('FAKE_MARKETPLACE_ENABLED', false),
        'webhook_secret' => env('FAKE_MARKETPLACE_SECRET', 'fake-marketplace-secret'),
    ],

    // Webhook timestamps older than this are rejected (replay protection) when the marketplace sends one.
    'webhook_tolerance_seconds' => 300,

    'inventory_push' => [
        // Delay before pushing a stock increase; decreases are pushed at once (INVENTORY-ENGINE §7).
        'debounce_seconds' => 3,
        'batch_size' => 50,
        // Backoff in seconds per attempt for temporary failures (jitter added).
        'backoff' => [10, 30, 120, 600, 1800],
        'max_attempts' => 8,
        // Consecutive failed pushes that open the circuit breaker of a shop, and for how long.
        'circuit_threshold' => 5,
        'circuit_open_seconds' => 300,
    ],

    // Polling window overlap so an order updated during a sync is not missed.
    'order_sync_overlap_minutes' => 5,
];
