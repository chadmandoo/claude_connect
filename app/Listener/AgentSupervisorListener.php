<?php

declare(strict_types=1);

namespace App\Listener;

use App\Agent\AgentSupervisor;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Listener]
class AgentSupervisorListener implements ListenerInterface
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
                $logger->info('AgentSupervisor: starting on worker 0');
                $supervisor = $this->container->get(AgentSupervisor::class);
                $supervisor->start();
            } catch (\Throwable $e) {
                $logger->error("AgentSupervisor fatal: {$e->getMessage()}");
            }
        });
    }
}
