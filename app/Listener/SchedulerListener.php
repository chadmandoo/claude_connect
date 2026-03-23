<?php

declare(strict_types=1);

namespace App\Listener;

use App\Scheduler\SchedulerRunner;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles AfterWorkerStart to launch the SchedulerRunner on worker 0.
 *
 * Starts the cron-style scheduler that evaluates and dispatches scheduled
 * tasks in a background coroutine.
 */
#[Listener]
class SchedulerListener implements ListenerInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function listen(): array
    {
        return [AfterWorkerStart::class];
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
                $logger->info('SchedulerRunner: starting on worker 0');
                $runner = $this->container->get(SchedulerRunner::class);
                $runner->start();
            } catch (Throwable $e) {
                $logger->error("SchedulerRunner fatal: {$e->getMessage()}");
            }
        });
    }
}
