<?php

declare(strict_types=1);

namespace Tests\Unit\Skills;

use App\Skills\BuiltinSkills;
use App\Skills\SkillRegistry;
use App\Storage\PostgresStore;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Helpers\ReflectionHelper;

/**
 * Tests for SkillRegistry.
 *
 * Covers: global skill registration, listing skills by scope (global and builtin),
 * skill removal, and merging all scopes (builtin + global + user) for a given user.
 */
class SkillRegistryTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private SkillRegistry $registry;

    private PostgresStore|Mockery\MockInterface $store;

    private BuiltinSkills|Mockery\MockInterface $builtinSkills;

    private LoggerInterface|Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(PostgresStore::class);
        $this->builtinSkills = Mockery::mock(BuiltinSkills::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->registry = new SkillRegistry();
        $this->setProperty($this->registry, 'store', $this->store);
        $this->setProperty($this->registry, 'builtinSkills', $this->builtinSkills);
        $this->setProperty($this->registry, 'logger', $this->logger);
    }

    public function testRegisterGlobal(): void
    {
        $config = ['command' => 'npx', 'args' => ['-y', 'some-server']];

        $this->store->shouldReceive('setSkill')
            ->once()
            ->with('global', 'test-skill', $config);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Skill registered globally: test-skill');

        $this->registry->registerGlobal('test-skill', $config);
    }

    public function testListSkillsWithGlobalScope(): void
    {
        $storeSkills = [
            'custom-skill' => ['command' => 'node', 'args' => ['server.js']],
        ];

        $this->store->shouldReceive('getAllSkills')
            ->once()
            ->with('global')
            ->andReturn($storeSkills);

        $result = $this->registry->listSkills('global');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('custom-skill', $result);
    }

    public function testListSkillsWithBuiltinScope(): void
    {
        $builtinList = [
            'filesystem' => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/tmp']],
            'fetch' => ['command' => 'npx', 'args' => ['-y', 'mcp-server-fetch']],
        ];

        $this->builtinSkills->shouldReceive('getAll')
            ->once()
            ->andReturn($builtinList);

        $result = $this->registry->listSkills('builtin');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('filesystem', $result);
        $this->assertArrayHasKey('fetch', $result);
    }

    public function testRemoveSkill(): void
    {
        $this->store->shouldReceive('deleteSkill')
            ->once()
            ->with('global', 'old-skill');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Skill removed from global: old-skill');

        $this->registry->removeSkill('global', 'old-skill');
    }

    public function testGetSkillsForUserMergesAllScopes(): void
    {
        $this->builtinSkills->shouldReceive('getAll')
            ->once()
            ->andReturn(['filesystem' => ['command' => 'npx']]);

        $this->store->shouldReceive('getAllSkills')
            ->with('global')
            ->once()
            ->andReturn(['global-skill' => ['command' => 'node']]);

        $this->store->shouldReceive('getAllSkills')
            ->with('user-1')
            ->once()
            ->andReturn(['user-skill' => ['command' => 'python']]);

        $result = $this->registry->getSkillsForUser('user-1');

        $this->assertCount(3, $result);
        $this->assertArrayHasKey('filesystem', $result);
        $this->assertArrayHasKey('global-skill', $result);
        $this->assertArrayHasKey('user-skill', $result);
    }
}
