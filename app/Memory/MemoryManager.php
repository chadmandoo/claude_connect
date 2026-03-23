<?php

declare(strict_types=1);

namespace App\Memory;

use App\Embedding\EmbeddingService;
use App\Embedding\VectorStore;
use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Central manager for the structured memory system, handling storage, retrieval, and
 * contextual ranking of memories for prompt injection.
 *
 * Supports general and project-scoped memories with hybrid ranking (vector + keyword),
 * core memory pinning, async embedding, and scoped context building for system prompts.
 */
class MemoryManager
{
    private const MAX_CONTEXT_CHARS = 8000;
    private const MAX_RELEVANT_MEMORIES = 30;

    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private EmbeddingService $embeddingService;

    #[Inject]
    private VectorStore $vectorStore;

    #[Inject]
    private LoggerInterface $logger;

    /**
     * Build system prompt context from user's memory.
     * Returns a <user_memory> block for --append-system-prompt.
     * When $agentId is provided, filters memories by agent scope.
     */
    public function buildSystemPromptContext(string $userId, string $currentPrompt = '', ?string $agentId = null): string
    {
        $facts = $this->getFacts($userId);
        $log = $this->store->getMemoryLog($userId, 10);

        // If agent-scoped, use filtered queries; otherwise get all general memories
        if ($agentId !== null && $agentId !== '') {
            $structuredMemories = $this->store->getMemoriesForAgent($userId, $agentId, null, null, 200);
            // Filter to general (no project) memories only for this method
            $structuredMemories = array_filter($structuredMemories, fn ($m) => empty($m['project_id']));
            $structuredMemories = array_values($structuredMemories);
        } else {
            $structuredMemories = $this->store->getMemoryEntries($userId, 200);
        }

        if (empty($facts) && empty($log) && empty($structuredMemories)) {
            return '';
        }

        $parts = [];
        $parts[] = '<user_memory>';
        $surfacedIds = [];

        // Core memories (type = 'core') — always included
        $coreMemories = [];
        $otherMemories = [];

        foreach ($structuredMemories as $entry) {
            if ($entry === null) {
                continue;
            }
            if (($entry['type'] ?? 'project') === 'core') {
                $coreMemories[] = $entry;
            } else {
                $otherMemories[] = $entry;
            }
        }

        if (!empty($facts) || !empty($coreMemories)) {
            $parts[] = '*Core Memories:*';
            foreach ($facts as $key => $value) {
                $parts[] = "- {$key}: {$value}";
            }
            foreach ($coreMemories as $entry) {
                $cat = $entry['category'] ?? 'fact';
                $parts[] = "- [{$cat}] {$entry['content']}";
                $surfacedIds[] = $entry['id'];
            }
        }

        // Relevant context — hybrid vector + keyword ranking
        if (!empty($otherMemories)) {
            $relevant = $this->getRelevantMemories($userId, null, $otherMemories, $currentPrompt);
            if (!empty($relevant)) {
                $parts[] = '';
                $parts[] = '*Relevant Context:*';
                foreach ($relevant as $entry) {
                    $cat = $entry['category'] ?? 'fact';
                    $parts[] = "- [{$cat}] {$entry['content']}";
                    $surfacedIds[] = $entry['id'];
                }
            }
        }

        // Recent conversation summaries
        if (!empty($log)) {
            $parts[] = '';
            $parts[] = '*Recent Conversations:*';
            foreach ($log as $entry) {
                $parts[] = "- {$entry}";
            }
        }

        $parts[] = '</user_memory>';

        $context = implode("\n", $parts);

        // Truncate if too long
        if (mb_strlen($context) > self::MAX_CONTEXT_CHARS) {
            $context = mb_substr($context, 0, self::MAX_CONTEXT_CHARS - 20) . "\n</user_memory>";
        }

        // Record surfacing async
        $this->recordSurfaced($surfacedIds);

        return $context;
    }

    /**
     * Store a structured memory entry.
     */
    public function storeMemory(
        string $userId,
        string $category,
        string $content,
        string $importance = 'normal',
        string $source = 'inline',
        string $type = 'project',
        string $agentScope = '*',
    ): string {
        $id = 'mem_' . bin2hex(random_bytes(4));

        $entry = [
            'id' => $id,
            'category' => $category,
            'content' => $content,
            'importance' => $importance,
            'source' => $source,
            'type' => $type,
            'agent_scope' => $agentScope,
            'created_at' => time(),
        ];

        $this->store->addMemoryEntry($userId, $entry);
        $this->logger->info("Memory: stored {$type} entry {$id} for {$userId}: [{$category}/{$importance}] {$content}");

        // Async embed for vector search (non-blocking — nightly backfill catches failures)
        if ($this->embeddingService->isAvailable()) {
            \Swoole\Coroutine::create(fn () => $this->embeddingService->embedMemory(
                $id,
                $userId,
                'general',
                $category,
                $importance,
                $content,
                $entry['created_at'],
            ));
        }

        return $id;
    }

    /**
     * Get a single memory entry by ID (any scope).
     */
    public function getMemory(string $userId, string $entryId): ?array
    {
        return $this->store->getMemoryEntryById($userId, $entryId);
    }

    /**
     * Get all structured memory entries for a user.
     */
    public function getStructuredMemories(string $userId, int $limit = 100): array
    {
        return $this->store->getMemoryEntries($userId, $limit);
    }

    /**
     * Get count of structured memory entries.
     */
    public function getStructuredMemoryCount(string $userId): int
    {
        return $this->store->getMemoryEntryCount($userId);
    }

    /**
     * Get ALL memory entries (both general and project-scoped).
     */
    public function getAllMemories(string $userId, int $limit = 200): array
    {
        return $this->store->getAllMemoryEntries($userId, $limit);
    }

    /**
     * Get count of ALL memory entries (both general and project-scoped).
     */
    public function getAllMemoryCount(string $userId): int
    {
        return $this->store->getAllMemoryEntryCount($userId);
    }

    /**
     * Store a project-scoped memory entry.
     */
    public function storeProjectMemory(
        string $userId,
        string $projectId,
        string $category,
        string $content,
        string $importance = 'normal',
        string $source = 'inline',
        string $type = 'project',
        string $agentScope = '*',
    ): string {
        $id = 'mem_' . bin2hex(random_bytes(4));

        $entry = [
            'id' => $id,
            'category' => $category,
            'content' => $content,
            'importance' => $importance,
            'source' => $source,
            'type' => $type,
            'agent_scope' => $agentScope,
            'project_id' => $projectId,
            'created_at' => time(),
        ];

        $this->store->addProjectMemoryEntry($userId, $projectId, $entry);
        $this->logger->info("Memory: stored {$type} project entry {$id} for {$userId}/{$projectId}: [{$category}/{$importance}]");

        // Async embed for vector search
        if ($this->embeddingService->isAvailable()) {
            \Swoole\Coroutine::create(fn () => $this->embeddingService->embedMemory(
                $id,
                $userId,
                $projectId,
                $category,
                $importance,
                $content,
                $entry['created_at'],
            ));
        }

        return $id;
    }

    /**
     * Update a memory entry's fields.
     */
    public function updateMemory(string $userId, string $entryId, array $updates, ?string $projectId = null): void
    {
        $this->store->updateMemoryEntry($userId, $projectId, $entryId, $updates);
        $this->logger->info("Memory: updated entry {$entryId} for {$userId}");
    }

    /**
     * Get project-scoped memories.
     */
    public function getProjectMemories(string $userId, string $projectId, int $limit = 100): array
    {
        return $this->store->getProjectMemoryEntries($userId, $projectId, $limit);
    }

    /**
     * Delete a project-scoped memory entry.
     */
    public function deleteProjectMemory(string $userId, string $projectId, string $entryId): void
    {
        $this->store->deleteProjectMemoryEntry($userId, $projectId, $entryId);
        $this->vectorStore->delete($entryId);
        $this->logger->info("Memory: deleted project entry {$entryId} for {$userId}/{$projectId}");
    }

    /**
     * Build scoped memory context: general <user_memory> + optional <project_memory>.
     * When $agentId is provided, filters memories by agent scope.
     */
    public function buildScopedContext(string $userId, string $prompt, ?string $projectId = null, ?string $agentId = null): string
    {
        $generalContext = $this->buildSystemPromptContext($userId, $prompt, $agentId);

        if ($projectId === null || $projectId === '' || $projectId === 'general') {
            return $generalContext;
        }

        // If agent-scoped, filter project memories by agent
        if ($agentId !== null && $agentId !== '') {
            $projectMemories = $this->store->getMemoriesForAgent($userId, $agentId, $projectId, null, 100);
        } else {
            $projectMemories = $this->store->getProjectMemoryEntries($userId, $projectId, 100);
        }

        if (empty($projectMemories)) {
            return $generalContext;
        }

        $surfacedIds = [];

        // Separate core (always included) from project type (ranked)
        $coreProjectMemories = [];
        $otherProjectMemories = [];
        foreach ($projectMemories as $entry) {
            if ($entry === null) {
                continue;
            }
            if (($entry['type'] ?? 'project') === 'core') {
                $coreProjectMemories[] = $entry;
            } else {
                $otherProjectMemories[] = $entry;
            }
        }

        $rankedOthers = $this->getRelevantMemories($userId, $projectId, $otherProjectMemories, $prompt);

        $parts = [];
        if ($generalContext !== '') {
            $parts[] = $generalContext;
        }

        $projectParts = ['<project_memory>'];
        foreach ($coreProjectMemories as $entry) {
            $cat = $entry['category'] ?? 'fact';
            $projectParts[] = "- [{$cat}] [core] {$entry['content']}";
            $surfacedIds[] = $entry['id'];
        }
        foreach ($rankedOthers as $entry) {
            $cat = $entry['category'] ?? 'fact';
            $projectParts[] = "- [{$cat}] {$entry['content']}";
            $surfacedIds[] = $entry['id'];
        }
        $projectParts[] = '</project_memory>';
        $parts[] = implode("\n", $projectParts);

        // Record surfacing async
        $this->recordSurfaced($surfacedIds);

        return implode("\n\n", $parts);
    }

    /**
     * Delete a structured memory entry by ID (general scope only).
     */
    public function deleteStructuredMemory(string $userId, string $entryId): void
    {
        $this->store->deleteMemoryEntry($userId, $entryId);
        $this->vectorStore->delete($entryId);
        $this->logger->info("Memory: deleted structured entry {$entryId} for {$userId}");
    }

    /**
     * Delete any memory entry by ID regardless of project scope.
     */
    public function deleteAnyMemory(string $userId, string $entryId): void
    {
        $this->store->deleteAnyMemoryEntry($userId, $entryId);
        $this->vectorStore->delete($entryId);
        $this->logger->info("Memory: deleted entry {$entryId} for {$userId}");
    }

    public function remember(string $userId, string $key, string $value): void
    {
        $this->store->setMemoryFact($userId, $key, $value);
        $this->logger->info("Memory: set fact for {$userId}: {$key} = {$value}");
    }

    public function forget(string $userId, string $key): void
    {
        $this->store->deleteMemoryFact($userId, $key);
        $this->logger->info("Memory: deleted fact for {$userId}: {$key}");
    }

    public function getFacts(string $userId): array
    {
        return $this->store->getAllMemory($userId);
    }

    public function logConversation(string $userId, string $summary): void
    {
        $this->store->addMemoryLog($userId, $summary);
        $this->logger->debug("Memory: logged conversation for {$userId}");
    }

    /**
     * Record that memories were surfaced (included in a prompt).
     * Runs async to avoid blocking prompt building.
     */
    private function recordSurfaced(array $ids): void
    {
        $ids = array_filter($ids, fn ($id) => $id !== '' && $id !== null);
        if (empty($ids)) {
            return;
        }

        \Swoole\Coroutine::create(function () use ($ids) {
            try {
                $this->store->touchMemoriesSurfaced($ids);
            } catch (Throwable $e) {
                $this->logger->debug("Memory: failed to record surfacing: {$e->getMessage()}");
            }
        });
    }

    /**
     * Hybrid ranking: merge vector search results with keyword-scored results.
     * Falls back to keyword-only if embeddings are unavailable.
     *
     * @return array Top-N relevant memories
     */
    private function getRelevantMemories(string $userId, ?string $projectId, array $memories, string $currentPrompt): array
    {
        if ($currentPrompt === '') {
            return array_slice($memories, 0, self::MAX_RELEVANT_MEMORIES);
        }

        // Keyword-scored results
        $keywordResults = $this->scoreAndRankMemories($memories, $currentPrompt);

        // If embeddings unavailable, keyword-only
        if (!$this->embeddingService->isAvailable()) {
            return $keywordResults;
        }

        // Vector search results
        try {
            $vectorResults = $this->embeddingService->semanticSearch(
                $currentPrompt,
                $userId,
                $projectId,
                20,
            );
        } catch (Throwable $e) {
            $this->logger->debug("Memory: vector search failed, using keyword-only: {$e->getMessage()}");

            return $keywordResults;
        }

        if (empty($vectorResults)) {
            return $keywordResults;
        }

        // Build score maps
        $vectorScores = [];
        foreach ($vectorResults as $vr) {
            $vectorScores[$vr['memory_id']] = $vr['score'];
        }

        // Score keyword results (normalize to 0-1 range)
        $keywordScores = [];
        $maxKeywordScore = 0;
        $promptWords = $this->extractWords($currentPrompt);
        foreach ($memories as $entry) {
            $id = $entry['id'] ?? '';
            if ($id === '') {
                continue;
            }
            $content = $entry['content'] ?? '';
            $category = $entry['category'] ?? '';
            $contentWords = $this->extractWords($content . ' ' . $category);
            $overlap = count(array_intersect($promptWords, $contentWords));
            $score = $overlap / max(count($promptWords), 1);
            $age = time() - ($entry['created_at'] ?? 0);
            $recencyBoost = max(0, 1 - ($age / (86400 * 30)));
            $score += $recencyBoost * 0.1;
            $keywordScores[$id] = $score;
            $maxKeywordScore = max($maxKeywordScore, $score);
        }

        // Normalize keyword scores
        if ($maxKeywordScore > 0) {
            foreach ($keywordScores as &$s) {
                $s /= $maxKeywordScore;
            }
        }

        // Merge: combined score = 0.7 * vector + 0.3 * keyword
        $combined = [];
        $memoryById = [];
        foreach ($memories as $entry) {
            $id = $entry['id'] ?? '';
            if ($id !== '') {
                $memoryById[$id] = $entry;
            }
        }

        // Only score IDs that exist in the input memory set (avoid phantom vector results
        // from other scopes consuming top-K slots then getting dropped)
        foreach ($memoryById as $id => $_) {
            $vs = $vectorScores[$id] ?? 0;
            $ks = $keywordScores[$id] ?? 0;
            $combined[$id] = (0.7 * $vs) + (0.3 * $ks);
        }

        arsort($combined);
        $topIds = array_slice(array_keys($combined), 0, self::MAX_RELEVANT_MEMORIES);

        $result = [];
        foreach ($topIds as $id) {
            $result[] = $memoryById[$id];
        }

        return $result;
    }

    /**
     * Score and rank memories by relevance to the current prompt.
     * Uses simple word intersection scoring + recency bias.
     *
     * @return array Top-N relevant memories
     */
    private function scoreAndRankMemories(array $memories, string $currentPrompt): array
    {
        if ($currentPrompt === '') {
            // No prompt context — return most recent
            return array_slice($memories, 0, self::MAX_RELEVANT_MEMORIES);
        }

        $promptWords = $this->extractWords($currentPrompt);
        if (empty($promptWords)) {
            return array_slice($memories, 0, self::MAX_RELEVANT_MEMORIES);
        }

        $scored = [];
        foreach ($memories as $entry) {
            $content = $entry['content'] ?? '';
            $category = $entry['category'] ?? '';
            $contentWords = $this->extractWords($content . ' ' . $category);

            // Word intersection score
            $overlap = count(array_intersect($promptWords, $contentWords));
            $score = $overlap / max(count($promptWords), 1);

            // Small recency bias (newer entries get slight boost)
            $age = time() - ($entry['created_at'] ?? 0);
            $recencyBoost = max(0, 1 - ($age / (86400 * 30))); // decay over 30 days
            $score += $recencyBoost * 0.1;

            $scored[] = ['entry' => $entry, 'score' => $score];
        }

        // Sort by score descending
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $result = [];
        foreach (array_slice($scored, 0, self::MAX_RELEVANT_MEMORIES) as $item) {
            $result[] = $item['entry'];
        }

        return $result;
    }

    /**
     * Extract lowercase unique words from text (3+ chars, no stop words).
     */
    private function extractWords(string $text): array
    {
        $text = mb_strtolower($text);
        $words = preg_split('/\W+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $stopWords = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all',
            'can', 'has', 'her', 'was', 'one', 'our', 'out', 'his', 'had',
            'how', 'its', 'let', 'may', 'who', 'did', 'get', 'got', 'him',
            'she', 'they', 'them', 'been', 'have', 'from', 'this', 'that',
            'with', 'what', 'will', 'your', 'about', 'would', 'there',
            'their', 'which', 'could', 'other', 'into', 'just', 'also',
            'than', 'some', 'very', 'when', 'where'];

        return array_values(array_unique(array_filter($words, function ($w) use ($stopWords) {
            return mb_strlen($w) >= 3 && !in_array($w, $stopWords, true);
        })));
    }
}
