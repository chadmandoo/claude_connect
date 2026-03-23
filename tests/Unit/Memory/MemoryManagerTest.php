<?php

declare(strict_types=1);

namespace Tests\Unit\Memory;

use App\Embedding\EmbeddingService;
use App\Embedding\VectorStore;
use App\Memory\MemoryManager;
use App\Storage\PostgresStore;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Helpers\ReflectionHelper;

/**
 * Tests for MemoryManager.
 *
 * Covers: storing/retrieving/deleting structured and project-scoped memories,
 * key-value fact operations (remember, forget, getFacts), conversation logging,
 * memory counts, updates with optional project scoping, and vector store cleanup.
 */
class MemoryManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private MemoryManager $manager;

    private PostgresStore|Mockery\MockInterface $store;

    private EmbeddingService|Mockery\MockInterface $embeddingService;

    private VectorStore|Mockery\MockInterface $vectorStore;

    private LoggerInterface|Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(PostgresStore::class);
        $this->embeddingService = Mockery::mock(EmbeddingService::class);
        $this->vectorStore = Mockery::mock(VectorStore::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();
        $this->logger->shouldReceive('debug')->byDefault();

        $this->manager = new MemoryManager();
        $this->setProperty($this->manager, 'store', $this->store);
        $this->setProperty($this->manager, 'embeddingService', $this->embeddingService);
        $this->setProperty($this->manager, 'vectorStore', $this->vectorStore);
        $this->setProperty($this->manager, 'logger', $this->logger);
    }

    public function testStoreMemoryReturnsId(): void
    {
        $this->store->shouldReceive('addMemoryEntry')
            ->once()
            ->withArgs(function (string $userId, array $entry) {
                return $userId === 'user-1'
                    && $entry['category'] === 'preference'
                    && $entry['content'] === 'Likes dark mode'
                    && $entry['importance'] === 'normal'
                    && $entry['source'] === 'inline'
                    && $entry['type'] === 'project'
                    && str_starts_with($entry['id'], 'mem_');
            });

        $this->embeddingService->shouldReceive('isAvailable')->once()->andReturn(false);

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'stored'));

        $id = $this->manager->storeMemory('user-1', 'preference', 'Likes dark mode');

        $this->assertIsString($id);
        $this->assertStringStartsWith('mem_', $id);
    }

    public function testGetMemoryReturnsArray(): void
    {
        $expected = [
            'id' => 'mem_abc123',
            'category' => 'preference',
            'content' => 'Likes dark mode',
        ];

        $this->store->shouldReceive('getMemoryEntryById')
            ->once()
            ->with('user-1', 'mem_abc123')
            ->andReturn($expected);

        $result = $this->manager->getMemory('user-1', 'mem_abc123');

        $this->assertSame($expected, $result);
    }

    public function testGetMemoryReturnsNull(): void
    {
        $this->store->shouldReceive('getMemoryEntryById')
            ->once()
            ->with('user-1', 'mem_nonexistent')
            ->andReturn(null);

        $result = $this->manager->getMemory('user-1', 'mem_nonexistent');

        $this->assertNull($result);
    }

    public function testGetStructuredMemories(): void
    {
        $memories = [
            ['id' => 'mem_1', 'content' => 'Memory 1'],
            ['id' => 'mem_2', 'content' => 'Memory 2'],
        ];

        $this->store->shouldReceive('getMemoryEntries')
            ->once()
            ->with('user-1', 50)
            ->andReturn($memories);

        $result = $this->manager->getStructuredMemories('user-1', 50);

        $this->assertCount(2, $result);
        $this->assertSame('mem_1', $result[0]['id']);
        $this->assertSame('mem_2', $result[1]['id']);
    }

    public function testGetAllMemories(): void
    {
        $memories = [
            ['id' => 'mem_1', 'content' => 'General memory'],
            ['id' => 'mem_2', 'content' => 'Project memory', 'project_id' => 'proj-1'],
        ];

        $this->store->shouldReceive('getAllMemoryEntries')
            ->once()
            ->with('user-1', 200)
            ->andReturn($memories);

        $result = $this->manager->getAllMemories('user-1');

        $this->assertCount(2, $result);
    }

    public function testDeleteStructuredMemory(): void
    {
        $this->store->shouldReceive('deleteMemoryEntry')
            ->once()
            ->with('user-1', 'mem_abc123');

        $this->vectorStore->shouldReceive('delete')
            ->once()
            ->with('mem_abc123');

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'deleted structured entry') && str_contains($msg, 'mem_abc123'));

        $this->manager->deleteStructuredMemory('user-1', 'mem_abc123');
    }

    public function testRemember(): void
    {
        $this->store->shouldReceive('setMemoryFact')
            ->once()
            ->with('user-1', 'favorite_color', 'blue');

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'set fact') && str_contains($msg, 'favorite_color'));

        $this->manager->remember('user-1', 'favorite_color', 'blue');
    }

    public function testForget(): void
    {
        $this->store->shouldReceive('deleteMemoryFact')
            ->once()
            ->with('user-1', 'favorite_color');

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'deleted fact') && str_contains($msg, 'favorite_color'));

        $this->manager->forget('user-1', 'favorite_color');
    }

    public function testGetFacts(): void
    {
        $facts = [
            'name' => 'Chad',
            'role' => 'Developer',
        ];

        $this->store->shouldReceive('getAllMemory')
            ->once()
            ->with('user-1')
            ->andReturn($facts);

        $result = $this->manager->getFacts('user-1');

        $this->assertSame($facts, $result);
        $this->assertSame('Chad', $result['name']);
        $this->assertSame('Developer', $result['role']);
    }

    public function testGetFactsReturnsEmptyArray(): void
    {
        $this->store->shouldReceive('getAllMemory')
            ->once()
            ->with('user-1')
            ->andReturn([]);

        $result = $this->manager->getFacts('user-1');

        $this->assertSame([], $result);
    }

    public function testLogConversation(): void
    {
        $this->store->shouldReceive('addMemoryLog')
            ->once()
            ->with('user-1', 'Discussed project architecture');

        $this->logger->shouldReceive('debug')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'logged conversation'));

        $this->manager->logConversation('user-1', 'Discussed project architecture');
    }

    public function testGetStructuredMemoryCount(): void
    {
        $this->store->shouldReceive('getMemoryEntryCount')
            ->once()
            ->with('user-1')
            ->andReturn(42);

        $result = $this->manager->getStructuredMemoryCount('user-1');

        $this->assertSame(42, $result);
    }

    public function testGetAllMemoryCount(): void
    {
        $this->store->shouldReceive('getAllMemoryEntryCount')
            ->once()
            ->with('user-1')
            ->andReturn(100);

        $result = $this->manager->getAllMemoryCount('user-1');

        $this->assertSame(100, $result);
    }

    public function testUpdateMemory(): void
    {
        $updates = ['content' => 'Updated content', 'importance' => 'high'];

        $this->store->shouldReceive('updateMemoryEntry')
            ->once()
            ->with('user-1', null, 'mem_abc123', $updates);

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'updated entry') && str_contains($msg, 'mem_abc123'));

        $this->manager->updateMemory('user-1', 'mem_abc123', $updates);
    }

    public function testUpdateMemoryWithProjectId(): void
    {
        $updates = ['content' => 'Updated content'];

        $this->store->shouldReceive('updateMemoryEntry')
            ->once()
            ->with('user-1', 'proj-1', 'mem_abc123', $updates);

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'updated entry'));

        $this->manager->updateMemory('user-1', 'mem_abc123', $updates, 'proj-1');
    }

    public function testStoreProjectMemoryReturnsId(): void
    {
        $this->store->shouldReceive('addProjectMemoryEntry')
            ->once()
            ->withArgs(function (string $userId, string $projectId, array $entry) {
                return $userId === 'user-1'
                    && $projectId === 'proj-1'
                    && $entry['category'] === 'architecture'
                    && $entry['content'] === 'Uses microservices'
                    && $entry['project_id'] === 'proj-1'
                    && str_starts_with($entry['id'], 'mem_');
            });

        $this->embeddingService->shouldReceive('isAvailable')->once()->andReturn(false);

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'stored'));

        $id = $this->manager->storeProjectMemory('user-1', 'proj-1', 'architecture', 'Uses microservices');

        $this->assertIsString($id);
        $this->assertStringStartsWith('mem_', $id);
    }

    public function testGetProjectMemories(): void
    {
        $memories = [
            ['id' => 'mem_1', 'content' => 'Project memory 1', 'project_id' => 'proj-1'],
        ];

        $this->store->shouldReceive('getProjectMemoryEntries')
            ->once()
            ->with('user-1', 'proj-1', 100)
            ->andReturn($memories);

        $result = $this->manager->getProjectMemories('user-1', 'proj-1');

        $this->assertCount(1, $result);
        $this->assertSame('proj-1', $result[0]['project_id']);
    }

    public function testDeleteProjectMemory(): void
    {
        $this->store->shouldReceive('deleteProjectMemoryEntry')
            ->once()
            ->with('user-1', 'proj-1', 'mem_abc123');

        $this->vectorStore->shouldReceive('delete')
            ->once()
            ->with('mem_abc123');

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'deleted project entry'));

        $this->manager->deleteProjectMemory('user-1', 'proj-1', 'mem_abc123');
    }

    public function testDeleteAnyMemory(): void
    {
        $this->store->shouldReceive('deleteAnyMemoryEntry')
            ->once()
            ->with('user-1', 'mem_abc123');

        $this->vectorStore->shouldReceive('delete')
            ->once()
            ->with('mem_abc123');

        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'deleted entry') && str_contains($msg, 'mem_abc123'));

        $this->manager->deleteAnyMemory('user-1', 'mem_abc123');
    }
}
