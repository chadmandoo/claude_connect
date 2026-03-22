<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Storage\PostgresStore;
use App\Storage\SwooleTableCache;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server;

/**
 * Posts system notifications to a dedicated #system channel.
 * Ensures the channel exists and broadcasts messages to all connected clients.
 */
class SystemChannel
{
    private const CHANNEL_ID = 'system_channel';
    private const CHANNEL_NAME = 'system';

    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private SwooleTableCache $cache;

    #[Inject]
    private LoggerInterface $logger;

    /**
     * Ensure the #system channel exists in Redis.
     */
    public function ensureChannel(): void
    {
        $existing = $this->store->getChannel(self::CHANNEL_ID);
        if (!$existing) {
            $this->store->saveChannel([
                'id' => self::CHANNEL_ID,
                'name' => self::CHANNEL_NAME,
                'description' => 'System notifications, task updates, and scheduler events',
                'member_count' => 0,
                'created_at' => time(),
            ]);
            $this->logger->info('SystemChannel: created #system channel');
        }
    }

    /**
     * Post a notification to the #system channel and broadcast to all WS clients.
     */
    public function post(string $content, string $author = 'System', ?Server $server = null): void
    {
        $message = [
            'id' => uniqid('sys_', true),
            'channel_id' => self::CHANNEL_ID,
            'author' => $author,
            'content' => $content,
            'created_at' => time(),
        ];

        $this->store->saveChannelMessage(self::CHANNEL_ID, $message);

        // Broadcast to all connected clients
        if ($server) {
            $this->broadcastToClients($server, $message);
        } else {
            // Try to get server from context
            try {
                $server = \Hyperf\Context\ApplicationContext::getContainer()->get(\Swoole\Server::class);
                if ($server instanceof Server) {
                    $this->broadcastToClients($server, $message);
                }
            } catch (\Throwable) {
                // No server available, message is still saved in Redis
            }
        }
    }

    /**
     * Post a task state change notification.
     */
    public function postTaskUpdate(string $taskId, string $state, string $promptPreview): void
    {
        $emoji = match ($state) {
            'running' => '🔄',
            'completed' => '✅',
            'failed' => '❌',
            default => '📋',
        };

        $shortId = substr($taskId, 0, 8);
        $this->post("{$emoji} **Task `{$shortId}`** → **{$state}**\n> {$promptPreview}");
    }

    /**
     * Post a scheduler job execution notification.
     */
    public function postSchedulerRun(string $jobName, string $result, int $duration): void
    {
        $this->post("⏰ **Scheduled: {$jobName}** completed in {$duration}s\n> {$result}", 'Scheduler');
    }

    /**
     * Post a supervisor health check notification.
     */
    public function postSupervisorStatus(int $running, int $pending, int $completed): void
    {
        $this->post(
            "📊 **Supervisor Status** — {$running} running, {$pending} pending, {$completed} completed today",
            'Supervisor'
        );
    }

    private function broadcastToClients(Server $server, array $message): void
    {
        $connections = $this->cache->getWsConnections();
        $payload = json_encode([
            'type' => 'channels.message',
            'channel_id' => self::CHANNEL_ID,
            'message' => $message,
        ]);

        foreach ($connections as $fd => $conn) {
            try {
                if ($server->isEstablished((int) $fd)) {
                    $server->push((int) $fd, $payload);
                }
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    public function getChannelId(): string
    {
        return self::CHANNEL_ID;
    }
}
