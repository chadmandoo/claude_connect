<?php

declare(strict_types=1);

namespace App\StateMachine;

use App\Storage\PostgresStore;
use App\Storage\SwooleTableCache;
use Hyperf\Di\Annotation\Inject;
use Ramsey\Uuid\Uuid;

class TaskManager
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private SwooleTableCache $cache;

    public function createTask(string $prompt, ?string $sessionId = null, array $options = []): string
    {
        $taskId = Uuid::uuid4()->toString();

        $task = [
            'id' => $taskId,
            'prompt' => $prompt,
            'session_id' => $sessionId ?? '',
            'claude_session_id' => '',
            'parent_task_id' => '',
            'conversation_id' => $options['conversation_id'] ?? '',
            'project_id' => $options['project_id'] ?? 'general',
            'source' => $options['source'] ?? 'web',
            'state' => TaskState::PENDING->value,
            'result' => '',
            'error' => '',
            'pid' => 0,
            'created_at' => time(),
            'updated_at' => time(),
            'started_at' => 0,
            'completed_at' => 0,
            'options' => json_encode($options),
        ];

        $this->store->createTask($taskId, $task);
        $this->cache->setActiveTask($taskId, TaskState::PENDING->value);

        $this->store->addTaskHistory($taskId, [
            'from' => null,
            'to' => TaskState::PENDING->value,
            'timestamp' => time(),
        ]);

        return $taskId;
    }

    public function transition(string $taskId, TaskState $targetState, array $extra = []): void
    {
        $task = $this->store->getTask($taskId);
        if (!$task) {
            throw new \RuntimeException("Task {$taskId} not found");
        }

        $currentState = TaskState::from($task['state']);

        if (!$currentState->canTransitionTo($targetState)) {
            throw new \RuntimeException(
                "Invalid transition from {$currentState->value} to {$targetState->value} for task {$taskId}"
            );
        }

        $update = array_merge($extra, [
            'state' => $targetState->value,
            'updated_at' => time(),
        ]);

        if ($targetState === TaskState::RUNNING && (int) ($task['started_at'] ?? 0) === 0) {
            $update['started_at'] = time();
        }

        if ($targetState->isTerminal()) {
            $update['completed_at'] = time();
        }

        $this->store->updateTask($taskId, $update);
        $this->cache->updateTaskState($taskId, $targetState->value);

        $this->store->addTaskHistory($taskId, [
            'from' => $currentState->value,
            'to' => $targetState->value,
            'timestamp' => time(),
            'extra' => $extra,
        ]);

        if ($targetState->isTerminal()) {
            $this->cache->removeActiveTask($taskId);
        }
    }

    public function getTask(string $taskId): ?array
    {
        $cached = $this->cache->getActiveTask($taskId);
        if ($cached) {
            $full = $this->store->getTask($taskId);
            if ($full) {
                return $full;
            }
        }

        return $this->store->getTask($taskId);
    }

    public function setTaskPid(string $taskId, int $pid): void
    {
        $this->store->updateTask($taskId, ['pid' => $pid]);
        $this->cache->updateTaskPid($taskId, $pid);
    }

    public function setTaskResult(string $taskId, string $result): void
    {
        $this->store->updateTask($taskId, ['result' => $result]);
    }

    public function setTaskError(string $taskId, string $error): void
    {
        $this->store->updateTask($taskId, ['error' => $error]);
    }

    public function setTaskCost(string $taskId, float $costUsd): void
    {
        $this->store->updateTask($taskId, ['cost_usd' => number_format($costUsd, 6, '.', '')]);
    }

    public function listTasks(?string $state = null, int $limit = 50): array
    {
        return $this->store->listTasks($state, $limit);
    }

    public function getTaskTransitions(string $taskId): array
    {
        return $this->store->getTaskHistory($taskId);
    }

    public function setClaudeSessionId(string $taskId, string $claudeSessionId): void
    {
        $this->store->updateTask($taskId, ['claude_session_id' => $claudeSessionId]);
    }

    public function setParentTaskId(string $taskId, string $parentTaskId): void
    {
        $this->store->updateTask($taskId, ['parent_task_id' => $parentTaskId]);
    }

    public function setConversationId(string $taskId, string $conversationId): void
    {
        $this->store->updateTask($taskId, ['conversation_id' => $conversationId]);
    }

    public function updateTaskOptions(string $taskId, array $options): void
    {
        $this->store->updateTask($taskId, ['options' => json_encode($options)]);
    }

    /**
     * Reset a task for retry: clear session_id, update prompt, reset state to PENDING.
     */
    public function resetTaskForRetry(string $taskId, string $newPrompt): void
    {
        $this->store->updateTask($taskId, [
            'session_id' => '',
            'prompt' => $newPrompt,
            'state' => TaskState::PENDING->value,
            'error' => '',
        ]);
    }

    public function deleteTask(string $taskId): void
    {
        $this->cache->removeActiveTask($taskId);
        $this->store->deleteTask($taskId);
    }

    public function setTaskImages(string $taskId, array $images): void
    {
        $this->store->updateTask($taskId, ['images' => json_encode($images)]);
    }

    public function getTaskImages(string $taskId): array
    {
        $task = $this->store->getTask($taskId);
        if (!$task || empty($task['images'])) {
            return [];
        }
        return json_decode($task['images'], true) ?: [];
    }

    public function setTaskProgress(string $taskId, array $progress): void
    {
        $this->store->updateTask($taskId, ['progress' => json_encode($progress)]);
    }

    public function getTaskProgress(string $taskId): ?array
    {
        $task = $this->store->getTask($taskId);
        if (!$task || empty($task['progress'])) {
            return null;
        }
        return json_decode($task['progress'], true) ?: null;
    }
}
