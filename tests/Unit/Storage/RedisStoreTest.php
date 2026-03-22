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

    // =========================================================================
    // Web auth token operations
    // =========================================================================

    public function testSetWebToken(): void
    {
        $this->redis->shouldReceive('setex')
            ->once()
            ->with('cc:web:token:abc123', 86400, '1');

        $this->store->setWebToken('abc123');
    }

    public function testSetWebTokenWithCustomTtl(): void
    {
        $this->redis->shouldReceive('setex')
            ->once()
            ->with('cc:web:token:abc123', 3600, '1');

        $this->store->setWebToken('abc123', 3600);
    }

    public function testHasWebTokenReturnsTrue(): void
    {
        $this->redis->shouldReceive('exists')
            ->once()
            ->with('cc:web:token:abc123')
            ->andReturn(1);

        $this->assertTrue($this->store->hasWebToken('abc123'));
    }

    public function testHasWebTokenReturnsFalse(): void
    {
        $this->redis->shouldReceive('exists')
            ->once()
            ->with('cc:web:token:missing')
            ->andReturn(0);

        $this->assertFalse($this->store->hasWebToken('missing'));
    }

    public function testDeleteWebToken(): void
    {
        $this->redis->shouldReceive('del')
            ->once()
            ->with('cc:web:token:abc123');

        $this->store->deleteWebToken('abc123');
    }

    // =========================================================================
    // Distributed locks
    // =========================================================================

    public function testAcquireLockSuccess(): void
    {
        $this->redis->shouldReceive('set')
            ->once()
            ->with('cc:my-lock', Mockery::type('string'), ['NX', 'EX' => 30])
            ->andReturn(true);

        $this->assertTrue($this->store->acquireLock('my-lock', 30));
    }

    public function testAcquireLockFailure(): void
    {
        $this->redis->shouldReceive('set')
            ->once()
            ->with('cc:my-lock', Mockery::type('string'), ['NX', 'EX' => 30])
            ->andReturn(false);

        $this->assertFalse($this->store->acquireLock('my-lock', 30));
    }

    public function testReleaseLock(): void
    {
        $this->redis->shouldReceive('del')
            ->once()
            ->with('cc:my-lock');

        $this->store->releaseLock('my-lock');
    }

    public function testHasLockReturnsTrue(): void
    {
        $this->redis->shouldReceive('exists')
            ->once()
            ->with('cc:my-lock')
            ->andReturn(1);

        $this->assertTrue($this->store->hasLock('my-lock'));
    }

    public function testHasLockReturnsFalse(): void
    {
        $this->redis->shouldReceive('exists')
            ->once()
            ->with('cc:my-lock')
            ->andReturn(0);

        $this->assertFalse($this->store->hasLock('my-lock'));
    }

    // =========================================================================
    // Chat history operations
    // =========================================================================

    public function testAppendChatHistory(): void
    {
        $message = ['role' => 'user', 'content' => 'hello'];

        $this->redis->shouldReceive('rPush')
            ->once()
            ->with('cc:chat_history:conv-1', json_encode($message));

        $this->store->appendChatHistory('conv-1', $message);
    }

    public function testGetChatHistory(): void
    {
        $this->redis->shouldReceive('lLen')
            ->once()
            ->with('cc:chat_history:conv-1')
            ->andReturn(3);

        $this->redis->shouldReceive('lRange')
            ->once()
            ->with('cc:chat_history:conv-1', 0, -1)
            ->andReturn([
                json_encode(['role' => 'user', 'content' => 'hello']),
                json_encode(['role' => 'assistant', 'content' => 'hi']),
                json_encode(['role' => 'user', 'content' => 'bye']),
            ]);

        $result = $this->store->getChatHistory('conv-1', 50);

        $this->assertCount(3, $result);
        $this->assertSame('user', $result[0]['role']);
        $this->assertSame('hello', $result[0]['content']);
    }

    public function testGetChatHistoryWithLimit(): void
    {
        $this->redis->shouldReceive('lLen')
            ->once()
            ->with('cc:chat_history:conv-1')
            ->andReturn(5);

        $this->redis->shouldReceive('lRange')
            ->once()
            ->with('cc:chat_history:conv-1', 3, -1)
            ->andReturn([
                json_encode(['role' => 'user', 'content' => 'msg4']),
                json_encode(['role' => 'assistant', 'content' => 'msg5']),
            ]);

        $result = $this->store->getChatHistory('conv-1', 2);

        $this->assertCount(2, $result);
    }

    public function testGetChatHistoryReturnsEmptyWhenNoData(): void
    {
        $this->redis->shouldReceive('lLen')
            ->once()
            ->with('cc:chat_history:conv-1')
            ->andReturn(0);

        $this->redis->shouldReceive('lRange')
            ->once()
            ->andReturn(false);

        $result = $this->store->getChatHistory('conv-1');

        $this->assertSame([], $result);
    }

    public function testTrimChatHistory(): void
    {
        $this->redis->shouldReceive('lLen')
            ->once()
            ->with('cc:chat_history:conv-1')
            ->andReturn(100);

        $this->redis->shouldReceive('lTrim')
            ->once()
            ->with('cc:chat_history:conv-1', 80, -1);

        $this->store->trimChatHistory('conv-1', 20);
    }

    public function testTrimChatHistoryNoOpWhenUnderLimit(): void
    {
        $this->redis->shouldReceive('lLen')
            ->once()
            ->with('cc:chat_history:conv-1')
            ->andReturn(5);

        // lTrim should NOT be called when total <= keep
        $this->redis->shouldNotReceive('lTrim');

        $this->store->trimChatHistory('conv-1', 10);
    }

    public function testDeleteChatHistory(): void
    {
        $this->redis->shouldReceive('del')
            ->once()
            ->with('cc:chat_history:conv-1');

        $this->store->deleteChatHistory('conv-1');
    }

    // =========================================================================
    // Active project state
    // =========================================================================

    public function testGetActiveProjectIdReturnsId(): void
    {
        $this->redis->shouldReceive('get')
            ->once()
            ->with('cc:project:active')
            ->andReturn('proj-1');

        $this->assertSame('proj-1', $this->store->getActiveProjectId());
    }

    public function testGetActiveProjectIdReturnsNullWhenEmpty(): void
    {
        $this->redis->shouldReceive('get')
            ->once()
            ->with('cc:project:active')
            ->andReturn(false);

        $this->assertNull($this->store->getActiveProjectId());
    }

    public function testSetActiveProject(): void
    {
        $this->redis->shouldReceive('set')
            ->once()
            ->with('cc:project:active', 'proj-1');

        $this->store->setActiveProject('proj-1');
    }

    public function testClearActiveProject(): void
    {
        $this->redis->shouldReceive('del')
            ->once()
            ->with('cc:project:active');

        $this->store->clearActiveProject();
    }

    // =========================================================================
    // Health check
    // =========================================================================

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
