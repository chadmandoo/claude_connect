<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentManager;
use App\Storage\PostgresStore;
use App\Prompts\PromptLoader;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Helpers\ReflectionHelper;

class AgentManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private AgentManager $manager;
    private PostgresStore|Mockery\MockInterface $store;
    private PromptLoader|Mockery\MockInterface $promptLoader;
    private LoggerInterface|Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(PostgresStore::class);
        $this->promptLoader = Mockery::mock(PromptLoader::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->manager = new AgentManager();
        $this->setProperty($this->manager, 'store', $this->store);
        $this->setProperty($this->manager, 'promptLoader', $this->promptLoader);
        $this->setProperty($this->manager, 'logger', $this->logger);
    }

    public function testCreateAgentReturnsUuid(): void
    {
        $this->store->shouldReceive('createAgent')
            ->once()
            ->withArgs(function (string $id, array $data) {
                return $data['slug'] === 'test-agent'
                    && $data['name'] === 'Test Agent';
            });

        $this->logger->shouldReceive('info')
            ->once()
            ->with(Mockery::pattern('/^Agent created: test-agent/'));

        $id = $this->manager->createAgent([
            'slug' => 'test-agent',
            'name' => 'Test Agent',
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    public function testGetAgentReturnsData(): void
    {
        $agentData = [
            'id' => 'agent-123',
            'slug' => 'helper',
            'name' => 'Helper Agent',
        ];

        $this->store->shouldReceive('getAgent')
            ->once()
            ->with('agent-123')
            ->andReturn($agentData);

        $result = $this->manager->getAgent('agent-123');

        $this->assertSame($agentData, $result);
        $this->assertSame('helper', $result['slug']);
        $this->assertSame('Helper Agent', $result['name']);
    }

    public function testGetAgentReturnsNull(): void
    {
        $this->store->shouldReceive('getAgent')
            ->once()
            ->with('nonexistent')
            ->andReturn(null);

        $this->assertNull($this->manager->getAgent('nonexistent'));
    }

    public function testGetAgentBySlug(): void
    {
        $agentData = [
            'id' => 'agent-456',
            'slug' => 'pm',
            'name' => 'PM',
        ];

        $this->store->shouldReceive('getAgentBySlug')
            ->once()
            ->with('pm')
            ->andReturn($agentData);

        $result = $this->manager->getAgentBySlug('pm');

        $this->assertSame($agentData, $result);
        $this->assertSame('pm', $result['slug']);
    }

    public function testListAgents(): void
    {
        $agents = [
            ['id' => 'a1', 'slug' => 'pm', 'name' => 'PM'],
            ['id' => 'a2', 'slug' => 'general', 'name' => 'General'],
        ];

        $this->store->shouldReceive('listAgents')
            ->once()
            ->with(null)
            ->andReturn($agents);

        $result = $this->manager->listAgents();

        $this->assertCount(2, $result);
        $this->assertSame('pm', $result[0]['slug']);
        $this->assertSame('general', $result[1]['slug']);
    }

    public function testDeleteAgent(): void
    {
        $agentData = [
            'id' => 'agent-789',
            'slug' => 'custom-agent',
            'name' => 'Custom',
            'is_system' => '0',
        ];

        $this->store->shouldReceive('getAgent')
            ->once()
            ->with('agent-789')
            ->andReturn($agentData);

        $this->store->shouldReceive('deleteAgent')
            ->once()
            ->with('agent-789');

        $this->logger->shouldReceive('info')
            ->once()
            ->with(Mockery::pattern('/^Agent deleted: custom-agent/'));

        $this->manager->deleteAgent('agent-789');
    }

    public function testDeleteAgentThrowsWhenSystemAgent(): void
    {
        $agentData = [
            'id' => 'agent-sys',
            'slug' => 'pm',
            'name' => 'PM',
            'is_system' => '1',
        ];

        $this->store->shouldReceive('getAgent')
            ->once()
            ->with('agent-sys')
            ->andReturn($agentData);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete system agent');

        $this->manager->deleteAgent('agent-sys');
    }
}
