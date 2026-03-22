<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Storage\RedisStore;
use Hyperf\Redis\Redis;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

class RedisStoreTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private RedisStore $store;
    private Redis|Mockery\MockInterface $redis;

    protected function setUp(): void
    {
        $this->redis = Mockery::mock(Redis::class);
        $this->store = new RedisStore();
        $this->setProperty($this->store, 'redis', $this->redis);
    }

    // Task operations

    public function testCreateTask(): void
    {
        $taskId = 'task-123';
        $data = ['id' => $taskId, 'prompt' => 'hello'];

        $this->redis->shouldReceive('hMSet')
            ->once()
            ->with('cc:tasks:task-123', $data);
        $this->redis->shouldReceive('zAdd')
            ->once()
            ->with('cc:task_index', Mockery::type('int'), $taskId);

        $this->store->createTask($taskId, $data);
    }

    public function testGetTaskReturnsData(): void
    {
        $this->redis->shouldReceive('hGetAll')
            ->once()
            ->with('cc:tasks:task-123')
            ->andReturn(['id' => 'task-123', 'prompt' => 'hello']);

        $result = $this->store->getTask('task-123');

        $this->assertSame(['id' => 'task-123', 'prompt' => 'hello'], $result);
    }

    public function testGetTaskReturnsNullWhenEmpty(): void
    {
        $this->redis->shouldReceive('hGetAll')
            ->once()
            ->with('cc:tasks:task-missing')
            ->andReturn([]);

        $result = $this->store->getTask('task-missing');

        $this->assertNull($result);
    }

    public function testUpdateTask(): void
    {
        $this->redis->shouldReceive('hMSet')
            ->once()
            ->with('cc:tasks:task-123', ['state' => 'running']);

        $this->store->updateTask('task-123', ['state' => 'running']);
    }

    public function testDeleteTask(): void
    {
        $this->redis->shouldReceive('del')
            ->once()
            ->with('cc:tasks:task-123', 'cc:tasks:task-123:history');
        $this->redis->shouldReceive('zRem')
            ->once()
            ->with('cc:task_index', 'task-123');

        $this->store->deleteTask('task-123');
    }

    // Task history

    public function testAddTaskHistory(): void
    {
        $entry = ['from' => 'pending', 'to' => 'running', 'timestamp' => 1000];

        $this->redis->shouldReceive('rPush')
            ->once()
            ->with('cc:tasks:task-123:history', json_encode($entry));

        $this->store->addTaskHistory('task-123', $entry);
    }

    public function testGetTaskHistory(): void
    {
        $entries = [
            json_encode(['from' => null, 'to' => 'pending']),
            json_encode(['from' => 'pending', 'to' => 'running']),
        ];

        $this->redis->shouldReceive('lRange')
            ->once()
            ->with('cc:tasks:task-123:history', 0, -1)
            ->andReturn($entries);

        $result = $this->store->getTaskHistory('task-123');

        $this->assertCount(2, $result);
        $this->assertNull($result[0]['from']);
        $this->assertSame('pending', $result[0]['to']);
        $this->assertSame('pending', $result[1]['from']);
        $this->assertSame('running', $result[1]['to']);
    }

    public function testGetTaskHistoryReturnsEmptyForNoHistory(): void
    {
        $this->redis->shouldReceive('lRange')
            ->once()
            ->andReturn(false);

        $result = $this->store->getTaskHistory('task-missing');

        $this->assertSame([], $result);
    }

    // Session operations

    public function testCreateSession(): void
    {
        $this->redis->shouldReceive('hMSet')
            ->once()
            ->with('cc:sessions:sess-1', ['id' => 'sess-1']);

        $this->store->createSession('sess-1', ['id' => 'sess-1']);
    }

    public function testGetSession(): void
    {
        $this->redis->shouldReceive('hGetAll')
            ->once()
            ->with('cc:sessions:sess-1')
            ->andReturn(['id' => 'sess-1', 'state' => 'active']);

        $result = $this->store->getSession('sess-1');

        $this->assertSame('active', $result['state']);
    }

    public function testGetSessionReturnsNull(): void
    {
        $this->redis->shouldReceive('hGetAll')
            ->once()
            ->with('cc:sessions:sess-missing')
            ->andReturn([]);

        $result = $this->store->getSession('sess-missing');

        $this->assertNull($result);
    }

    public function testUpdateSession(): void
    {
        $this->redis->shouldReceive('hMSet')
            ->once()
            ->with('cc:sessions:sess-1', ['state' => 'closed']);

        $this->store->updateSession('sess-1', ['state' => 'closed']);
    }

    public function testDeleteSession(): void
    {
        $this->redis->shouldReceive('del')
            ->once()
            ->with('cc:sessions:sess-1');

        $this->store->deleteSession('sess-1');
    }

    public function testListSessions(): void
    {
        $this->redis->shouldReceive('keys')
            ->once()
            ->with('cc:sessions:*')
            ->andReturn(['cc:sessions:s1', 'cc:sessions:s2']);

        $this->redis->shouldReceive('hGetAll')
            ->with('cc:sessions:s1')
            ->andReturn(['id' => 's1']);
        $this->redis->shouldReceive('hGetAll')
            ->with('cc:sessions:s2')
            ->andReturn(['id' => 's2']);

        $result = $this->store->listSessions();

        $this->assertCount(2, $result);
    }

    public function testListSessionsSkipsEmpty(): void
    {
        $this->redis->shouldReceive('keys')
            ->once()
            ->andReturn(['cc:sessions:s1']);

        $this->redis->shouldReceive('hGetAll')
            ->with('cc:sessions:s1')
            ->andReturn([]);

        $result = $this->store->listSessions();

        $this->assertCount(0, $result);
    }

    // Task index

    public function testListTasksNoFilter(): void
    {
        $this->redis->shouldReceive('zRevRange')
            ->once()
            ->with('cc:task_index', 0, 49)
            ->andReturn(['t1', 't2']);

        $this->redis->shouldReceive('hGetAll')
            ->with('cc:tasks:t1')
            ->andReturn(['id' => 't1', 'state' => 'completed']);
        $this->redis->shouldReceive('hGetAll')
            ->with('cc:tasks:t2')
            ->andReturn(['id' => 't2', 'state' => 'running']);

        $result = $this->store->listTasks(null, 50);

        $this->assertCount(2, $result);
    }

    public function testListTasksWithStateFilter(): void
    {
        $this->redis->shouldReceive('zRevRange')
            ->once()
            ->with('cc:task_index', 0, 9)
            ->andReturn(['t1', 't2']);

        $this->redis->shouldReceive('hGetAll')
            ->with('cc:tasks:t1')
            ->andReturn(['id' => 't1', 'state' => 'completed']);
        $this->redis->shouldReceive('hGetAll')
            ->with('cc:tasks:t2')
            ->andReturn(['id' => 't2', 'state' => 'running']);

        $result = $this->store->listTasks('completed', 10);

        $this->assertCount(1, $result);
        $this->assertSame('t1', $result[0]['id']);
    }

    public function testListTasksSkipsNullTasks(): void
    {
        $this->redis->shouldReceive('zRevRange')
            ->once()
            ->andReturn(['t1', 't2']);

        $this->redis->shouldReceive('hGetAll')
            ->with('cc:tasks:t1')
            ->andReturn([]);
        $this->redis->shouldReceive('hGetAll')
            ->with('cc:tasks:t2')
            ->andReturn(['id' => 't2', 'state' => 'running']);

        $result = $this->store->listTasks();

        $this->assertCount(1, $result);
    }

    // Health check

    public function testPingSuccess(): void
    {
        $this->redis->shouldReceive('ping')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->store->ping());
    }

    public function testPingSuccessWithPong(): void
    {
        $this->redis->shouldReceive('ping')
            ->once()
            ->andReturn('+PONG');

        $this->assertTrue($this->store->ping());
    }

    public function testPingFailure(): void
    {
        $this->redis->shouldReceive('ping')
            ->once()
            ->andReturn(false);

        $this->assertFalse($this->store->ping());
    }

    public function testPingException(): void
    {
        $this->redis->shouldReceive('ping')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->assertFalse($this->store->ping());
    }
}
