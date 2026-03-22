#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

use Swoole\Runtime;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_PROC);

Hyperf\Di\ClassLoader::init();

$container = require BASE_PATH . '/config/container.php';
$application = $container->get(Hyperf\Contract\ApplicationInterface::class);
$application->run();
