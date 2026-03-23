<?php

declare(strict_types=1);

namespace App\Listener;

use App\Chat\ChatConversationStore;
use App\Conversation\ConversationManager;
use App\StateMachine\TaskManager;
use App\Web\TaskNotifier;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Redis;
use Throwable;

/**
 * Subscribes to Redis pub/sub channel for task completions from the external worker.
 * Instantly triggers WebSocket notifications and conversation recording.
 */
#[Listener]
class TaskCompletionListener implements ListenerInterface
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
            $config = $this->container->get(ConfigInterface::class);

            $redisHost = $config->get('redis.default.host', '127.0.0.1');
            $redisPort = (int) $config->get('redis.default.port', 6380);

            $logger->info('TaskCompletionListener: subscribing to Redis pub/sub on worker 0');

            try {
                // Use a dedicated Redis connection for subscribing (subscribe blocks)
                $subRedis = new Redis();
                $subRedis->connect($redisHost, $redisPort);
                $subRedis->setOption(Redis::OPT_READ_TIMEOUT, -1); // Block forever

                $subRedis->subscribe(['cc:task_completions'], function ($redis, $channel, $message) use ($logger) {
                    try {
                        $data = json_decode($message, true);
                        if (!$data || empty($data['task_id'])) {
                            return;
                        }

                        $taskId = $data['task_id'];
                        $state = $data['state'] ?? '';

                        $logger->info("TaskCompletionListener: received {$state} for task {$taskId}");

                        $this->handleCompletion($taskId, $state);
                    } catch (Throwable $e) {
                        $logger->error("TaskCompletionListener: error handling message: {$e->getMessage()}");
                    }
                });
            } catch (Throwable $e) {
                $logger->error("TaskCompletionListener: Redis subscribe failed: {$e->getMessage()}");
            }
        });
    }

    private function handleCompletion(string $taskId, string $state): void
    {
        $taskManager = $this->container->get(TaskManager::class);
        $conversationManager = $this->container->get(ConversationManager::class);
        $chatStore = $this->container->get(ChatConversationStore::class);
        $notifier = $this->container->get(TaskNotifier::class);
        $logger = $this->container->get(LoggerInterface::class);

        $task = $taskManager->getTask($taskId);
        if (!$task) {
            $logger->warning("TaskCompletionListener: task {$taskId} not found");

            return;
        }

        $options = json_decode($task['options'] ?? '{}', true) ?: [];
        // conversation_id can be in options OR as a top-level task field
        $conversationId = $options['conversation_id'] ?? $task['conversation_id'] ?? '';
        $userId = $options['web_user_id'] ?? $task['web_user_id'] ?? '';
        $result = $task['result'] ?? '';
        $cost = (float) ($task['cost_usd'] ?? 0);
        $startedAt = (int) ($task['started_at'] ?? time());
        $duration = time() - $startedAt;

        $source = $task['source'] ?? $options['source'] ?? 'web';
        $isManager = $source === 'manager';

        if ($state === 'completed') {
            // Note: conversation turn is already written by bin/task-worker.php's recordConversationTurn.
            // We only append to chat history (ephemeral Redis) for API mode context.
            if ($conversationId !== '' && $result !== '') {
                try {
                    $chatStore->appendMessage($conversationId, [
                        'role' => 'assistant',
                        'content' => $result,
                    ]);
                } catch (Throwable $e) {
                    $logger->warning("TaskCompletionListener: failed to append chat history for {$taskId}: {$e->getMessage()}");
                }
            }

            // Send WebSocket notifications
            $notifier->notifyStateChange($taskId, 'completed', $task);

            if ($isManager) {
                // Push result directly to Manager UI
                $notifier->broadcastToUser([
                    'type' => 'manager.result',
                    'task_id' => $taskId,
                    'conversation_id' => $conversationId,
                    'result' => $result,
                    'cost_usd' => $cost,
                    'duration' => $duration,
                ], $userId);
            } else {
                $notifier->notifyTaskResult($taskId, $task, $duration);
            }

            $logger->info("TaskCompletionListener: notified completion for task {$taskId} (conv={$conversationId}, source={$source})");
        } elseif ($state === 'failed') {
            $notifier->notifyStateChange($taskId, 'failed', $task);

            if ($isManager) {
                $notifier->broadcastToUser([
                    'type' => 'manager.error',
                    'task_id' => $taskId,
                    'error' => $task['error'] ?? 'Task failed',
                ], $userId);
            }

            $logger->info("TaskCompletionListener: notified failure for task {$taskId}");
        }
    }
}
