<?php

return [
    // Token required by GET /health/ready (empty = endpoint disabled).
    'health_token' => env('HEALTH_TOKEN', ''),

    // Super admins must use two-factor authentication (TOTP) before using the admin console (Phase 8).
    // Only switch off for automated test environments; ecso:check-env flags it on production.
    'admin_two_factor' => (bool) env('ECSO_ADMIN_2FA', true),

    // Monitoring thresholds used by the admin monitoring page and `ecso:monitor` alerts.
    'monitoring' => [
        'queues' => ['default', 'webhooks', 'sync'],
        'backlog_alert' => 1000,
        'failed_jobs_alert_24h' => 20,
        'scheduler_stale_minutes' => 3,
    ],

    // Open orders whose ship-by time is within this many hours are flagged "sắp quá hạn" (ORDER-LIFECYCLE §7).
    'orders' => [
        'due_soon_hours' => 6,
    ],

    // Minimum PHP extensions checked by `php artisan ecso:check-env`.
    'required_extensions' => [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl',
        'pcntl', 'pdo_mysql', 'redis', 'tokenizer', 'xml', 'zip',
    ],
];
