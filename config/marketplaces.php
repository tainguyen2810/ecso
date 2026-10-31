<?php

use App\Modules\Marketplace\Connectors\Fake\FakeConnector;

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
    ],

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
