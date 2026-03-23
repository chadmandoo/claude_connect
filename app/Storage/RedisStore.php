<?php

declare(strict_types=1);

namespace App\Storage;

use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Throwable;

/**
 * Redis store — ephemeral operations only.
 *
 * Handles: auth tokens, chat API history, distributed locks, pub/sub, active project state.
 * All persistent data has moved to PostgresStore.
 */
class RedisStore
{
    private const PREFIX = 'cc:';

    #[Inject]
    private Redis $redis;

    // =========================================================================
    // Web auth token operations (TTL-based)
    // =========================================================================

    public function setWebToken(string $token, int $ttl = 86400): void
    {
        $this->redis->setex(self::PREFIX . "web:token:{$token}", $ttl, '1');
    }

    public function hasWebToken(string $token): bool
    {
        return (bool) $this->redis->exists(self::PREFIX . "web:token:{$token}");
    }

    public function deleteWebToken(string $token): void
    {
        $this->redis->del(self::PREFIX . "web:token:{$token}");
    }

    // =========================================================================
    // Distributed locks
    // =========================================================================

    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        return (bool) $this->redis->set(
            self::PREFIX . $key,
            (string) time(),
            ['NX', 'EX' => $ttlSeconds],
        );
    }

    public function releaseLock(string $key): void
    {
        $this->redis->del(self::PREFIX . $key);
    }

    public function hasLock(string $key): bool
    {
        return (bool) $this->redis->exists(self::PREFIX . $key);
    }

    // =========================================================================
    // Chat history operations (Anthropic API message format — ephemeral)
    // =========================================================================

    public function appendChatHistory(string $conversationId, array $message): void
    {
        $this->redis->rPush(
            self::PREFIX . "chat_history:{$conversationId}",
            json_encode($message),
        );
    }

    public function getChatHistory(string $conversationId, int $limit = 50): array
    {
        $total = (int) $this->redis->lLen(self::PREFIX . "chat_history:{$conversationId}");
        $start = max(0, $total - $limit);
        $raw = $this->redis->lRange(self::PREFIX . "chat_history:{$conversationId}", $start, -1);

        return array_values(array_filter(
            array_map(fn (string $item) => json_decode($item, true), $raw ?: []),
            fn ($entry) => is_array($entry),
        ));
    }

    public function trimChatHistory(string $conversationId, int $keep): void
    {
        $total = (int) $this->redis->lLen(self::PREFIX . "chat_history:{$conversationId}");
        if ($total > $keep) {
            $this->redis->lTrim(self::PREFIX . "chat_history:{$conversationId}", $total - $keep, -1);
        }
    }

    public function deleteChatHistory(string $conversationId): void
    {
        $this->redis->del(self::PREFIX . "chat_history:{$conversationId}");
    }

    // =========================================================================
    // Active project state (ephemeral UI state)
    // =========================================================================

    public function getActiveProjectId(): ?string
    {
        $id = $this->redis->get(self::PREFIX . 'project:active');

        return $id ?: null;
    }

    public function setActiveProject(string $projectId): void
    {
        $this->redis->set(self::PREFIX . 'project:active', $projectId);
    }

    public function clearActiveProject(): void
    {
        $this->redis->del(self::PREFIX . 'project:active');
    }

    // =========================================================================
    // Health check
    // =========================================================================

    public function ping(): bool
    {
        try {
            $result = $this->redis->ping();

            return $result === true || $result === '+PONG';
        } catch (Throwable) {
            return false;
        }
    }
}
