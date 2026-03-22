<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\StateMachine\TaskManager;
use App\Storage\SwooleTableCache;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;

/**
 * Runs on Worker 0: ticks every 15s, checks for due scheduled jobs, executes them.
 */
class SchedulerRunner
{
    #[Inject]
    private SchedulerManager $manager;

    #[Inject]
    private SystemChannel $systemChannel;

    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private SwooleTableCache $cache;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    private bool $running = false;
    private int $tickInterval = 15;

    public function start(): void
    {
        $this->running = true;

        // Ensure system channel exists
        $this->systemChannel->ensureChannel();

        // Register default jobs from config
        $this->manager->registerDefaults([
            'nightly' => $this->config->get('mcp.nightly', []),
            'cleanup' => $this->config->get('mcp.cleanup', []),
            'backup' => $this->config->get('mcp.backup', []),
        ]);

        $this->logger->info('SchedulerRunner: started (tick every {interval}s)', ['interval' => $this->tickInterval]);
        $this->systemChannel->post('🚀 **System started** — Scheduler online', 'System');

        while ($this->running) {
            \Swoole\Coroutine::sleep($this->tickInterval);

            try {
                $this->tick();
            } catch (\Throwable $e) {
                $this->logger->error("SchedulerRunner tick error: {$e->getMessage()}");
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function tick(): void
    {
        $dueJobs = $this->manager->getDueJobs();

        foreach ($dueJobs as $job) {
            $jobId = $job['id'] ?? '';
            $jobName = $job['name'] ?? $jobId;
            $handler = $job['handler'] ?? '';

            $this->logger->info("Scheduler: executing job '{$jobId}'");

            \Swoole\Coroutine::create(function () use ($jobId, $jobName, $handler) {
                $startTime = time();
                $result = 'OK';

                try {
                    $result = $this->executeHandler($handler);
                } catch (\Throwable $e) {
                    $result = "Error: {$e->getMessage()}";
                    $this->logger->error("Scheduler: job '{$jobId}' failed: {$e->getMessage()}");
                }

                $duration = time() - $startTime;
                $this->manager->markRun($jobId, $result, $duration);
                $this->systemChannel->postSchedulerRun($jobName, mb_substr($result, 0, 200), $duration);
            });
        }
    }

    private function executeHandler(string $handler): string
    {
        return match ($handler) {
            'nightly' => $this->runNightly(),
            'cleanup' => $this->runCleanup(),
            'supervisor_health' => $this->runSupervisorHealth(),
            'memory_sync' => $this->runMemorySync(),
            'database_backup' => $this->runDatabaseBackup(),
            default => 'Unknown handler: ' . $handler,
        };
    }

    private function runNightly(): string
    {
        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $agent = $container->get(\App\Nightly\NightlyConsolidationAgent::class);
        // The nightly agent has its own run method
        return 'Nightly consolidation triggered (runs in its own cycle)';
    }

    private function runCleanup(): string
    {
        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $agent = $container->get(\App\Cleanup\CleanupAgent::class);
        return 'Cleanup triggered (runs in its own cycle)';
    }

    private function runSupervisorHealth(): string
    {
        $running = $this->taskManager->listTasks('running', 50);
        $pending = $this->taskManager->listTasks('pending', 50);
        $runningCount = count($running);
        $pendingCount = count($pending);

        $stalled = 0;
        $forceFailed = 0;
        $now = time();
        $stallTimeout = (int) ($this->config->get('mcp.supervisor.stall_timeout', 1800));

        foreach ($running as $task) {
            $startedAt = (int) ($task['started_at'] ?? $task['created_at'] ?? 0);
            $elapsed = $startedAt > 0 ? ($now - $startedAt) : 0;
            $taskId = $task['id'] ?? '';

            if ($elapsed > $stallTimeout) {
                $stalled++;

                // Kill the process if it has a PID
                $pid = (int) ($task['pid'] ?? 0);
                if ($pid > 0) {
                    $isAlive = posix_kill($pid, 0);
                    if ($isAlive) {
                        posix_kill($pid, SIGTERM);
                        $this->logger->warning("Scheduler: killed stalled process pid={$pid} for task {$taskId}");
                    }
                }

                // Force-fail the task
                try {
                    $this->taskManager->setTaskError($taskId, "Task stalled after {$elapsed}s — force-failed by scheduler");
                    $this->taskManager->transition($taskId, \App\StateMachine\TaskState::FAILED);
                    $forceFailed++;
                    $this->logger->warning("Scheduler: force-failed stalled task {$taskId} (running for {$elapsed}s)");

                    // Notify via system channel
                    $prompt = mb_substr($task['prompt'] ?? '', 0, 80);
                    $this->systemChannel->postTaskUpdate($taskId, 'failed', $prompt . " (stalled {$elapsed}s)");
                } catch (\Throwable $e) {
                    $this->logger->error("Scheduler: failed to force-fail task {$taskId}: {$e->getMessage()}");
                }
            }
        }

        // Also check for tasks stuck in "pending" too long (> 10 min)
        foreach ($pending as $task) {
            $createdAt = (int) ($task['created_at'] ?? 0);
            $elapsed = $createdAt > 0 ? ($now - $createdAt) : 0;
            $taskId = $task['id'] ?? '';

            if ($elapsed > 600) { // 10 minutes pending
                try {
                    $this->taskManager->setTaskError($taskId, "Task stuck in pending for {$elapsed}s — force-failed by scheduler");
                    // Pending tasks need to transition to RUNNING first, then FAILED
                    $this->taskManager->transition($taskId, \App\StateMachine\TaskState::RUNNING);
                    $this->taskManager->transition($taskId, \App\StateMachine\TaskState::FAILED);
                    $forceFailed++;
                    $this->logger->warning("Scheduler: force-failed stuck pending task {$taskId} ({$elapsed}s pending)");
                } catch (\Throwable $e) {
                    $this->logger->error("Scheduler: failed to force-fail pending task {$taskId}: {$e->getMessage()}");
                }
            }
        }

        $status = "{$runningCount} running, {$pendingCount} pending";
        if ($stalled > 0) {
            $status .= ", {$stalled} stalled";
        }
        if ($forceFailed > 0) {
            $status .= ", {$forceFailed} force-failed";
        }

        if ($runningCount > 0 || $pendingCount > 0 || $forceFailed > 0) {
            return $status;
        }

        return 'All clear';
    }

    private function runMemorySync(): string
    {
        // Placeholder for memory sync job
        return 'Memory sync not yet implemented';
    }

    private function runDatabaseBackup(): string
    {
        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $agent = $container->get(\App\Backup\DatabaseBackupAgent::class);
        return $agent->run();
    }
}
