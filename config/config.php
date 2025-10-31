<?php
return [
    'timezone' => 'Europe/Istanbul',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'signage',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'signage' => [
        'refresh_seconds' => (int) (getenv('SIGNAGE_REFRESH') ?: 5),
        'time_format' => getenv('SIGNAGE_TIME_FORMAT') ?: '24h',
    ],
    'theme' => [
        'primary' => '#E3000B',
        'secondary' => '#17007A',
        'dark' => '#080915',
        'light' => '#FFFFFF',
    ],
];
