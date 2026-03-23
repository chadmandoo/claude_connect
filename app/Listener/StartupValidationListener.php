<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles AfterWorkerStart to perform startup health checks on worker 0.
 *
 * Validates Claude CLI availability, Redis connectivity, auth configuration,
 * RediSearch vector index initialization, and default agent seeding.
 */
#[Listener]
class StartupValidationListener implements ListenerInterface
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

        // Only validate on worker 0
        if ($event->workerId !== 0) {
            return;
        }

        $logger = $this->container->get(LoggerInterface::class);
        $config = $this->container->get(ConfigInterface::class);

        // Verify Claude CLI binary exists
        $cliPath = $config->get('mcp.claude.cli_path', '/Users/chadpeppers/.local/bin/claude');
        if (!is_file($cliPath) || !is_executable($cliPath)) {
            $logger->error("Startup: Claude CLI not found or not executable at {$cliPath}");
        }

        // Verify Redis connectivity
        try {
            $redis = $this->container->get(\Hyperf\Redis\Redis::class);
            $result = $redis->ping();
            if ($result !== true && $result !== '+PONG') {
                $logger->error('Startup: Redis ping failed');
            }
        } catch (Throwable $e) {
            $logger->error("Startup: Redis connection failed: {$e->getMessage()}");
        }

        // Warn about missing auth password
        $authPassword = $config->get('mcp.web.auth_password', '');
        if ($authPassword === '') {
            $logger->warning('Startup: WEB_AUTH_PASSWORD is not set — web frontend has no authentication');
        }

        // Initialize RediSearch vector index (idempotent)
        try {
            $vectorStore = $this->container->get(\App\Embedding\VectorStore::class);
            $vectorStore->ensureIndex();
        } catch (Throwable $e) {
            $logger->warning("Startup: VectorStore index init failed: {$e->getMessage()}");
        }

        // Seed default agents (idempotent — skips existing)
        try {
            $agentManager = $this->container->get(\App\Agent\AgentManager::class);
            $agentManager->seedDefaultAgents();
        } catch (Throwable $e) {
            $logger->warning("Startup: Agent seeding failed: {$e->getMessage()}");
        }

        $logger->info('Startup: validation complete');
    }
}
