<?php

return [
    // Token required by GET /health/ready (empty = endpoint disabled).
    'health_token' => env('HEALTH_TOKEN', ''),

    // Super admins must use two-factor authentication (TOTP) before using the admin console (Phase 8).
    // Only switch off for automated test environments; ecso:check-env flags it on production.
    'admin_two_factor' => (bool) env('ECSO_ADMIN_2FA', true),

    // Monitoring thresholds used by the admin monitoring page and `ecso:monitor` alerts.
    'monitoring' => [
        'queues' => ['default', 'webhooks', 'sync', 'sync-long'],
        'backlog_alert' => 1000,
        'failed_jobs_alert_24h' => 20,
        'scheduler_stale_minutes' => 3,
    ],

    // Open orders whose ship-by time is within this many hours are flagged "sắp quá hạn" (ORDER-LIFECYCLE §7).
    'orders' => [
        'due_soon_hours' => 6,
    ],

    // Detail reports list at most this many rows on screen; their totals always cover every row.
    'reports' => [
        'row_limit' => (int) env('ECSO_REPORT_ROW_LIMIT', 2000),
        'customer_row_limit' => 500,
        // "Xuất CSV" of a cut table fetches every row of the filter, up to this many.
        'export_row_limit' => 50000,
    ],

    // Minimum PHP extensions checked by `php artisan ecso:check-env`.
    'required_extensions' => [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl',
        'pcntl', 'pdo_mysql', 'redis', 'tokenizer', 'xml', 'zip',
    ],

    // Web setup wizard (/install). The wizard is open only while the app has no APP_KEY and no lock file;
    // every call needs the one-time token written to token_path (readable only by someone with server access).
    'installer' => [
        'lock_path' => storage_path('app/installed.lock'),
        'token_path' => storage_path('app/install-token.txt'),
    ],

    // Customer files: up to sync_rows rows are imported while the user waits; larger files (up to background_rows)
    // are checked and imported in the background (CustomerImport).
    'customer_import' => [
        'sync_rows' => (int) env('CUSTOMER_IMPORT_SYNC_ROWS', 5000),
        'background_rows' => (int) env('CUSTOMER_IMPORT_BACKGROUND_ROWS', 30000),
    ],
];
