<?php

declare(strict_types=1);

namespace App\Listener;

use App\Nightly\NightlyConsolidationAgent;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Listener]
class NightlyConsolidationListener implements ListenerInterface
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
                $logger->info('NightlyAgent: starting on worker 0');
                $agent = $this->container->get(NightlyConsolidationAgent::class);
                $agent->start();
            } catch (\Throwable $e) {
                $logger->error("NightlyAgent fatal: {$e->getMessage()}");
            }
        });
    }
}
