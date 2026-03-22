<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\HealthController;
use App\Storage\RedisStore;
use App\Storage\SwooleTableCache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

class HealthControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private HealthController $controller;
    private RedisStore|Mockery\MockInterface $redis;
    private SwooleTableCache|Mockery\MockInterface $cache;

    protected function setUp(): void
    {
        $this->redis = Mockery::mock(RedisStore::class);
        $this->cache = Mockery::mock(SwooleTableCache::class);

        $this->controller = new HealthController();
        $this->setProperty($this->controller, 'redis', $this->redis);
        $this->setProperty($this->controller, 'cache', $this->cache);
    }

    public function testHealthyWhenRedisOk(): void
    {
        $this->redis->shouldReceive('ping')->andReturn(true);
        $this->cache->shouldReceive('getActiveTasks')->andReturn([]);
        $this->cache->shouldReceive('getActiveSessions')->andReturn([]);

        $result = $this->controller->index();

        $this->assertSame('healthy', $result['status']);
        $this->assertSame('ok', $result['checks']['redis']);
        $this->assertSame('ok', $result['checks']['swoole']);
        $this->assertSame(0, $result['stats']['active_tasks']);
        $this->assertSame(0, $result['stats']['active_sessions']);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('worker_id', $result['stats']);
    }

    public function testDegradedWhenRedisDown(): void
    {
        $this->redis->shouldReceive('ping')->andReturn(false);
        $this->cache->shouldReceive('getActiveTasks')->andReturn([]);
        $this->cache->shouldReceive('getActiveSessions')->andReturn([]);

        $result = $this->controller->index();

        $this->assertSame('degraded', $result['status']);
        $this->assertSame('error', $result['checks']['redis']);
    }

    public function testDegradedWhenRedisThrows(): void
    {
        $this->redis->shouldReceive('ping')
            ->andThrow(new \RuntimeException('Connection refused'));
        $this->cache->shouldReceive('getActiveTasks')->andReturn([]);
        $this->cache->shouldReceive('getActiveSessions')->andReturn([]);

        $result = $this->controller->index();

        $this->assertSame('degraded', $result['status']);
        $this->assertSame('error', $result['checks']['redis']);
    }

    public function testReportsActiveTasksCount(): void
    {
        $this->redis->shouldReceive('ping')->andReturn(true);
        $this->cache->shouldReceive('getActiveTasks')->andReturn([
            'task-1' => ['task_id' => 'task-1'],
            'task-2' => ['task_id' => 'task-2'],
        ]);
        $this->cache->shouldReceive('getActiveSessions')->andReturn([]);

        $result = $this->controller->index();

        $this->assertSame(2, $result['stats']['active_tasks']);
    }

    public function testReportsActiveSessionsCount(): void
    {
        $this->redis->shouldReceive('ping')->andReturn(true);
        $this->cache->shouldReceive('getActiveTasks')->andReturn([]);
        $this->cache->shouldReceive('getActiveSessions')->andReturn([
            'sess-1' => ['session_id' => 'sess-1'],
        ]);

        $result = $this->controller->index();

        $this->assertSame(1, $result['stats']['active_sessions']);
    }

    public function testTimestampIsIso8601(): void
    {
        $this->redis->shouldReceive('ping')->andReturn(true);
        $this->cache->shouldReceive('getActiveTasks')->andReturn([]);
        $this->cache->shouldReceive('getActiveSessions')->andReturn([]);

        $result = $this->controller->index();

        // Verify timestamp is parseable
        $parsed = strtotime($result['timestamp']);
        $this->assertNotFalse($parsed);
    }
}
