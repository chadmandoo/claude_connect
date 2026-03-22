<?php

declare(strict_types=1);

use Hyperf\Contract\StdoutLoggerInterface;
use function Hyperf\Support\env;

return [
    'app_name' => env('APP_NAME', 'claude-connect'),
    'app_env' => env('APP_ENV', 'production'),
    'scan_cacheable' => env('SCAN_CACHEABLE', false),
    StdoutLoggerInterface::class => [
        'log_level' => [
            \Psr\Log\LogLevel::ALERT,
            \Psr\Log\LogLevel::CRITICAL,
            \Psr\Log\LogLevel::EMERGENCY,
            \Psr\Log\LogLevel::ERROR,
            \Psr\Log\LogLevel::INFO,
            \Psr\Log\LogLevel::NOTICE,
            \Psr\Log\LogLevel::WARNING,
        ],
    ],
];
