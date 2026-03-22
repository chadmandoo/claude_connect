<?php

declare(strict_types=1);

namespace App\Embedding;

use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

class VectorStore
{
    private const PREFIX = 'cc:memvec:';
    private const INDEX_NAME = 'idx:memory_vectors';

    public function __construct(
        private Redis $redis,
        private int $dimensions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create the RediSearch HNSW index if it doesn't exist.
     * Safe to call multiple times (catches "Index already exists").
     */
    public function ensureIndex(): void
    {
        try {
            // Check if index exists
            $this->redis->rawCommand('FT.INFO', self::INDEX_NAME);
            $this->logger->debug('VectorStore: index already exists');
            return;
        } catch (\Throwable) {
            // Index doesn't exist, create it
        }

        try {
            $this->redis->rawCommand(
                'FT.CREATE',
                self::INDEX_NAME,
                'ON', 'HASH',
                'PREFIX', '1', self::PREFIX,
                'SCHEMA',
                'memory_id', 'TAG',
                'user_id', 'TAG',
                'project_id', 'TAG',
                'category', 'TAG',
                'importance', 'TAG',
                'content', 'TEXT',
                'created_at', 'NUMERIC', 'SORTABLE',
                'vector', 'VECTOR', 'HNSW', '6',
                    'TYPE', 'FLOAT32',
                    'DIM', (string) $this->dimensions,
                    'DISTANCE_METRIC', 'COSINE',
            );
            $this->logger->info('VectorStore: created RediSearch index');
        } catch (\Throwable $e) {
            // Double-check it's not an "already exists" race condition
            if (str_contains($e->getMessage(), 'Index already exists')) {
                $this->logger->debug('VectorStore: index already exists (race)');
                return;
            }
            $this->logger->error("VectorStore: failed to create index: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Upsert a memory vector into the store.
     *
     * @param float[] $vector
     */
    public function upsert(
        string $memoryId,
        string $userId,
        string $projectId,
        string $category,
        string $importance,
        string $content,
        int $createdAt,
        array $vector,
    ): void {
        $key = self::PREFIX . $memoryId;
        $binaryVector = pack('f*', ...$vector);

        $this->redis->hMSet($key, [
            'memory_id' => $memoryId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'category' => $category,
            'importance' => $importance,
            'content' => $content,
            'created_at' => $createdAt,
            'vector' => $binaryVector,
        ]);
    }

    /**
     * Delete a memory vector by memory ID.
     */
    public function delete(string $memoryId): void
    {
        $this->redis->del(self::PREFIX . $memoryId);
    }

    /**
     * Check if a vector exists for a given memory ID.
     */
    public function exists(string $memoryId): bool
    {
        return (bool) $this->redis->exists(self::PREFIX . $memoryId);
    }

    /**
     * KNN vector search with optional tag filters.
     *
     * @param float[] $queryVector
     * @param int $topK Number of results to return
     * @param array{user_id?: string, project_id?: string, category?: string} $filters
     * @return array<int, array{memory_id: string, score: float, content: string, category: string, importance: string, project_id: string}>
     */
    public function search(array $queryVector, int $topK = 20, array $filters = []): array
    {
        $binaryVector = pack('f*', ...$queryVector);

        // Build filter expression
        $filterParts = [];
        if (!empty($filters['user_id'])) {
            $filterParts[] = "@user_id:{{$filters['user_id']}}";
        }
        if (!empty($filters['project_id'])) {
            $filterParts[] = "@project_id:{{$filters['project_id']}}";
        }
        if (!empty($filters['category'])) {
            $filterParts[] = "@category:{{$filters['category']}}";
        }

        $prefilter = !empty($filterParts) ? '(' . implode(' ', $filterParts) . ')' : '*';
        $query = "{$prefilter}=>[KNN {$topK} @vector \$query_vec AS score]";

        try {
            $result = $this->redis->rawCommand(
                'FT.SEARCH',
                self::INDEX_NAME,
                $query,
                'PARAMS', '2', 'query_vec', $binaryVector,
                'SORTBY', 'score',
                'RETURN', '6', 'memory_id', 'score', 'content', 'category', 'importance', 'project_id',
                'LIMIT', '0', (string) $topK,
                'DIALECT', '2',
            );

            return $this->parseSearchResults($result);
        } catch (\Throwable $e) {
            $this->logger->error("VectorStore: search failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Find nearest neighbors of a specific memory (for deduplication).
     *
     * @return array<int, array{memory_id: string, score: float}>
     */
    public function findNeighbors(string $memoryId, int $topK = 5): array
    {
        $key = self::PREFIX . $memoryId;
        $data = $this->redis->hGetAll($key);

        if (!$data || !isset($data['vector'])) {
            return [];
        }

        $vector = array_values(unpack('f*', $data['vector']));
        $results = $this->search($vector, $topK + 1); // +1 because it will match itself

        // Filter out self
        return array_values(array_filter($results, fn($r) => $r['memory_id'] !== $memoryId));
    }

    /**
     * Scan all vector keys (for orphan cleanup).
     *
     * @return \Generator<string> memory IDs
     */
    public function scanAllIds(): \Generator
    {
        $cursor = 0;
        do {
            // phpredis scan: $cursor passed by reference, returns array of keys or false
            $keys = $this->redis->scan($cursor, self::PREFIX . '*', 100);

            if ($keys !== false && is_array($keys)) {
                foreach ($keys as $key) {
                    yield str_replace(self::PREFIX, '', $key);
                }
            }
        } while ($cursor > 0);
    }

    /**
     * Parse FT.SEARCH result into structured array.
     */
    private function parseSearchResults(mixed $result): array
    {
        if (!is_array($result) || count($result) < 1) {
            return [];
        }

        $totalResults = $result[0];
        if ($totalResults === 0) {
            return [];
        }

        $parsed = [];
        // FT.SEARCH returns: [total, key1, [field, value, ...], key2, [field, value, ...], ...]
        for ($i = 1; $i < count($result); $i += 2) {
            if (!isset($result[$i + 1]) || !is_array($result[$i + 1])) {
                continue;
            }

            $fields = $result[$i + 1];
            $entry = [];
            for ($j = 0; $j < count($fields) - 1; $j += 2) {
                $entry[$fields[$j]] = $fields[$j + 1];
            }

            // Cosine distance → similarity: score is distance (0 = identical, 2 = opposite)
            // Convert to similarity: 1 - (distance / 2) gives range [0, 1]
            $distance = (float) ($entry['score'] ?? 1.0);
            $similarity = 1.0 - ($distance / 2.0);

            $parsed[] = [
                'memory_id' => $entry['memory_id'] ?? '',
                'score' => round($similarity, 4),
                'content' => $entry['content'] ?? '',
                'category' => $entry['category'] ?? '',
                'importance' => $entry['importance'] ?? '',
                'project_id' => $entry['project_id'] ?? '',
            ];
        }

        return $parsed;
    }
}
