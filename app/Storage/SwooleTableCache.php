<?php

declare(strict_types=1);

namespace App\Storage;

use Swoole\Table;

/**
 * In-memory cache using Swoole Tables for cross-worker shared state.
 *
 * Maintains fast-access tables for active tasks, sessions, WebSocket connections,
 * and active conversations. Tables are created before worker forking and shared
 * across all Swoole workers via shared memory.
 */
class SwooleTableCache
{
    private Table $activeTasks;

    private Table $activeSessions;

    private Table $wsConnections;

    private Table $activeConversations;

    public function __construct()
    {
        $this->activeTasks = new Table(1024);
        $this->activeTasks->column('task_id', Table::TYPE_STRING, 64);
        $this->activeTasks->column('state', Table::TYPE_STRING, 32);
        $this->activeTasks->column('pid', Table::TYPE_INT);
        $this->activeTasks->column('started_at', Table::TYPE_INT);
        $this->activeTasks->create();

        $this->activeSessions = new Table(256);
        $this->activeSessions->column('session_id', Table::TYPE_STRING, 64);
        $this->activeSessions->column('task_id', Table::TYPE_STRING, 64);
        $this->activeSessions->column('last_activity', Table::TYPE_INT);
        $this->activeSessions->create();

        $this->wsConnections = new Table(64);
        $this->wsConnections->column('fd', Table::TYPE_INT);
        $this->wsConnections->column('user_id', Table::TYPE_STRING, 64);
        $this->wsConnections->column('connected_at', Table::TYPE_INT);
        $this->wsConnections->column('last_ping', Table::TYPE_INT);
        $this->wsConnections->column('last_pong', Table::TYPE_INT);
        $this->wsConnections->create();

        $this->activeConversations = new Table(64);
        $this->activeConversations->column('conversation_id', Table::TYPE_STRING, 64);
        $this->activeConversations->column('user_id', Table::TYPE_STRING, 64);
        $this->activeConversations->column('project_id', Table::TYPE_STRING, 64);
        $this->activeConversations->column('type', Table::TYPE_STRING, 32);
        $this->activeConversations->column('last_activity', Table::TYPE_INT);
        $this->activeConversations->create();
    }

    // Task cache operations

    public function setActiveTask(string $taskId, string $state, int $pid = 0): void
    {
        $this->activeTasks->set($taskId, [
            'task_id' => $taskId,
            'state' => $state,
            'pid' => $pid,
            'started_at' => time(),
        ]);
    }

    public function getActiveTask(string $taskId): ?array
    {
        $row = $this->activeTasks->get($taskId);

        return $row ?: null;
    }

    public function updateTaskState(string $taskId, string $state): void
    {
        $existing = $this->activeTasks->get($taskId);
        if ($existing) {
            $existing['state'] = $state;
            $this->activeTasks->set($taskId, $existing);
        }
    }

    public function updateTaskPid(string $taskId, int $pid): void
    {
        $existing = $this->activeTasks->get($taskId);
        if ($existing) {
            $existing['pid'] = $pid;
            $this->activeTasks->set($taskId, $existing);
        }
    }

    public function removeActiveTask(string $taskId): void
    {
        $this->activeTasks->del($taskId);
    }

    public function getActiveTasks(): array
    {
        $tasks = [];
        foreach ($this->activeTasks as $key => $row) {
            $tasks[$key] = $row;
        }

        return $tasks;
    }

    // Session cache operations

    public function setActiveSession(string $sessionId, string $taskId = ''): void
    {
        $this->activeSessions->set($sessionId, [
            'session_id' => $sessionId,
            'task_id' => $taskId,
            'last_activity' => time(),
        ]);
    }

    public function getActiveSession(string $sessionId): ?array
    {
        $row = $this->activeSessions->get($sessionId);

        return $row ?: null;
    }

    public function updateSessionActivity(string $sessionId, string $taskId = ''): void
    {
        $existing = $this->activeSessions->get($sessionId);
        if ($existing) {
            $existing['last_activity'] = time();
            if ($taskId !== '') {
                $existing['task_id'] = $taskId;
            }
            $this->activeSessions->set($sessionId, $existing);
        }
    }

    public function removeActiveSession(string $sessionId): void
    {
        $this->activeSessions->del($sessionId);
    }

    public function getActiveSessions(): array
    {
        $sessions = [];
        foreach ($this->activeSessions as $key => $row) {
            $sessions[$key] = $row;
        }

        return $sessions;
    }

    // WebSocket connection operations

    public function setWsConnection(int $fd, string $userId): void
    {
        $this->wsConnections->set((string) $fd, [
            'fd' => $fd,
            'user_id' => $userId,
            'connected_at' => time(),
        ]);
    }

    public function removeWsConnection(int $fd): void
    {
        $this->wsConnections->del((string) $fd);
    }

    public function getWsConnection(int $fd): ?array
    {
        $row = $this->wsConnections->get((string) $fd);

        return $row ?: null;
    }

    public function updateWsConnectionPing(int $fd): void
    {
        $existing = $this->wsConnections->get((string) $fd);
        if ($existing) {
            $existing['last_ping'] = time();
            $this->wsConnections->set((string) $fd, $existing);
        }
    }

    public function updateWsConnectionPong(int $fd): void
    {
        $existing = $this->wsConnections->get((string) $fd);
        if ($existing) {
            $existing['last_pong'] = time();
            $this->wsConnections->set((string) $fd, $existing);
        }
    }

    public function getWsConnections(): array
    {
        $connections = [];
        foreach ($this->wsConnections as $key => $row) {
            $connections[(int) $key] = $row;
        }

        return $connections;
    }

    // Active conversation operations

    public function setActiveConversation(string $conversationId, string $userId, string $projectId = 'general', string $type = 'task'): void
    {
        $this->activeConversations->set($conversationId, [
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'type' => $type,
            'last_activity' => time(),
        ]);
    }

    public function getActiveConversation(string $conversationId): ?array
    {
        $row = $this->activeConversations->get($conversationId);

        return $row ?: null;
    }

    public function updateConversationActivity(string $conversationId): void
    {
        $existing = $this->activeConversations->get($conversationId);
        if ($existing) {
            $existing['last_activity'] = time();
            $this->activeConversations->set($conversationId, $existing);
        }
    }

    public function removeActiveConversation(string $conversationId): void
    {
        $this->activeConversations->del($conversationId);
    }
}
