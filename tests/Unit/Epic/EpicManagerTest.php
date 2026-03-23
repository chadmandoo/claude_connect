<?php

declare(strict_types=1);

namespace Tests\Unit\Epic;

use App\Epic\EpicManager;
use App\Epic\EpicState;
use App\Storage\PostgresStore;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

/**
 * Tests for EpicManager.
 *
 * Covers: epic creation with UUID and project association, retrieval (found and not found),
 * listing epics by project, and deleting non-backlog epics with item migration.
 */
class EpicManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private EpicManager $manager;

    private PostgresStore|Mockery\MockInterface $store;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(PostgresStore::class);
        $this->manager = new EpicManager();
        $this->setProperty($this->manager, 'store', $this->store);
    }

    public function testCreateEpicReturnsUuid(): void
    {
        $this->store->shouldReceive('listProjectEpics')
            ->once()
            ->with('proj-1')
            ->andReturn([]);

        $this->store->shouldReceive('createEpic')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'));

        $this->store->shouldReceive('addEpicToProject')
            ->once()
            ->with('proj-1', Mockery::type('string'), 1.0);

        $result = $this->manager->createEpic('proj-1', 'My Epic', 'A description');

        $this->assertIsString($result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result,
        );
    }

    public function testGetEpicReturnsArray(): void
    {
        $expected = [
            'id' => 'epic-1',
            'project_id' => 'proj-1',
            'title' => 'My Epic',
            'state' => EpicState::OPEN->value,
        ];

        $this->store->shouldReceive('getEpic')
            ->once()
            ->with('epic-1')
            ->andReturn($expected);

        $result = $this->manager->getEpic('epic-1');

        $this->assertSame($expected, $result);
    }

    public function testGetEpicReturnsNull(): void
    {
        $this->store->shouldReceive('getEpic')
            ->once()
            ->with('epic-missing')
            ->andReturn(null);

        $result = $this->manager->getEpic('epic-missing');

        $this->assertNull($result);
    }

    public function testListEpics(): void
    {
        $expected = [
            ['id' => 'epic-1', 'title' => 'Epic One'],
            ['id' => 'epic-2', 'title' => 'Epic Two'],
        ];

        $this->store->shouldReceive('listProjectEpics')
            ->once()
            ->with('proj-1')
            ->andReturn($expected);

        $result = $this->manager->listEpics('proj-1');

        $this->assertCount(2, $result);
        $this->assertSame($expected, $result);
    }

    public function testDeleteEpic(): void
    {
        $this->store->shouldReceive('getEpic')
            ->with('epic-1')
            ->andReturn([
                'id' => 'epic-1',
                'project_id' => 'proj-1',
                'is_backlog' => '0',
                'state' => EpicState::OPEN->value,
            ]);

        // ensureBacklogEpic flow
        $this->store->shouldReceive('getProjectBacklogEpic')
            ->once()
            ->with('proj-1')
            ->andReturn('backlog-epic-1');

        $this->store->shouldReceive('getEpic')
            ->with('backlog-epic-1')
            ->andReturn(['id' => 'backlog-epic-1', 'is_backlog' => '1']);

        $this->store->shouldReceive('listEpicItems')
            ->once()
            ->with('epic-1')
            ->andReturn([]);

        $this->store->shouldReceive('deleteEpic')
            ->once()
            ->with('epic-1');

        $this->manager->deleteEpic('epic-1', 'proj-1');
    }
}
