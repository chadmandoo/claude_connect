<?php

declare(strict_types=1);

namespace App\Agent;

use App\Chat\ChatConversationStore;
use App\Conversation\ConversationManager;
use App\Pipeline\PipelineContext;
use App\Pipeline\PostTaskPipeline;
use App\StateMachine\TaskManager;
use App\StateMachine\TaskState;
use App\Web\TaskNotifier;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background supervisor loop that monitors running tasks for completion, failure, and stalls.
 *
 * Sends WebSocket notifications on state changes, retries failed tasks up to a configured limit,
 * kills stalled processes, and runs the post-task pipeline on completion.
 */
class AgentSupervisor
{
    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private ConversationManager $conversationManager;

    #[Inject]
    private ChatConversationStore $chatConversationStore;

    #[Inject]
    private PostTaskPipeline $pipeline;

    #[Inject]
    private TaskNotifier $notifier;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    private bool $running = false;

    /** @var array<string, int> taskId => startTime for stall detection */
    private array $runningTasks = [];

    public function start(): void
    {
        if (!(bool) $this->config->get('mcp.supervisor.enabled', false)) {
            $this->logger->info('AgentSupervisor: disabled via config');

            return;
        }

        $this->running = true;
        $interval = (int) $this->config->get('mcp.supervisor.tick_interval', 30);
        $this->logger->info('AgentSupervisor started', ['tick_interval' => $interval]);

        while ($this->running) {
            try {
                $this->tick();
            } catch (Throwable $e) {
                $this->logger->error("AgentSupervisor tick error: {$e->getMessage()}");
            }
            \Swoole\Coroutine::sleep($interval);
        }

        $this->logger->info('AgentSupervisor stopped');
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function tick(): void
    {
        $this->checkRunningTasks();
        // Task execution is handled by the external task-worker process (bin/task-worker.php).
        // The supervisor monitors running tasks for completion/stalls and sends WS notifications.
        $this->monitorExternalTasks();
    }

    /**
     * Check running tasks for completion, failure, or stalls.
     */
    private function checkRunningTasks(): void
    {
        $stallTimeout = (int) $this->config->get('mcp.supervisor.stall_timeout', 1800);
        $maxRetries = (int) $this->config->get('mcp.supervisor.max_retries', 1);
        $now = time();

        foreach ($this->runningTasks as $taskId => $startTime) {
            $task = $this->taskManager->getTask($taskId);
            if ($task === null) {
                unset($this->runningTasks[$taskId]);
                continue;
            }

            $state = $task['state'] ?? '';

            if ($state === 'completed') {
                $this->logger->info("AgentSupervisor: task {$taskId} completed");
                $this->handleTaskCompletion($taskId, $task, $startTime);
                unset($this->runningTasks[$taskId]);
                continue;
            }

            if ($state === 'failed') {
                $retryCount = (int) ($task['retry_count'] ?? 0);
                if ($retryCount < $maxRetries) {
                    $this->logger->info("AgentSupervisor: retrying task {$taskId} (attempt {$retryCount})");
                    $this->taskManager->resetTaskForRetry($taskId, $task['prompt'] ?? '');
                    unset($this->runningTasks[$taskId]);
                    // Will be picked up on next tick
                } else {
                    $this->logger->warning("AgentSupervisor: task {$taskId} failed (max retries reached)");
                    $this->notifier->notifyStateChange($taskId, 'failed', $task);
                    unset($this->runningTasks[$taskId]);
                }
                continue;
            }

            // Stall detection
            if ($state === 'running' && ($now - $startTime) > $stallTimeout) {
                $pid = (int) ($task['pid'] ?? 0);
                if ($pid > 0) {
                    // Check if process is actually alive
                    if (!posix_kill($pid, 0)) {
                        $this->logger->warning("AgentSupervisor: task {$taskId} stalled (process dead), marking failed");
                        $this->taskManager->setTaskError($taskId, 'Process died unexpectedly');
                        $this->taskManager->transition($taskId, TaskState::FAILED);
                        $this->notifier->notifyStateChange($taskId, 'failed', $task);
                        unset($this->runningTasks[$taskId]);
                        continue;
                    }
                }

                $this->logger->warning("AgentSupervisor: task {$taskId} stalled for " . ($now - $startTime) . 's, killing');
                if ($pid > 0) {
                    posix_kill($pid, SIGTERM);
                }
                $this->taskManager->setTaskError($taskId, 'Stall timeout exceeded');
                $this->taskManager->transition($taskId, TaskState::FAILED);
                $this->notifier->notifyStateChange($taskId, 'failed', $task);
                unset($this->runningTasks[$taskId]);
            }
        }
    }

    // Task pickup and execution is handled by the external task-worker.php process.
    // The supervisor only monitors running tasks for completions and stalls.

    /**
     * Monitor tasks executed by the external worker (bin/task-worker.php).
     * Detects state changes and sends WebSocket notifications + runs post-processing.
     */
    private function monitorExternalTasks(): void
    {
        // Find running tasks that we're not already tracking
        $runningTasks = $this->taskManager->listTasks('running', 20);
        foreach ($runningTasks as $task) {
            $taskId = $task['id'] ?? '';
            $options = json_decode($task['options'] ?? '{}', true);
            if (($options['dispatch_mode'] ?? '') !== 'supervisor') {
                continue;
            }
            if (!isset($this->runningTasks[$taskId])) {
                $this->runningTasks[$taskId] = (int) ($task['started_at'] ?? time());
            }
        }

        // Also check recently completed/failed tasks that we were tracking
        $completedTasks = $this->taskManager->listTasks('completed', 10);
        $failedTasks = $this->taskManager->listTasks('failed', 10);

        foreach (array_merge($completedTasks, $failedTasks) as $task) {
            $taskId = $task['id'] ?? '';
            if (!isset($this->runningTasks[$taskId])) {
                continue;
            }

            $state = $task['state'] ?? '';
            $startTime = $this->runningTasks[$taskId];

            if ($state === 'completed') {
                $this->logger->info("AgentSupervisor: external worker completed task {$taskId}");
                $this->handleTaskCompletion($taskId, $task, $startTime);
            } elseif ($state === 'failed') {
                $this->logger->info("AgentSupervisor: external worker failed task {$taskId}");
                $this->notifier->notifyStateChange($taskId, 'failed', $task);
            }

            unset($this->runningTasks[$taskId]);
        }
    }

    /**
     * Handle a completed supervisor task: record in conversation, notify user, run pipeline.
     */
    private function handleTaskCompletion(string $taskId, array $task, int $startTime): void
    {
        $options = json_decode($task['options'] ?? '{}', true);
        $conversationId = $options['conversation_id'] ?? $task['conversation_id'] ?? '';
        $userId = $options['web_user_id'] ?? $task['web_user_id'] ?? '';
        $projectId = $options['project_id'] ?? $task['project_id'] ?? 'general';
        $result = $task['result'] ?? '';
        $cost = (float) ($task['cost_usd'] ?? 0);
        $duration = time() - $startTime;

        // Note: conversation turn is already written by bin/task-worker.php's recordConversationTurn.
        // We only update ephemeral chat history for API mode context.
        if ($conversationId !== '' && $result !== '') {
            try {
                $this->chatConversationStore->appendMessage($conversationId, [
                    'role' => 'assistant',
                    'content' => $result,
                ]);
            } catch (Throwable $e) {
                $this->logger->warning("AgentSupervisor: failed to append chat history for task {$taskId}: {$e->getMessage()}");
            }
        }

        // 2. Notify via WebSocket: task.state_changed (badge/toast) + chat.result (render in chat)
        $this->notifier->notifyStateChange($taskId, 'completed', $task);
        $this->notifier->notifyTaskResult($taskId, $task, $duration);

        // 3. Run the post-task pipeline
        $this->runPostPipeline($taskId, $task, $userId, $conversationId, $options);
    }

    /**
     * Run the post-task pipeline in a separate coroutine.
     */
    private function runPostPipeline(string $taskId, array $task, string $userId, string $conversationId, array $options): void
    {
        \Swoole\Coroutine::create(function () use ($taskId, $task, $userId, $conversationId, $options) {
            try {
                $context = new PipelineContext(
                    task: $task,
                    userId: $userId,
                    templateConfig: [
                        'name' => $options['workflow_template'] ?? 'standard',
                        'max_turns' => $options['max_turns'] ?? 25,
                        'max_budget_usd' => $options['max_budget_usd'] ?? 5.00,
                    ],
                    conversationId: $conversationId,
                    conversationType: 'task',
                );
                $this->pipeline->run($context);
            } catch (Throwable $e) {
                $this->logger->error("AgentSupervisor: pipeline failed for task {$taskId}: {$e->getMessage()}");
            }
        });
    }
}
