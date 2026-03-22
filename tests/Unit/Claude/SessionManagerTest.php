<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Claude\SessionManager;
use App\Storage\PostgresStore;
use App\Storage\SwooleTableCache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

class SessionManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private SessionManager $manager;
    private PostgresStore|Mockery\MockInterface $store;
    private SwooleTableCache|Mockery\MockInterface $cache;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(PostgresStore::class);
        $this->cache = Mockery::mock(SwooleTableCache::class);

        $this->manager = new SessionManager();
        $this->setProperty($this->manager, 'store', $this->store);
        $this->setProperty($this->manager, 'cache', $this->cache);
    }

    public function testCreateSessionReturnsUuid(): void
    {
        $this->store->shouldReceive('createSession')->once();
        $this->cache->shouldReceive('setActiveSession')->once();

        $sessionId = $this->manager->createSession();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $sessionId
        );
    }

    public function testCreateSessionStoresCorrectData(): void
    {
        $this->store->shouldReceive('createSession')
            ->once()
            ->withArgs(function (string $id, array $data) {
                return $data['id'] === $id
                    && $data['claude_session_id'] === ''
                    && $data['state'] === 'active'
                    && $data['last_task_id'] === ''
                    && isset($data['created_at'])
                    && isset($data['updated_at']);
            });
        $this->cache->shouldReceive('setActiveSession')->once();

        $this->manager->createSession();
    }

    public function testGetSession(): void
    {
        $this->store->shouldReceive('getSession')
            ->with('sess-1')
            ->once()
            ->andReturn(['id' => 'sess-1', 'state' => 'active']);

        $result = $this->manager->getSession('sess-1');

        $this->assertSame('active', $result['state']);
    }

    public function testGetSessionReturnsNull(): void
    {
        $this->store->shouldReceive('getSession')
            ->with('missing')
            ->once()
            ->andReturn(null);

        $this->assertNull($this->manager->getSession('missing'));
    }

    public function testUpdateSession(): void
    {
        $this->store->shouldReceive('updateSession')
            ->once()
            ->withArgs(function (string $id, array $data) {
                return $id === 'sess-1'
                    && $data['last_task_id'] === 'task-1'
                    && isset($data['updated_at']);
            });
        $this->cache->shouldReceive('updateSessionActivity')
            ->once()
            ->with('sess-1', 'task-1');

        $this->manager->updateSession('sess-1', ['last_task_id' => 'task-1']);
    }

    public function testUpdateSessionWithoutTaskId(): void
    {
        $this->store->shouldReceive('updateSession')->once();
        $this->cache->shouldReceive('updateSessionActivity')
            ->once()
            ->with('sess-1', '');

        $this->manager->updateSession('sess-1', ['some_field' => 'value']);
    }

    public function testCloseSession(): void
    {
        $this->store->shouldReceive('updateSession')
            ->once()
            ->withArgs(function (string $id, array $data) {
                return $id === 'sess-1' && $data['state'] === 'closed';
            });
        $this->cache->shouldReceive('removeActiveSession')
            ->once()
            ->with('sess-1');

        $this->manager->closeSession('sess-1');
    }

    public function testListSessions(): void
    {
        $sessions = [['id' => 's1'], ['id' => 's2']];
        $this->store->shouldReceive('listSessions')
            ->once()
            ->andReturn($sessions);

        $result = $this->manager->listSessions();

        $this->assertCount(2, $result);
    }

    public function testArchiveSession(): void
    {
        $this->store->shouldReceive('updateSession')
            ->once()
            ->withArgs(function (string $id, array $data) {
                return $id === 'sess-1' && $data['state'] === 'archived';
            });
        $this->cache->shouldReceive('removeActiveSession')
            ->once()
            ->with('sess-1');

        $this->manager->archiveSession('sess-1');
    }
}
