<?php

declare(strict_types=1);

namespace App\Web;

use App\Scheduler\SystemChannel;
use App\Storage\PostgresStore;
use App\Storage\SwooleTableCache;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server;
use Throwable;

/**
 * Broadcasts task state changes, results, and progress updates to WebSocket clients.
 *
 * Filters internal tasks (routing, extraction, cleanup) from user-facing notifications
 * and uses atomic claim logic to prevent duplicate notifications across workers.
 */
class TaskNotifier
{
    #[Inject]
    private SwooleTableCache $cache;

    #[Inject]
    private LoggerInterface $logger;

    #[Inject]
    private SystemChannel $systemChannel;

    #[Inject]
    private PostgresStore $store;

    private ?Server $server = null;

    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * Notify all connected clients about a task state change.
     * Checks notified_at flag to prevent duplicate notifications.
     */
    public function notifyStateChange(string $taskId, string $state, array $task): void
    {
        $options = json_decode($task['options'] ?? '{}', true) ?: [];

        // Always post to #system channel
        $promptPreview = mb_substr($task['prompt'] ?? '', 0, 80);
        $this->systemChannel->postTaskUpdate($taskId, $state, $promptPreview);

        // Only notify clients for user-facing tasks
        $source = $task['source'] ?? $options['source'] ?? 'web';
        if (in_array($source, ['routing', 'extraction', 'cleanup', 'nightly', 'item_agent', 'manager'], true)) {
            return;
        }

        // Atomically claim notification — only the first caller wins
        if (!$this->store->markNotified($taskId)) {
            return;
        }

        $payload = [
            'type' => 'task.state_changed',
            'task_id' => $taskId,
            'state' => $state,
            'conversation_id' => $options['conversation_id'] ?? $task['conversation_id'] ?? '',
            'prompt_preview' => mb_substr($task['prompt'] ?? '', 0, 100),
            'cost_usd' => (float) ($task['cost_usd'] ?? 0),
            'timestamp' => time(),
        ];

        if ($state === 'completed' && !empty($task['result'])) {
            $payload['result_preview'] = mb_substr($task['result'], 0, 200);
        }
        if ($state === 'failed' && !empty($task['error'])) {
            $payload['error'] = mb_substr($task['error'], 0, 200);
        }

        $this->broadcast($payload, $options['web_user_id'] ?? null);
    }

    /**
     * Send a chat.result message for a completed background task.
     * This renders the result in the user's conversation view — NOT a notification.
     * Does not trigger the notification bell (no background flag needed).
     */
    public function notifyTaskResult(string $taskId, array $task, int $duration = 0): void
    {
        $options = json_decode($task['options'] ?? '{}', true);
        $conversationId = $options['conversation_id'] ?? '';
        $result = $task['result'] ?? '';

        if ($conversationId === '' || $result === '') {
            return;
        }

        $images = [];
        if (!empty($task['images'])) {
            $decoded = json_decode($task['images'], true);
            if (is_array($decoded)) {
                $images = $decoded;
            }
        }

        $payload = [
            'type' => 'chat.result',
            'task_id' => $taskId,
            'conversation_id' => $conversationId,
            'result' => $result,
            'claude_session_id' => $task['claude_session_id'] ?? '',
            'cost_usd' => (float) ($task['cost_usd'] ?? 0),
            'duration' => $duration,
            'images' => $images,
        ];

        $this->broadcast($payload, $options['web_user_id'] ?? null);
    }

    /**
     * Notify clients about task progress.
     */
    public function notifyProgress(string $taskId, int $elapsed, int $stderrLines): void
    {
        $this->broadcast([
            'type' => 'task.progress',
            'task_id' => $taskId,
            'elapsed' => $elapsed,
            'stderr_lines' => $stderrLines,
            'timestamp' => time(),
        ]);
    }

    /**
     * Broadcast a payload to a specific user's WebSocket connections.
     */
    public function broadcastToUser(array $data, string $userId): void
    {
        $this->broadcast($data, $userId !== '' ? $userId : null);
    }

    /**
     * Broadcast a message to all connected WS clients (optionally filtered by user_id).
     */
    private function broadcast(array $data, ?string $userId = null): void
    {
        if ($this->server === null) {
            return;
        }

        $connections = $this->cache->getWsConnections();
        $json = json_encode($data);

        foreach ($connections as $fd => $conn) {
            if ($userId !== null && ($conn['user_id'] ?? '') !== $userId) {
                continue;
            }

            try {
                if ($this->server->isEstablished($fd)) {
                    $this->server->push($fd, $json);
                }
            } catch (Throwable $e) {
                $this->logger->debug("TaskNotifier: push failed for fd={$fd}: {$e->getMessage()}");
            }
        }
    }
}
