<?php

use App\Modules\Marketplace\Connectors\Fake\FakeConnector;
use App\Modules\Marketplace\Connectors\Lazada\LazadaConnector;
use App\Modules\Marketplace\Connectors\Shopee\ShopeeConnector;
use App\Modules\Marketplace\Connectors\Tiki\TikiConnector;
use App\Modules\Marketplace\Connectors\TikTok\TikTokConnector;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;

return [
    // Marketplaces shown in the UI. Without a connector class below they are listed as "not available yet".
    'catalog' => [
        'shopee' => 'Shopee',
        'tiktok' => 'TikTok Shop',
        'lazada' => 'Lazada',
        'tiki' => 'Tiki',
        'woocommerce' => 'WooCommerce',
        'fake' => 'Sàn giả lập (Fake)',
    ],

    'connectors' => [
        'fake' => FakeConnector::class,
        // WooCommerce REST API v3 (see API-CAPABILITY-MATRIX); a store connects with its own API keys (no partner app).
        'woocommerce' => WooCommerceConnector::class,
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

    'woocommerce' => [
        'timeout' => (int) env('WOOCOMMERCE_TIMEOUT', 25),
        // Development only: allow http:// stores and stores on private networks. Never enable on production.
        'allow_http' => (bool) env('WOOCOMMERCE_ALLOW_HTTP', false),
        'allow_private_hosts' => (bool) env('WOOCOMMERCE_ALLOW_PRIVATE_HOSTS', false),
        // Lifetime of a "Phê duyệt trên website" attempt (Woo posts the keys to ECSO, then sends the user back).
        'authorize_ttl' => 900,
    ],

    // Marketplace prices from fees (round 3). Starting fee tables are reference figures only (public rates change
    // often and differ per seller programme): the owner checks them, or takes the actual fees from settlement data.
    'pricing' => [
        'reference_fees' => [
            'shopee' => ['commission_pct' => 9, 'payment_pct' => 4.91, 'service_pct' => 0, 'fixed_fee' => 0],
            'tiktok' => ['commission_pct' => 8, 'payment_pct' => 5, 'service_pct' => 0, 'fixed_fee' => 0],
            'lazada' => ['commission_pct' => 8, 'payment_pct' => 4, 'service_pct' => 0, 'fixed_fee' => 0],
            'tiki' => ['commission_pct' => 10, 'payment_pct' => 0, 'service_pct' => 0, 'fixed_fee' => 0],
        ],
        // Fee check: window, the gap (percentage points) that raises an alert, and the fewest orders worth comparing.
        'check_days' => 30,
        'drift_points' => 0.5,
        'min_orders' => 20,
        'loss_days' => 7,
    ],

    // Polling window overlap so an order updated during a sync is not missed.
    'order_sync_overlap_minutes' => 5,
];
