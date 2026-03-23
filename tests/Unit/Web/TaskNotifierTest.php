<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Scheduler\SystemChannel;
use App\Storage\PostgresStore;
use App\Storage\SwooleTableCache;
use App\Web\TaskNotifier;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\WebSocket\Server;
use Tests\Helpers\ReflectionHelper;

/**
 * Tests for TaskNotifier.
 *
 * Covers: WebSocket server assignment, state change notifications (system channel posting,
 * duplicate prevention, non-user-facing source filtering, user-targeted broadcasting),
 * task result delivery (with images, conversation ID filtering), progress broadcasting,
 * graceful handling of push exceptions and unestablished connections, and broadcastToUser.
 */
class TaskNotifierTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private TaskNotifier $notifier;

    private SwooleTableCache|Mockery\MockInterface $cache;

    private LoggerInterface|Mockery\MockInterface $logger;

    private SystemChannel|Mockery\MockInterface $systemChannel;

    private PostgresStore|Mockery\MockInterface $store;

    protected function setUp(): void
    {
        $this->cache = Mockery::mock(SwooleTableCache::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('debug')->byDefault();
        $this->systemChannel = Mockery::mock(SystemChannel::class);
        $this->store = Mockery::mock(PostgresStore::class);

        $this->notifier = new TaskNotifier();
        $this->setProperty($this->notifier, 'cache', $this->cache);
        $this->setProperty($this->notifier, 'logger', $this->logger);
        $this->setProperty($this->notifier, 'systemChannel', $this->systemChannel);
        $this->setProperty($this->notifier, 'store', $this->store);
    }

    public function testSetServer(): void
    {
        $server = Mockery::mock(Server::class);

        $this->notifier->setServer($server);

        $this->assertSame($server, $this->getProperty($this->notifier, 'server'));
    }

    public function testNotifyStateChangePostsToSystemChannel(): void
    {
        $task = [
            'prompt' => 'Do something important',
            'options' => json_encode(['source' => 'web', 'conversation_id' => 'conv-1']),
        ];

        $this->systemChannel->shouldReceive('postTaskUpdate')
            ->once()
            ->with('task-1', 'completed', Mockery::type('string'));

        $this->store->shouldReceive('markNotified')
            ->once()
            ->with('task-1')
            ->andReturn(true);

        // No server set, so broadcast is a no-op
        $this->notifier->notifyStateChange('task-1', 'completed', $task);
    }

    /**
     * @dataProvider nonUserFacingSourceProvider
     */
    public function testNotifyStateChangeSkipsNonUserFacingSources(string $source): void
    {
        $task = [
            'prompt' => 'Internal task',
            'options' => json_encode(['source' => $source]),
        ];

        $this->systemChannel->shouldReceive('postTaskUpdate')
            ->once()
            ->with('task-' . $source, 'completed', Mockery::type('string'));

        // markNotified should NOT be called for non-user-facing sources
        $this->store->shouldNotReceive('markNotified');

        $this->notifier->notifyStateChange('task-' . $source, 'completed', $task);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonUserFacingSourceProvider(): array
    {
        return [
            'routing' => ['routing'],
            'extraction' => ['extraction'],
            'cleanup' => ['cleanup'],
            'nightly' => ['nightly'],
            'item_agent' => ['item_agent'],
            'manager' => ['manager'],
        ];
    }

    public function testNotifyStateChangeSkipsDuplicateNotification(): void
    {
        $task = [
            'prompt' => 'Do something',
            'options' => json_encode(['source' => 'web']),
        ];

        $this->systemChannel->shouldReceive('postTaskUpdate')->once();

        // markNotified returns false => already notified
        $this->store->shouldReceive('markNotified')
            ->once()
            ->with('task-1')
            ->andReturn(false);

        // Should not attempt to broadcast
        $this->notifier->notifyStateChange('task-1', 'completed', $task);
    }

    public function testNotifyStateChangeBroadcastsToServer(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $task = [
            'prompt' => 'Hello world',
            'result' => 'Response text here',
            'options' => json_encode([
                'source' => 'web',
                'conversation_id' => 'conv-1',
                'web_user_id' => 'user-1',
            ]),
        ];

        $this->systemChannel->shouldReceive('postTaskUpdate')->once();
        $this->store->shouldReceive('markNotified')->once()->andReturn(true);

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([
                1 => ['user_id' => 'user-1'],
                2 => ['user_id' => 'user-2'],
            ]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, Mockery::type('string'))
            ->once();

        // fd=2 belongs to user-2, should be skipped (filtered by web_user_id)
        $server->shouldNotReceive('isEstablished')->with(2);

        $this->notifier->notifyStateChange('task-1', 'completed', $task);
    }

    public function testNotifyStateChangeIncludesResultPreviewOnCompleted(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $task = [
            'prompt' => 'Hello',
            'result' => 'This is the result',
            'options' => json_encode(['source' => 'web', 'web_user_id' => 'user-1']),
        ];

        $this->systemChannel->shouldReceive('postTaskUpdate')->once();
        $this->store->shouldReceive('markNotified')->once()->andReturn(true);

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([1 => ['user_id' => 'user-1']]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, Mockery::on(function (string $json) {
                $data = json_decode($json, true);

                return $data['type'] === 'task.state_changed'
                    && $data['state'] === 'completed'
                    && isset($data['result_preview']);
            }))
            ->once();

        $this->notifier->notifyStateChange('task-1', 'completed', $task);
    }

    public function testNotifyStateChangeIncludesErrorOnFailed(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $task = [
            'prompt' => 'Hello',
            'error' => 'Something went wrong',
            'options' => json_encode(['source' => 'web', 'web_user_id' => 'user-1']),
        ];

        $this->systemChannel->shouldReceive('postTaskUpdate')->once();
        $this->store->shouldReceive('markNotified')->once()->andReturn(true);

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([1 => ['user_id' => 'user-1']]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, Mockery::on(function (string $json) {
                $data = json_decode($json, true);

                return $data['type'] === 'task.state_changed'
                    && $data['state'] === 'failed'
                    && $data['error'] === 'Something went wrong';
            }))
            ->once();

        $this->notifier->notifyStateChange('task-1', 'failed', $task);
    }

    public function testNotifyTaskResultBroadcastsChatResult(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $task = [
            'result' => 'Hello world response',
            'claude_session_id' => 'claude-sess-1',
            'cost_usd' => '0.05',
            'options' => json_encode([
                'conversation_id' => 'conv-1',
                'web_user_id' => 'user-1',
            ]),
        ];

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([1 => ['user_id' => 'user-1']]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, Mockery::on(function (string $json) {
                $data = json_decode($json, true);

                return $data['type'] === 'chat.result'
                    && $data['conversation_id'] === 'conv-1'
                    && $data['result'] === 'Hello world response'
                    && $data['claude_session_id'] === 'claude-sess-1';
            }))
            ->once();

        $this->notifier->notifyTaskResult('task-1', $task, 5);
    }

    public function testNotifyTaskResultSkipsWhenNoConversationId(): void
    {
        $task = [
            'result' => 'Some result',
            'options' => json_encode([]),
        ];

        // broadcast should not be called (no getWsConnections call)
        $this->cache->shouldNotReceive('getWsConnections');

        $this->notifier->notifyTaskResult('task-1', $task);
    }

    public function testNotifyTaskResultSkipsWhenNoResult(): void
    {
        $task = [
            'result' => '',
            'options' => json_encode(['conversation_id' => 'conv-1']),
        ];

        $this->cache->shouldNotReceive('getWsConnections');

        $this->notifier->notifyTaskResult('task-1', $task);
    }

    public function testNotifyProgressBroadcasts(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([1 => ['user_id' => 'user-1']]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, Mockery::on(function (string $json) {
                $data = json_decode($json, true);

                return $data['type'] === 'task.progress'
                    && $data['task_id'] === 'task-1'
                    && $data['elapsed'] === 10
                    && $data['stderr_lines'] === 5;
            }))
            ->once();

        $this->notifier->notifyProgress('task-1', 10, 5);
    }

    public function testBroadcastWithNoServerIsNoOp(): void
    {
        // Server is null (not set)
        $this->cache->shouldNotReceive('getWsConnections');

        $this->notifier->notifyProgress('task-1', 10, 5);

        $this->assertTrue(true);
    }

    public function testBroadcastHandlesPushException(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([
                1 => ['user_id' => 'user-1'],
                2 => ['user_id' => 'user-2'],
            ]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, Mockery::type('string'))
            ->once()
            ->andThrow(new RuntimeException('Connection lost'));

        $server->shouldReceive('isEstablished')->with(2)->once()->andReturn(true);
        $server->shouldReceive('push')
            ->with(2, Mockery::type('string'))
            ->once();

        $this->logger->shouldReceive('debug')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'push failed') && str_contains($msg, 'fd=1'));

        $this->notifier->notifyProgress('task-1', 10, 5);
    }

    public function testBroadcastSkipsUnestablishedConnections(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([1 => ['user_id' => 'user-1']]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(false);
        $server->shouldNotReceive('push');

        $this->notifier->notifyProgress('task-1', 10, 5);
    }

    public function testBroadcastToUserDelegates(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([
                1 => ['user_id' => 'user-1'],
                2 => ['user_id' => 'user-2'],
            ]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')->with(1, Mockery::type('string'))->once();

        // fd=2 is for a different user, should not be pushed to
        $server->shouldNotReceive('isEstablished')->with(2);

        $this->notifier->broadcastToUser(['type' => 'custom.event'], 'user-1');
    }

    public function testBroadcastToUserWithEmptyUserIdBroadcastsToAll(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([
                1 => ['user_id' => 'user-1'],
                2 => ['user_id' => 'user-2'],
            ]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')->with(1, Mockery::type('string'))->once();
        $server->shouldReceive('isEstablished')->with(2)->once()->andReturn(true);
        $server->shouldReceive('push')->with(2, Mockery::type('string'))->once();

        $this->notifier->broadcastToUser(['type' => 'custom.event'], '');
    }

    public function testNotifyTaskResultIncludesImages(): void
    {
        $server = Mockery::mock(Server::class);
        $this->notifier->setServer($server);

        $images = ['/path/to/image1.png', '/path/to/image2.png'];
        $task = [
            'result' => 'Here are some images',
            'images' => json_encode($images),
            'cost_usd' => '0.03',
            'options' => json_encode([
                'conversation_id' => 'conv-1',
                'web_user_id' => 'user-1',
            ]),
        ];

        $this->cache->shouldReceive('getWsConnections')
            ->once()
            ->andReturn([1 => ['user_id' => 'user-1']]);

        $server->shouldReceive('isEstablished')->with(1)->once()->andReturn(true);
        $server->shouldReceive('push')
            ->with(1, Mockery::on(function (string $json) use ($images) {
                $data = json_decode($json, true);

                return $data['type'] === 'chat.result'
                    && $data['images'] === $images;
            }))
            ->once();

        $this->notifier->notifyTaskResult('task-1', $task, 3);
    }
}
