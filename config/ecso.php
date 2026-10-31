<?php

return [
    // Token required by GET /health/ready (empty = endpoint disabled).
    'health_token' => env('HEALTH_TOKEN', ''),

    // Minimum PHP extensions checked by `php artisan ecso:check-env`.
    'required_extensions' => [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl',
        'pcntl', 'pdo_mysql', 'redis', 'tokenizer', 'xml', 'zip',
    ],
];
