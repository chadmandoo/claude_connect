<?php

declare(strict_types=1);

namespace Tests\Unit\Project;

use App\Project\ProjectManager;
use App\Project\ProjectState;
use App\Storage\PostgresStore;
use App\Storage\RedisStore;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

/**
 * Tests for ProjectManager.
 *
 * Covers: project creation with UUID and history tracking, retrieval (found and not found),
 * listing projects, workspace creation with named lookup, and ensureGeneralProject
 * (existing vs. auto-creation).
 */
class ProjectManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private ProjectManager $manager;

    private PostgresStore|Mockery\MockInterface $store;

    private RedisStore|Mockery\MockInterface $redis;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(PostgresStore::class);
        $this->redis = Mockery::mock(RedisStore::class);
        $this->manager = new ProjectManager();
        $this->setProperty($this->manager, 'store', $this->store);
        $this->setProperty($this->manager, 'redis', $this->redis);
    }

    public function testCreateProjectReturnsUuid(): void
    {
        $this->store->shouldReceive('createProject')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'));

        $this->store->shouldReceive('addProjectHistory')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'));

        $result = $this->manager->createProject('Build a feature', 'user-1');

        $this->assertIsString($result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result,
        );
    }

    public function testGetProjectReturnsArray(): void
    {
        $expected = [
            'id' => 'proj-1',
            'goal' => 'Build a feature',
            'state' => ProjectState::PLANNING->value,
        ];

        $this->store->shouldReceive('getProject')
            ->once()
            ->with('proj-1')
            ->andReturn($expected);

        $result = $this->manager->getProject('proj-1');

        $this->assertSame($expected, $result);
    }

    public function testGetProjectReturnsNull(): void
    {
        $this->store->shouldReceive('getProject')
            ->once()
            ->with('proj-missing')
            ->andReturn(null);

        $result = $this->manager->getProject('proj-missing');

        $this->assertNull($result);
    }

    public function testListProjects(): void
    {
        $expected = [
            ['id' => 'proj-1', 'goal' => 'Feature A', 'state' => 'planning'],
            ['id' => 'proj-2', 'goal' => 'Feature B', 'state' => 'active'],
        ];

        $this->store->shouldReceive('listProjects')
            ->once()
            ->with(null, 20)
            ->andReturn($expected);

        $result = $this->manager->listProjects();

        $this->assertCount(2, $result);
        $this->assertSame($expected, $result);
    }

    public function testCreateWorkspace(): void
    {
        $this->store->shouldReceive('createProject')
            ->once()
            ->with(Mockery::type('string'), Mockery::on(function (array $data) {
                return $data['name'] === 'My Workspace'
                    && $data['description'] === 'A workspace'
                    && $data['state'] === ProjectState::WORKSPACE->value;
            }));

        $this->store->shouldReceive('setProjectName')
            ->once()
            ->with('My Workspace', Mockery::type('string'));

        $result = $this->manager->createWorkspace('My Workspace', 'A workspace', 'user-1');

        $this->assertIsString($result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result,
        );
    }

    public function testEnsureGeneralProjectWhenExists(): void
    {
        $this->store->shouldReceive('getProjectByName')
            ->once()
            ->with('General')
            ->andReturn('existing-proj-id');

        $result = $this->manager->ensureGeneralProject('user-1');

        $this->assertSame('existing-proj-id', $result);
    }

    public function testEnsureGeneralProjectWhenNotExists(): void
    {
        $this->store->shouldReceive('getProjectByName')
            ->once()
            ->with('General')
            ->andReturn(null);

        // createWorkspace internally calls createProject and setProjectName
        $this->store->shouldReceive('createProject')
            ->once()
            ->with(Mockery::type('string'), Mockery::on(function (array $data) {
                return $data['name'] === 'General'
                    && $data['state'] === ProjectState::WORKSPACE->value;
            }));

        $this->store->shouldReceive('setProjectName')
            ->once()
            ->with('General', Mockery::type('string'));

        $result = $this->manager->ensureGeneralProject('user-1');

        $this->assertIsString($result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result,
        );
    }
}
