<?php

declare(strict_types=1);

namespace Tests\Unit\StateMachine;

use App\StateMachine\TaskManager;
use App\StateMachine\TaskState;
use App\Storage\RedisStore;
use App\Storage\SwooleTableCache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

class TaskManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private TaskManager $manager;
    private RedisStore|Mockery\MockInterface $redis;
    private SwooleTableCache|Mockery\MockInterface $cache;

    protected function setUp(): void
    {
        $this->redis = Mockery::mock(RedisStore::class);
        $this->cache = Mockery::mock(SwooleTableCache::class);

        $this->manager = new TaskManager();
        $this->setProperty($this->manager, 'redis', $this->redis);
        $this->setProperty($this->manager, 'cache', $this->cache);
    }

    public function testCreateTaskReturnsUuid(): void
    {
        $this->redis->shouldReceive('createTask')->once();
        $this->cache->shouldReceive('setActiveTask')->once();
        $this->redis->shouldReceive('addTaskHistory')->once();

        $taskId = $this->manager->createTask('Hello Claude');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $taskId
        );
    }

    public function testCreateTaskStoresCorrectData(): void
    {
        $this->redis->shouldReceive('createTask')
            ->once()
            ->withArgs(function (string $id, array $data) {
                return $data['prompt'] === 'test prompt'
                    && $data['session_id'] === 'sess-1'
                    && $data['state'] === 'pending'
                    && $data['result'] === ''
                    && $data['error'] === ''
                    && $data['pid'] === 0
                    && $data['started_at'] === 0
                    && $data['completed_at'] === 0;
            });
        $this->cache->shouldReceive('setActiveTask')
            ->once()
            ->withArgs(fn($id, $state) => $state === 'pending');
        $this->redis->shouldReceive('addTaskHistory')
            ->once()
            ->withArgs(function ($id, $entry) {
                return $entry['from'] === null && $entry['to'] === 'pending';
            });

        $this->manager->createTask('test prompt', 'sess-1');
    }

    public function testCreateTaskWithOptions(): void
    {
        $this->redis->shouldReceive('createTask')
            ->once()
            ->withArgs(function ($id, $data) {
                $options = json_decode($data['options'], true);
                return $options['max_turns'] === 10 && $options['model'] === 'opus';
            });
        $this->cache->shouldReceive('setActiveTask')->once();
        $this->redis->shouldReceive('addTaskHistory')->once();

        $this->manager->createTask('prompt', null, ['max_turns' => 10, 'model' => 'opus']);
    }

    public function testCreateTaskWithNullSessionIdStoresEmptyString(): void
    {
        $this->redis->shouldReceive('createTask')
            ->once()
            ->withArgs(fn($id, $data) => $data['session_id'] === '');
        $this->cache->shouldReceive('setActiveTask')->once();
        $this->redis->shouldReceive('addTaskHistory')->once();

        $this->manager->createTask('prompt');
    }

    public function testTransitionPendingToRunning(): void
    {
        $this->redis->shouldReceive('getTask')
            ->once()
            ->andReturn(['state' => 'pending', 'started_at' => 0]);
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->withArgs(function ($id, $data) {
                return $data['state'] === 'running'
                    && isset($data['started_at'])
                    && $data['started_at'] > 0;
            });
        $this->cache->shouldReceive('updateTaskState')
            ->once()
            ->with('task-1', 'running');
        $this->redis->shouldReceive('addTaskHistory')->once();

        $this->manager->transition('task-1', TaskState::RUNNING);
    }

    public function testTransitionRunningToCompletedSetsCompletedAt(): void
    {
        $this->redis->shouldReceive('getTask')
            ->once()
            ->andReturn(['state' => 'running', 'started_at' => 1000]);
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->withArgs(function ($id, $data) {
                return $data['state'] === 'completed' && isset($data['completed_at']);
            });
        $this->cache->shouldReceive('updateTaskState')->once();
        $this->redis->shouldReceive('addTaskHistory')->once();
        $this->cache->shouldReceive('removeActiveTask')
            ->once()
            ->with('task-1');

        $this->manager->transition('task-1', TaskState::COMPLETED);
    }

    public function testTransitionToTerminalRemovesFromCache(): void
    {
        $this->redis->shouldReceive('getTask')
            ->andReturn(['state' => 'running', 'started_at' => 1000]);
        $this->redis->shouldReceive('updateTask')->once();
        $this->cache->shouldReceive('updateTaskState')->once();
        $this->redis->shouldReceive('addTaskHistory')->once();
        $this->cache->shouldReceive('removeActiveTask')->once()->with('task-1');

        $this->manager->transition('task-1', TaskState::FAILED);
    }

    public function testTransitionThrowsOnInvalidTransition(): void
    {
        $this->redis->shouldReceive('getTask')
            ->andReturn(['state' => 'pending']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid transition from pending to completed');

        $this->manager->transition('task-1', TaskState::COMPLETED);
    }

    public function testTransitionThrowsWhenTaskNotFound(): void
    {
        $this->redis->shouldReceive('getTask')
            ->andReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task task-missing not found');

        $this->manager->transition('task-missing', TaskState::RUNNING);
    }

    public function testTransitionDoesNotOverrideStartedAt(): void
    {
        // Task already has started_at set from a previous run
        $this->redis->shouldReceive('getTask')
            ->andReturn(['state' => 'pending', 'started_at' => 5000]);
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->withArgs(function ($id, $data) {
                // started_at should NOT be in update since it's already > 0
                return !isset($data['started_at']);
            });
        $this->cache->shouldReceive('updateTaskState')->once();
        $this->redis->shouldReceive('addTaskHistory')->once();

        $this->manager->transition('task-1', TaskState::RUNNING);
    }

    public function testTransitionWithExtra(): void
    {
        $this->redis->shouldReceive('getTask')
            ->andReturn(['state' => 'pending', 'started_at' => 0]);
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->withArgs(fn($id, $data) => $data['custom_field'] === 'value');
        $this->cache->shouldReceive('updateTaskState')->once();
        $this->redis->shouldReceive('addTaskHistory')
            ->once()
            ->withArgs(fn($id, $entry) => $entry['extra'] === ['custom_field' => 'value']);

        $this->manager->transition('task-1', TaskState::RUNNING, ['custom_field' => 'value']);
    }

    public function testGetTaskFromCache(): void
    {
        $this->cache->shouldReceive('getActiveTask')
            ->with('task-1')
            ->andReturn(['task_id' => 'task-1', 'state' => 'running']);
        $this->redis->shouldReceive('getTask')
            ->with('task-1')
            ->once()
            ->andReturn(['id' => 'task-1', 'state' => 'running', 'prompt' => 'hello']);

        $result = $this->manager->getTask('task-1');

        $this->assertSame('task-1', $result['id']);
        $this->assertSame('hello', $result['prompt']);
    }

    public function testGetTaskFallsBackToRedis(): void
    {
        $this->cache->shouldReceive('getActiveTask')
            ->with('task-1')
            ->andReturn(null);
        $this->redis->shouldReceive('getTask')
            ->with('task-1')
            ->once()
            ->andReturn(['id' => 'task-1', 'state' => 'completed']);

        $result = $this->manager->getTask('task-1');

        $this->assertSame('completed', $result['state']);
    }

    public function testGetTaskReturnsNullWhenNotFound(): void
    {
        $this->cache->shouldReceive('getActiveTask')->andReturn(null);
        $this->redis->shouldReceive('getTask')->andReturn(null);

        $this->assertNull($this->manager->getTask('missing'));
    }

    public function testGetTaskCacheHitButRedisMissFallsBack(): void
    {
        $this->cache->shouldReceive('getActiveTask')
            ->andReturn(['task_id' => 'task-1']);
        $this->redis->shouldReceive('getTask')
            ->with('task-1')
            ->twice()
            ->andReturn(null, null);

        $result = $this->manager->getTask('task-1');
        $this->assertNull($result);
    }

    public function testSetTaskPid(): void
    {
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->with('task-1', ['pid' => 12345]);
        $this->cache->shouldReceive('updateTaskPid')
            ->once()
            ->with('task-1', 12345);

        $this->manager->setTaskPid('task-1', 12345);
    }

    public function testSetTaskResult(): void
    {
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->with('task-1', ['result' => 'Hello world']);

        $this->manager->setTaskResult('task-1', 'Hello world');
    }

    public function testSetTaskError(): void
    {
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->with('task-1', ['error' => 'Something broke']);

        $this->manager->setTaskError('task-1', 'Something broke');
    }

    public function testListTasks(): void
    {
        $this->redis->shouldReceive('listTasks')
            ->once()
            ->with('running', 10)
            ->andReturn([['id' => 't1']]);

        $result = $this->manager->listTasks('running', 10);

        $this->assertCount(1, $result);
    }

    public function testGetTaskTransitions(): void
    {
        $this->redis->shouldReceive('getTaskHistory')
            ->once()
            ->with('task-1')
            ->andReturn([['from' => null, 'to' => 'pending']]);

        $result = $this->manager->getTaskTransitions('task-1');

        $this->assertCount(1, $result);
    }

    public function testSetClaudeSessionId(): void
    {
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->with('task-1', ['claude_session_id' => 'claude-sess-abc']);

        $this->manager->setClaudeSessionId('task-1', 'claude-sess-abc');
    }

    public function testSetParentTaskId(): void
    {
        $this->redis->shouldReceive('updateTask')
            ->once()
            ->with('task-1', ['parent_task_id' => 'parent-123']);

        $this->manager->setParentTaskId('task-1', 'parent-123');
    }
}
