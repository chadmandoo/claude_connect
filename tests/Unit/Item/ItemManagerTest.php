<?php

declare(strict_types=1);

namespace Tests\Unit\Item;

use App\Epic\EpicManager;
use App\Item\ItemManager;
use App\Item\ItemState;
use App\Storage\PostgresStore;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

class ItemManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private ItemManager $manager;
    private PostgresStore|Mockery\MockInterface $store;
    private EpicManager|Mockery\MockInterface $epicManager;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(PostgresStore::class);
        $this->epicManager = Mockery::mock(EpicManager::class);
        $this->manager = new ItemManager();
        $this->setProperty($this->manager, 'store', $this->store);
        $this->setProperty($this->manager, 'epicManager', $this->epicManager);
    }

    public function testCreateItemReturnsUuid(): void
    {
        $this->epicManager->shouldReceive('ensureBacklogEpic')
            ->once()
            ->with('proj-1')
            ->andReturn('backlog-epic-1');

        $this->store->shouldReceive('listEpicItems')
            ->once()
            ->with('backlog-epic-1')
            ->andReturn([]);

        $this->store->shouldReceive('createItem')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'));

        $this->store->shouldReceive('addItemToEpic')
            ->once()
            ->with('backlog-epic-1', Mockery::type('string'), 1.0);

        $this->store->shouldReceive('addItemToProject')
            ->once()
            ->with('proj-1', Mockery::type('string'));

        $result = $this->manager->createItem('proj-1', 'My Item');

        $this->assertIsString($result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result
        );
    }

    public function testGetItemReturnsArray(): void
    {
        $expected = [
            'id' => 'item-1',
            'title' => 'My Item',
            'state' => ItemState::OPEN->value,
        ];

        $this->store->shouldReceive('getItem')
            ->once()
            ->with('item-1')
            ->andReturn($expected);

        $result = $this->manager->getItem('item-1');

        $this->assertSame($expected, $result);
    }

    public function testGetItemReturnsNull(): void
    {
        $this->store->shouldReceive('getItem')
            ->once()
            ->with('item-missing')
            ->andReturn(null);

        $result = $this->manager->getItem('item-missing');

        $this->assertNull($result);
    }

    public function testListItemsByProject(): void
    {
        $expected = [
            ['id' => 'item-1', 'title' => 'Item One', 'state' => 'open'],
            ['id' => 'item-2', 'title' => 'Item Two', 'state' => 'done'],
        ];

        $this->store->shouldReceive('listProjectItems')
            ->once()
            ->with('proj-1', null)
            ->andReturn($expected);

        $result = $this->manager->listItemsByProject('proj-1');

        $this->assertCount(2, $result);
        $this->assertSame($expected, $result);
    }

    public function testAssignItem(): void
    {
        $this->store->shouldReceive('getItem')
            ->once()
            ->with('item-1')
            ->andReturn(['id' => 'item-1', 'title' => 'My Item']);

        $this->store->shouldReceive('updateItem')
            ->once()
            ->with('item-1', Mockery::on(function (array $data) {
                return $data['assigned_to'] === 'agent-1'
                    && isset($data['updated_at']);
            }));

        $this->manager->assignItem('item-1', 'agent-1');
    }

    public function testGetProjectItemCounts(): void
    {
        $items = [
            ['id' => 'item-1', 'state' => 'open'],
            ['id' => 'item-2', 'state' => 'open'],
            ['id' => 'item-3', 'state' => 'in_progress'],
            ['id' => 'item-4', 'state' => 'done'],
        ];

        $this->store->shouldReceive('listProjectItems')
            ->once()
            ->with('proj-1')
            ->andReturn($items);

        $result = $this->manager->getProjectItemCounts('proj-1');

        $this->assertSame(2, $result['open']);
        $this->assertSame(1, $result['in_progress']);
        $this->assertSame(1, $result['done']);
        $this->assertSame(0, $result['blocked']);
        $this->assertSame(0, $result['cancelled']);
        $this->assertSame(4, $result['total']);
    }
}
