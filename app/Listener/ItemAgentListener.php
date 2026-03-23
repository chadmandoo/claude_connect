<?php

declare(strict_types=1);

namespace App\Listener;

use App\Workflow\ItemAgent;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles AfterWorkerStart to launch the ItemAgent on worker 0.
 *
 * Starts the autonomous work item processing agent in a background coroutine
 * that polls for assigned items and executes them via Claude CLI.
 */
#[Listener]
class ItemAgentListener implements ListenerInterface
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    public function listen(): array
    {
        return [
            AfterWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        if (!$event instanceof AfterWorkerStart) {
            return;
        }

        // Only run on worker 0
        if ($event->workerId !== 0) {
            return;
        }

        \Swoole\Coroutine::create(function () {
            $logger = $this->container->get(LoggerInterface::class);

            try {
                $logger->info('ItemAgent: starting on worker 0');
                $agent = $this->container->get(ItemAgent::class);
                $agent->start();
            } catch (Throwable $e) {
                $logger->error("ItemAgent fatal: {$e->getMessage()}");
            }
        });
    }
}
