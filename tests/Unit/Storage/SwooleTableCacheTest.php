<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Storage\SwooleTableCache;
use PHPUnit\Framework\TestCase;

class SwooleTableCacheTest extends TestCase
{
    private SwooleTableCache $cache;

    protected function setUp(): void
    {
        $this->cache = new SwooleTableCache();
    }

    // Task cache operations

    public function testSetAndGetActiveTask(): void
    {
        $this->cache->setActiveTask('task-1', 'pending', 0);

        $task = $this->cache->getActiveTask('task-1');

        $this->assertNotNull($task);
        $this->assertSame('task-1', $task['task_id']);
        $this->assertSame('pending', $task['state']);
        $this->assertSame(0, $task['pid']);
        $this->assertGreaterThan(0, $task['started_at']);
    }

    public function testGetActiveTaskReturnsNullForMissing(): void
    {
        $this->assertNull($this->cache->getActiveTask('nonexistent'));
    }

    public function testUpdateTaskState(): void
    {
        $this->cache->setActiveTask('task-1', 'pending');

        $this->cache->updateTaskState('task-1', 'running');

        $task = $this->cache->getActiveTask('task-1');
        $this->assertSame('running', $task['state']);
    }

    public function testUpdateTaskStateNonExistentIsNoop(): void
    {
        // Should not throw
        $this->cache->updateTaskState('nonexistent', 'running');
        $this->assertNull($this->cache->getActiveTask('nonexistent'));
    }

    public function testUpdateTaskPid(): void
    {
        $this->cache->setActiveTask('task-1', 'running');

        $this->cache->updateTaskPid('task-1', 12345);

        $task = $this->cache->getActiveTask('task-1');
        $this->assertSame(12345, $task['pid']);
    }

    public function testUpdateTaskPidNonExistentIsNoop(): void
    {
        $this->cache->updateTaskPid('nonexistent', 99);
        $this->assertNull($this->cache->getActiveTask('nonexistent'));
    }

    public function testRemoveActiveTask(): void
    {
        $this->cache->setActiveTask('task-1', 'running');

        $this->cache->removeActiveTask('task-1');

        $this->assertNull($this->cache->getActiveTask('task-1'));
    }

    public function testGetActiveTasks(): void
    {
        $this->cache->setActiveTask('task-1', 'pending');
        $this->cache->setActiveTask('task-2', 'running');

        $tasks = $this->cache->getActiveTasks();

        $this->assertCount(2, $tasks);
        $this->assertArrayHasKey('task-1', $tasks);
        $this->assertArrayHasKey('task-2', $tasks);
    }

    public function testGetActiveTasksEmpty(): void
    {
        $tasks = $this->cache->getActiveTasks();
        $this->assertCount(0, $tasks);
    }

    public function testSetActiveTaskWithPid(): void
    {
        $this->cache->setActiveTask('task-1', 'running', 54321);

        $task = $this->cache->getActiveTask('task-1');
        $this->assertSame(54321, $task['pid']);
    }

    // Session cache operations

    public function testSetAndGetActiveSession(): void
    {
        $this->cache->setActiveSession('sess-1');

        $session = $this->cache->getActiveSession('sess-1');

        $this->assertNotNull($session);
        $this->assertSame('sess-1', $session['session_id']);
        $this->assertSame('', $session['task_id']);
        $this->assertGreaterThan(0, $session['last_activity']);
    }

    public function testSetActiveSessionWithTaskId(): void
    {
        $this->cache->setActiveSession('sess-1', 'task-99');

        $session = $this->cache->getActiveSession('sess-1');
        $this->assertSame('task-99', $session['task_id']);
    }

    public function testGetActiveSessionReturnsNullForMissing(): void
    {
        $this->assertNull($this->cache->getActiveSession('nonexistent'));
    }

    public function testUpdateSessionActivity(): void
    {
        $this->cache->setActiveSession('sess-1');

        // Sleep briefly to get a different timestamp
        $before = $this->cache->getActiveSession('sess-1')['last_activity'];

        $this->cache->updateSessionActivity('sess-1', 'task-42');

        $session = $this->cache->getActiveSession('sess-1');
        $this->assertSame('task-42', $session['task_id']);
        $this->assertGreaterThanOrEqual($before, $session['last_activity']);
    }

    public function testUpdateSessionActivityWithoutTaskIdPreservesExisting(): void
    {
        $this->cache->setActiveSession('sess-1', 'task-original');

        $this->cache->updateSessionActivity('sess-1');

        $session = $this->cache->getActiveSession('sess-1');
        $this->assertSame('task-original', $session['task_id']);
    }

    public function testUpdateSessionActivityNonExistentIsNoop(): void
    {
        $this->cache->updateSessionActivity('nonexistent', 'task-1');
        $this->assertNull($this->cache->getActiveSession('nonexistent'));
    }

    public function testRemoveActiveSession(): void
    {
        $this->cache->setActiveSession('sess-1');

        $this->cache->removeActiveSession('sess-1');

        $this->assertNull($this->cache->getActiveSession('sess-1'));
    }

    public function testGetActiveSessions(): void
    {
        $this->cache->setActiveSession('sess-1');
        $this->cache->setActiveSession('sess-2', 'task-1');

        $sessions = $this->cache->getActiveSessions();

        $this->assertCount(2, $sessions);
        $this->assertArrayHasKey('sess-1', $sessions);
        $this->assertArrayHasKey('sess-2', $sessions);
    }

    public function testGetActiveSessionsEmpty(): void
    {
        $sessions = $this->cache->getActiveSessions();
        $this->assertCount(0, $sessions);
    }
}
