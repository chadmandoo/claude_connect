<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'default' => [
        'driver' => 'pgsql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 5433),
        'database' => env('DB_DATABASE', 'claude_connect'),
        'username' => env('DB_USERNAME', 'claude_connect'),
        'password' => env('DB_PASSWORD', 'claude_connect'),
        'charset' => 'utf8',
        'schema' => 'public',
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
    ],
];
