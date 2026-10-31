<?php

return [
    // Token required by GET /health/ready (empty = endpoint disabled).
    'health_token' => env('HEALTH_TOKEN', ''),

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
