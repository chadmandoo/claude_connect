<?php

declare(strict_types=1);

namespace App\Claude;

use App\Storage\PostgresStore;
use App\Storage\SwooleTableCache;
use Hyperf\Di\Annotation\Inject;
use Ramsey\Uuid\Uuid;

class SessionManager
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private SwooleTableCache $cache;

    public function createSession(): string
    {
        $sessionId = Uuid::uuid4()->toString();

        $session = [
            'id' => $sessionId,
            'claude_session_id' => '',
            'state' => 'active',
            'created_at' => time(),
            'updated_at' => time(),
            'last_task_id' => '',
        ];

        $this->store->createSession($sessionId, $session);
        $this->cache->setActiveSession($sessionId);

        return $sessionId;
    }

    public function getSession(string $sessionId): ?array
    {
        return $this->store->getSession($sessionId);
    }

    public function updateSession(string $sessionId, array $data): void
    {
        $data['updated_at'] = time();
        $this->store->updateSession($sessionId, $data);
        $this->cache->updateSessionActivity($sessionId, $data['last_task_id'] ?? '');
    }

    public function closeSession(string $sessionId): void
    {
        $this->store->updateSession($sessionId, [
            'state' => 'closed',
            'updated_at' => time(),
        ]);
        $this->cache->removeActiveSession($sessionId);
    }

    public function listSessions(): array
    {
        return $this->store->listSessions();
    }

    public function archiveSession(string $sessionId): void
    {
        $this->store->updateSession($sessionId, [
            'state' => 'archived',
            'updated_at' => time(),
        ]);
        $this->cache->removeActiveSession($sessionId);
    }
}
