<?php

declare(strict_types=1);

namespace App\Listener;

use App\Project\ProjectManager;
use App\Project\ProjectOrchestrator;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles AfterWorkerStart to launch the ProjectOrchestrator on worker 0.
 *
 * Ensures the default "General" workspace exists and starts the orchestrator loop
 * that drives multi-step project execution in a background coroutine.
 */
#[Listener]
class ProjectOrchestratorListener implements ListenerInterface
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

        // Only run orchestrator on worker 0
        if ($event->workerId !== 0) {
            return;
        }

        \Swoole\Coroutine::create(function () {
            $logger = $this->container->get(LoggerInterface::class);

            // Ensure "General" workspace exists
            try {
                $config = $this->container->get(ConfigInterface::class);
                $userId = $config->get('mcp.web.user_id', 'web_user');
                $manager = $this->container->get(ProjectManager::class);
                $generalId = $manager->ensureGeneralProject($userId);
                $logger->info("ProjectOrchestrator: General project ensured (id: {$generalId})");
            } catch (Throwable $e) {
                $logger->warning("ProjectOrchestrator: failed to ensure General project: {$e->getMessage()}");
            }

            $logger->info('ProjectOrchestrator: starting on worker 0');
            $orchestrator = $this->container->get(ProjectOrchestrator::class);
            $orchestrator->start();
        });
    }
}
