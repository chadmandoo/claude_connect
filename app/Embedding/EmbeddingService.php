<?php

declare(strict_types=1);

namespace App\Embedding;

use Psr\Log\LoggerInterface;

class EmbeddingService
{
    public function __construct(
        private VoyageClient $voyageClient,
        private VectorStore $vectorStore,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->voyageClient->isConfigured();
    }

    /**
     * Embed a single memory and upsert into the vector store.
     */
    public function embedMemory(
        string $memoryId,
        string $userId,
        string $projectId,
        string $category,
        string $importance,
        string $content,
        int $createdAt,
    ): bool {
        $vector = $this->voyageClient->embed($content);

        if ($vector === null) {
            $this->logger->warning("EmbeddingService: failed to embed memory {$memoryId}");
            return false;
        }

        $this->vectorStore->upsert(
            $memoryId,
            $userId,
            $projectId,
            $category,
            $importance,
            $content,
            $createdAt,
            $vector,
        );

        return true;
    }

    /**
     * Embed a batch of memories.
     *
     * @param array<int, array{id: string, user_id: string, project_id: string, category: string, importance: string, content: string, created_at: int}> $memories
     * @return int Number successfully embedded
     */
    public function embedBatch(array $memories): int
    {
        if (empty($memories)) {
            return 0;
        }

        $texts = array_map(fn($m) => $m['content'], $memories);
        $vectors = $this->voyageClient->embedBatch($texts);

        $expectedDims = count($vectors[0] ?? []);
        $success = 0;
        foreach ($memories as $i => $memory) {
            if (!isset($vectors[$i]) || !is_array($vectors[$i]) || count($vectors[$i]) !== $expectedDims) {
                $this->logger->warning("EmbeddingService: skipping memory {$memory['id']}: invalid vector");
                continue;
            }

            $this->vectorStore->upsert(
                $memory['id'],
                $memory['user_id'],
                $memory['project_id'] ?? 'general',
                $memory['category'],
                $memory['importance'] ?? 'normal',
                $memory['content'],
                $memory['created_at'] ?? time(),
                $vectors[$i],
            );
            $success++;
        }

        $this->logger->info("EmbeddingService: batch embedded {$success}/" . count($memories) . " memories");
        return $success;
    }

    /**
     * Semantic search for relevant memories.
     *
     * @param string $type Filter by type: 'memory' (default), 'conversation', 'task', 'all'
     * @return array<int, array{memory_id: string, score: float, content: string, category: string, importance: string, project_id: string}>
     */
    public function semanticSearch(
        string $query,
        string $userId,
        ?string $projectId = null,
        int $topK = 20,
        string $type = 'memory',
    ): array {
        $queryVector = $this->voyageClient->embedQuery($query);

        if ($queryVector === null) {
            $this->logger->warning('EmbeddingService: failed to embed query for search');
            return [];
        }

        $filters = ['user_id' => $userId];
        if ($projectId !== null && $projectId !== '' && $projectId !== 'general') {
            $filters['project_id'] = $projectId;
        }
        if ($type !== 'all' && $type !== '') {
            $filters['category'] = $type;
        }

        return $this->vectorStore->search($queryVector, $topK, $filters);
    }

    public function getVectorStore(): VectorStore
    {
        return $this->vectorStore;
    }

    public function getVoyageClient(): VoyageClient
    {
        return $this->voyageClient;
    }
}
