<?php

declare(strict_types=1);

namespace App\Nightly;

use App\Agent\AgentManager;
use App\Claude\ProcessManager;
use App\Embedding\EmbeddingService;
use App\Embedding\VectorStore;
use App\Epic\EpicManager;
use App\Item\ItemManager;
use App\Memory\MemoryManager;
use App\Project\ProjectManager;
use App\Prompts\PromptLoader;
use App\StateMachine\TaskManager;
use App\Storage\PostgresStore;
use App\Storage\RedisStore;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;

class NightlyConsolidationAgent
{
    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private ProcessManager $processManager;

    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private EpicManager $epicManager;

    #[Inject]
    private ItemManager $itemManager;

    #[Inject]
    private EmbeddingService $embeddingService;

    #[Inject]
    private VectorStore $vectorStore;

    #[Inject]
    private PromptLoader $promptLoader;

    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private RedisStore $redis;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private AgentManager $agentManager;

    #[Inject]
    private LoggerInterface $logger;

    private bool $running = false;
    private float $haikuBudgetSpent = 0.0;
    private float $voyageBudgetSpent = 0.0;

    /**
     * Start the nightly agent loop (runs on worker 0).
     */
    public function start(): void
    {
        $nightlyConfig = new NightlyConfig($this->config);

        if (!$nightlyConfig->enabled) {
            $this->logger->info('NightlyAgent: disabled by config');
            return;
        }

        $this->running = true;
        $this->logger->info("NightlyAgent: started (scheduled at {$nightlyConfig->runHour}:{$nightlyConfig->runMinute})");

        $lastRunDate = '';

        while ($this->running) {
            \Swoole\Coroutine::sleep(60);

            if (!$this->running) {
                break;
            }

            $today = date('Y-m-d');
            if ($today === $lastRunDate) {
                continue; // Already ran today
            }

            if (!$nightlyConfig->shouldRunNow()) {
                continue;
            }

            $lastRunDate = $today;

            try {
                $stats = $this->run(dryRun: false);
                $stats['duration'] = time() - ($stats['_start'] ?? time());
                unset($stats['_start']);
                $this->store->addNightlyRunResult($stats);
            } catch (\Throwable $e) {
                $this->logger->error("NightlyAgent: run error: {$e->getMessage()}");
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Execute a full nightly consolidation run.
     *
     * @return array{backfilled: int, validated: int, removed_stale: int, merged: int, summarized: int, orphans_cleaned: int, haiku_budget: float, voyage_budget: float}
     */
    public function run(
        bool $dryRun = false,
        bool $skipValidation = false,
        bool $skipDedup = false,
        bool $skipSummarization = false,
    ): array {
        $nightlyConfig = new NightlyConfig($this->config);
        $this->haikuBudgetSpent = 0.0;
        $this->voyageBudgetSpent = 0.0;

        // Acquire distributed lock (2 hour TTL)
        if (!$dryRun && !$this->redis->acquireLock('nightly:lock', 7200)) {
            $this->logger->info('NightlyAgent: skipping — lock held by another run');
            return [
                'backfilled' => 0, 'validated' => 0, 'removed_stale' => 0,
                'merged' => 0, 'summarized' => 0, 'orphans_cleaned' => 0,
                'haiku_budget' => 0.0, 'voyage_budget' => 0.0, 'skipped' => true,
            ];
        }

        $this->logger->info('NightlyAgent: starting run' . ($dryRun ? ' (DRY RUN)' : ''));

        $stats = [
            '_start' => time(),
            'backfilled' => 0,
            'validated' => 0,
            'removed_stale' => 0,
            'merged' => 0,
            'summarized' => 0,
            'orphans_cleaned' => 0,
            'stale_reviewed' => 0,
            'stale_removed' => 0,
            'stale_escalated' => 0,
            'haiku_budget' => 0.0,
            'voyage_budget' => 0.0,
        ];

        $userId = $this->config->get('mcp.web.user_id', 'web_user');

        // Phase 1: Backfill missing embeddings
        if ($this->embeddingService->isAvailable()) {
            $stats['backfilled'] = $this->phaseBackfill($userId, $nightlyConfig, $dryRun);
        }

        // Phase 2: Project expert validation
        if (!$skipValidation && $this->haikuBudgetSpent < $nightlyConfig->maxBudgetUsd) {
            $validationStats = $this->phaseValidation($userId, $nightlyConfig, $dryRun);
            $stats['validated'] = $validationStats['validated'];
            $stats['removed_stale'] = $validationStats['removed'];
        }

        // Phase 3: Similarity deduplication
        if (!$skipDedup && $this->embeddingService->isAvailable() && $this->haikuBudgetSpent < $nightlyConfig->maxBudgetUsd) {
            $stats['merged'] = $this->phaseDeduplication($userId, $nightlyConfig, $dryRun);
        }

        // Phase 4: Summarization
        if (!$skipSummarization && $this->haikuBudgetSpent < $nightlyConfig->maxBudgetUsd) {
            $stats['summarized'] = $this->phaseSummarization($userId, $nightlyConfig, $dryRun);
        }

        // Phase 5: Orphan vector cleanup
        $stats['orphans_cleaned'] = $this->phaseOrphanCleanup($userId, $dryRun);

        // Phase 6: Staleness review (project memories not surfaced in 30+ days)
        if ($this->haikuBudgetSpent < $nightlyConfig->maxBudgetUsd) {
            $stalenessStats = $this->phaseStalenessReview($userId, $nightlyConfig, $dryRun);
            $stats['stale_reviewed'] = $stalenessStats['reviewed'];
            $stats['stale_removed'] = $stalenessStats['removed'];
            $stats['stale_escalated'] = $stalenessStats['escalated'];
        }

        $stats['haiku_budget'] = round($this->haikuBudgetSpent, 6);
        $stats['voyage_budget'] = round($this->voyageBudgetSpent, 6);

        // Release distributed lock
        if (!$dryRun) {
            $this->redis->releaseLock('nightly:lock');
        }

        $this->logger->info('NightlyAgent: run complete', $stats);

        return $stats;
    }

    // --- Phase 1: Backfill Missing Embeddings ---

    private function phaseBackfill(string $userId, NightlyConfig $config, bool $dryRun): int
    {
        $this->logger->info('NightlyAgent: Phase 1 — backfill missing embeddings');

        $needsEmbedding = [];

        // General memories
        $general = $this->memoryManager->getStructuredMemories($userId, 10000);
        foreach ($general as $entry) {
            $id = $entry['id'] ?? '';
            if ($id !== '' && !$this->vectorStore->exists($id)) {
                $needsEmbedding[] = [
                    'id' => $id,
                    'user_id' => $userId,
                    'project_id' => 'general',
                    'category' => $entry['category'] ?? 'fact',
                    'importance' => $entry['importance'] ?? 'normal',
                    'content' => $entry['content'] ?? '',
                    'created_at' => (int) ($entry['created_at'] ?? time()),
                ];
            }
        }

        // Project memories
        $workspaces = $this->projectManager->listWorkspaces();
        foreach ($workspaces as $project) {
            $pid = $project['id'] ?? '';
            if ($pid === '') continue;

            $projectMemories = $this->memoryManager->getProjectMemories($userId, $pid, 10000);
            foreach ($projectMemories as $entry) {
                $id = $entry['id'] ?? '';
                if ($id !== '' && !$this->vectorStore->exists($id)) {
                    $needsEmbedding[] = [
                        'id' => $id,
                        'user_id' => $userId,
                        'project_id' => $pid,
                        'category' => $entry['category'] ?? 'fact',
                        'importance' => $entry['importance'] ?? 'normal',
                        'content' => $entry['content'] ?? '',
                        'created_at' => (int) ($entry['created_at'] ?? time()),
                    ];
                }
            }
        }

        if (empty($needsEmbedding)) {
            $this->logger->info('NightlyAgent: no memories need backfill');
            return 0;
        }

        if ($dryRun) {
            $this->logger->info("NightlyAgent [DRY RUN]: would backfill " . count($needsEmbedding) . " memories");
            return count($needsEmbedding);
        }

        $embedded = $this->embeddingService->embedBatch($needsEmbedding);
        $this->logger->info("NightlyAgent: backfilled {$embedded}/" . count($needsEmbedding) . " memories");

        return $embedded;
    }

    // --- Phase 2: Project Expert Validation ---

    private function phaseValidation(string $userId, NightlyConfig $config, bool $dryRun): array
    {
        $this->logger->info('NightlyAgent: Phase 2 — project expert validation');

        $stats = ['validated' => 0, 'removed' => 0];
        $template = $this->promptLoader->load('nightly/validate');
        if ($template === '') {
            $this->logger->warning('NightlyAgent: validate prompt not found, skipping');
            return $stats;
        }

        $contextBuilder = new CodebaseContextBuilder($this->logger);
        $workspaces = $this->projectManager->listWorkspaces();

        foreach ($workspaces as $project) {
            if ($this->haikuBudgetSpent >= $config->maxBudgetUsd) {
                $this->logger->info('NightlyAgent: budget cap reached during validation');
                break;
            }

            $pid = $project['id'] ?? '';
            if ($pid === '') continue;

            $projectMemories = $this->memoryManager->getProjectMemories($userId, $pid, 500);
            // Skip core memories — they are never auto-validated/pruned
            $projectMemories = array_values(array_filter($projectMemories, fn($m) => ($m['type'] ?? 'project') !== 'core'));
            if (empty($projectMemories)) continue;

            $projectName = $project['name'] ?? $pid;
            $cwd = $project['cwd'] ?? '';

            // Build expert context
            $codebaseContext = $contextBuilder->build($cwd);

            // Project metadata
            $epics = $this->epicManager->listEpics($pid);
            $items = $this->itemManager->listItemsByProject($pid);
            $projectContext = "Project: {$projectName}\nDescription: " . ($project['description'] ?? '') . "\n";
            $projectContext .= "Epics: " . count($epics) . ", Items: " . count($items) . "\n";
            if (!empty($epics)) {
                $epicNames = array_map(fn($e) => $e['title'] ?? '', $epics);
                $projectContext .= "Epic titles: " . implode(', ', array_filter($epicNames)) . "\n";
            }

            // Batch validate memories
            $batches = array_chunk($projectMemories, $config->batchSize);
            foreach ($batches as $batch) {
                if ($this->haikuBudgetSpent >= $config->maxBudgetUsd) break;

                $memoriesJson = json_encode(array_map(fn($m) => [
                    'id' => $m['id'] ?? '',
                    'category' => $m['category'] ?? 'fact',
                    'importance' => $m['importance'] ?? 'normal',
                    'content' => $m['content'] ?? '',
                    'age_days' => round((time() - ($m['created_at'] ?? time())) / 86400, 1),
                ], $batch), JSON_PRETTY_PRINT);

                $prompt = str_replace(
                    ['{project_context}', '{codebase_context}', '{memories}'],
                    [$projectContext, $codebaseContext, $memoriesJson],
                    $template,
                );

                $verdicts = $this->callHaiku($prompt, $config);
                if ($verdicts === null) continue;

                $parsed = $this->parseValidationResult($verdicts);
                $stats['validated'] += count($parsed);

                foreach ($parsed as $verdict) {
                    $memId = $verdict['id'] ?? '';
                    $classification = $verdict['verdict'] ?? 'accurate';
                    $confidence = (float) ($verdict['confidence'] ?? 0);

                    if (in_array($classification, ['stale', 'inaccurate'], true) && $confidence > 0.7) {
                        if ($dryRun) {
                            $this->logger->info("NightlyAgent [DRY RUN]: would remove {$classification} memory {$memId} in {$projectName}: " . ($verdict['reason'] ?? ''));
                        } else {
                            $this->memoryManager->deleteProjectMemory($userId, $pid, $memId);
                            $this->logger->info("NightlyAgent: removed {$classification} memory {$memId} from {$projectName}");
                        }
                        $stats['removed']++;
                    }
                }
            }
        }

        // Also validate general memories (excluding core)
        $generalMemories = $this->memoryManager->getStructuredMemories($userId, 500);
        $generalMemories = array_values(array_filter($generalMemories, fn($m) => ($m['type'] ?? 'project') !== 'core'));
        if (!empty($generalMemories) && $this->haikuBudgetSpent < $config->maxBudgetUsd) {
            $batches = array_chunk($generalMemories, $config->batchSize);
            foreach ($batches as $batch) {
                if ($this->haikuBudgetSpent >= $config->maxBudgetUsd) break;

                $memoriesJson = json_encode(array_map(fn($m) => [
                    'id' => $m['id'] ?? '',
                    'category' => $m['category'] ?? 'fact',
                    'importance' => $m['importance'] ?? 'normal',
                    'content' => $m['content'] ?? '',
                    'age_days' => round((time() - ($m['created_at'] ?? time())) / 86400, 1),
                ], $batch), JSON_PRETTY_PRINT);

                $prompt = str_replace(
                    ['{project_context}', '{codebase_context}', '{memories}'],
                    ['General (no specific project)', '', $memoriesJson],
                    $template,
                );

                $verdicts = $this->callHaiku($prompt, $config);
                if ($verdicts === null) continue;

                $parsed = $this->parseValidationResult($verdicts);
                $stats['validated'] += count($parsed);

                foreach ($parsed as $verdict) {
                    $memId = $verdict['id'] ?? '';
                    $classification = $verdict['verdict'] ?? 'accurate';
                    $confidence = (float) ($verdict['confidence'] ?? 0);

                    if (in_array($classification, ['stale', 'inaccurate'], true) && $confidence > 0.7) {
                        if ($dryRun) {
                            $this->logger->info("NightlyAgent [DRY RUN]: would remove {$classification} general memory {$memId}: " . ($verdict['reason'] ?? ''));
                        } else {
                            $this->memoryManager->deleteStructuredMemory($userId, $memId);
                            $this->logger->info("NightlyAgent: removed {$classification} general memory {$memId}");
                        }
                        $stats['removed']++;
                    }
                }
            }
        }

        $this->logger->info("NightlyAgent: validated {$stats['validated']} memories, removed {$stats['removed']}");
        return $stats;
    }

    // --- Phase 3: Similarity Deduplication ---

    private function phaseDeduplication(string $userId, NightlyConfig $config, bool $dryRun): int
    {
        $this->logger->info('NightlyAgent: Phase 3 — similarity deduplication');

        $mergeTemplate = $this->promptLoader->load('nightly/merge');
        if ($mergeTemplate === '') {
            $this->logger->warning('NightlyAgent: merge prompt not found, skipping');
            return 0;
        }

        $merged = 0;
        $processedIds = [];

        // Check general memories for duplicates (excluding core)
        $generalMemories = $this->memoryManager->getStructuredMemories($userId, 500);
        $generalMemories = array_values(array_filter($generalMemories, fn($m) => ($m['type'] ?? 'project') !== 'core'));
        $merged += $this->deduplicateMemories(
            $generalMemories, $userId, null, $config, $mergeTemplate, $processedIds, $dryRun,
        );

        // Check project memories
        $workspaces = $this->projectManager->listWorkspaces();
        foreach ($workspaces as $project) {
            if ($this->haikuBudgetSpent >= $config->maxBudgetUsd) break;

            $pid = $project['id'] ?? '';
            if ($pid === '') continue;

            $projectMemories = $this->memoryManager->getProjectMemories($userId, $pid, 500);
            $projectMemories = array_values(array_filter($projectMemories, fn($m) => ($m['type'] ?? 'project') !== 'core'));
            if (count($projectMemories) < 2) continue;

            $merged += $this->deduplicateMemories(
                $projectMemories, $userId, $pid, $config, $mergeTemplate, $processedIds, $dryRun,
            );
        }

        $this->logger->info("NightlyAgent: merged {$merged} duplicate pairs");
        return $merged;
    }

    private function deduplicateMemories(
        array $memories,
        string $userId,
        ?string $projectId,
        NightlyConfig $config,
        string $mergeTemplate,
        array &$processedIds,
        bool $dryRun,
    ): int {
        $merged = 0;
        $clusters = [];

        // Build lookup map for O(1) access
        $memoryMap = [];
        foreach ($memories as $mem) {
            $mid = $mem['id'] ?? '';
            if ($mid !== '') {
                $memoryMap[$mid] = $mem;
            }
        }

        foreach ($memories as $memory) {
            $id = $memory['id'] ?? '';
            if ($id === '' || isset($processedIds[$id])) continue;

            $neighbors = $this->vectorStore->findNeighbors($id, 5);
            foreach ($neighbors as $neighbor) {
                $nid = $neighbor['memory_id'];
                if (isset($processedIds[$nid])) continue;

                if ($neighbor['score'] >= $config->similarityThreshold) {
                    $clusters[] = [
                        'memory_a' => $memory,
                        'memory_b' => $memoryMap[$nid] ?? null,
                        'similarity' => $neighbor['score'],
                    ];
                    $processedIds[$id] = true;
                    $processedIds[$nid] = true;
                    break; // One merge per memory per run
                }
            }
        }

        if (empty($clusters)) return 0;

        foreach ($clusters as $cluster) {
            if ($this->haikuBudgetSpent >= $config->maxBudgetUsd) break;
            if ($cluster['memory_b'] === null) {
                $this->logger->debug("NightlyAgent: skipping merge — neighbor memory not found for {$cluster['memory_a']['id']}");
                continue;
            }

            $clusterJson = json_encode([
                ['id' => $cluster['memory_a']['id'], 'category' => $cluster['memory_a']['category'] ?? '', 'importance' => $cluster['memory_a']['importance'] ?? 'normal', 'content' => $cluster['memory_a']['content'] ?? ''],
                ['id' => $cluster['memory_b']['id'], 'category' => $cluster['memory_b']['category'] ?? '', 'importance' => $cluster['memory_b']['importance'] ?? 'normal', 'content' => $cluster['memory_b']['content'] ?? ''],
            ], JSON_PRETTY_PRINT);

            $prompt = str_replace('{cluster}', $clusterJson, $mergeTemplate);
            $result = $this->callHaiku($prompt, $config);
            if ($result === null) continue;

            $mergeResult = $this->parseMergeResult($result);
            if ($mergeResult === null) continue;

            $idA = $cluster['memory_a']['id'];
            $idB = $cluster['memory_b']['id'];

            if ($dryRun) {
                $this->logger->info("NightlyAgent [DRY RUN]: would merge memories {$idA} + {$idB} (similarity: {$cluster['similarity']})");
            } else {
                // Store merged memory
                if ($projectId !== null) {
                    $newId = $this->memoryManager->storeProjectMemory(
                        $userId, $projectId,
                        $mergeResult['category'],
                        $mergeResult['content'],
                        $mergeResult['importance'],
                        'nightly:merge',
                    );
                } else {
                    $newId = $this->memoryManager->storeMemory(
                        $userId,
                        $mergeResult['category'],
                        $mergeResult['content'],
                        $mergeResult['importance'],
                        'nightly:merge',
                    );
                }

                // Delete originals
                if ($projectId !== null) {
                    $this->memoryManager->deleteProjectMemory($userId, $projectId, $idA);
                    $this->memoryManager->deleteProjectMemory($userId, $projectId, $idB);
                } else {
                    $this->memoryManager->deleteStructuredMemory($userId, $idA);
                    $this->memoryManager->deleteStructuredMemory($userId, $idB);
                }

                $this->logger->info("NightlyAgent: merged {$idA} + {$idB} → {$newId}");
            }
            $merged++;
        }

        return $merged;
    }

    // --- Phase 4: Summarization ---

    private function phaseSummarization(string $userId, NightlyConfig $config, bool $dryRun): int
    {
        $this->logger->info('NightlyAgent: Phase 4 — summarization');

        $summarizeTemplate = $this->promptLoader->load('nightly/summarize');
        if ($summarizeTemplate === '') {
            $this->logger->warning('NightlyAgent: summarize prompt not found, skipping');
            return 0;
        }

        $summarized = 0;

        $workspaces = $this->projectManager->listWorkspaces();
        foreach ($workspaces as $project) {
            if ($this->haikuBudgetSpent >= $config->maxBudgetUsd) break;

            $pid = $project['id'] ?? '';
            if ($pid === '') continue;

            $projectMemories = $this->memoryManager->getProjectMemories($userId, $pid, 10000);
            $projectMemories = array_values(array_filter($projectMemories, fn($m) => ($m['type'] ?? 'project') !== 'core'));
            if (count($projectMemories) < $config->summarizationThreshold) continue;

            $projectName = $project['name'] ?? $pid;
            $this->logger->info("NightlyAgent: project '{$projectName}' has " . count($projectMemories) . " memories — checking for summarizable categories");

            // Group by category
            $byCategory = [];
            foreach ($projectMemories as $mem) {
                $cat = $mem['category'] ?? 'fact';
                $byCategory[$cat][] = $mem;
            }

            foreach ($byCategory as $category => $catMemories) {
                if (count($catMemories) < 15) continue;
                if ($this->haikuBudgetSpent >= $config->maxBudgetUsd) break;

                $memoriesJson = json_encode(array_map(fn($m) => [
                    'id' => $m['id'] ?? '',
                    'content' => $m['content'] ?? '',
                    'importance' => $m['importance'] ?? 'normal',
                ], $catMemories), JSON_PRETTY_PRINT);

                $prompt = str_replace(
                    ['{category}', '{project_name}', '{memories}'],
                    [$category, $projectName, $memoriesJson],
                    $summarizeTemplate,
                );

                $result = $this->callHaiku($prompt, $config);
                if ($result === null) continue;

                $summaries = $this->parseSummarizationResult($result);
                if (empty($summaries)) continue;

                if ($dryRun) {
                    $this->logger->info("NightlyAgent [DRY RUN]: would summarize " . count($catMemories) . " [{$category}] memories in '{$projectName}' → " . count($summaries) . " summaries");
                } else {
                    // Store new summaries
                    foreach ($summaries as $summary) {
                        $this->memoryManager->storeProjectMemory(
                            $userId, $pid,
                            $category,
                            $summary['content'],
                            $summary['importance'] ?? 'normal',
                            'nightly:summarize',
                        );
                    }

                    // Delete originals
                    foreach ($catMemories as $mem) {
                        $memId = $mem['id'] ?? '';
                        if ($memId !== '') {
                            $this->memoryManager->deleteProjectMemory($userId, $pid, $memId);
                        }
                    }

                    $this->logger->info("NightlyAgent: summarized " . count($catMemories) . " [{$category}] memories in '{$projectName}' → " . count($summaries));
                }
                $summarized += count($catMemories);
            }
        }

        return $summarized;
    }

    // --- Phase 5: Orphan Vector Cleanup ---

    private function phaseOrphanCleanup(string $userId, bool $dryRun): int
    {
        $this->logger->info('NightlyAgent: Phase 5 — orphan vector cleanup');

        $cleaned = 0;

        // Get all general and project memories to build a known-good set
        $knownIds = [];

        $general = $this->memoryManager->getStructuredMemories($userId, 10000);
        foreach ($general as $entry) {
            $id = $entry['id'] ?? '';
            if ($id !== '') $knownIds[$id] = true;
        }

        $workspaces = $this->projectManager->listWorkspaces();
        foreach ($workspaces as $project) {
            $pid = $project['id'] ?? '';
            if ($pid === '') continue;

            $projectMemories = $this->memoryManager->getProjectMemories($userId, $pid, 10000);
            foreach ($projectMemories as $entry) {
                $id = $entry['id'] ?? '';
                if ($id !== '') $knownIds[$id] = true;
            }
        }

        // Scan vector store for orphans
        foreach ($this->vectorStore->scanAllIds() as $vectorId) {
            if (isset($knownIds[$vectorId])) continue;

            if ($dryRun) {
                $this->logger->info("NightlyAgent [DRY RUN]: would clean orphan vector {$vectorId}");
            } else {
                $this->vectorStore->delete($vectorId);
            }
            $cleaned++;
        }

        if ($cleaned > 0) {
            $this->logger->info("NightlyAgent: cleaned {$cleaned} orphan vectors");
        }

        return $cleaned;
    }

    // --- Phase 6: Staleness Review ---

    private function phaseStalenessReview(string $userId, NightlyConfig $config, bool $dryRun): array
    {
        $this->logger->info('NightlyAgent: Phase 6 — staleness review');

        $stats = ['reviewed' => 0, 'removed' => 0, 'escalated' => 0];

        $stalenessThreshold = ($config->stalenessThresholdDays ?? 30) * 86400;
        $staleMemories = $this->store->getStaleProjectMemories($userId, $stalenessThreshold, 100);

        if (empty($staleMemories)) {
            $this->logger->info('NightlyAgent: no stale memories to review');
            return $stats;
        }

        $this->logger->info('NightlyAgent: found ' . count($staleMemories) . ' stale memories for review');

        $template = $this->promptLoader->load('nightly/staleness');
        if ($template === '') {
            $this->logger->warning('NightlyAgent: staleness prompt not found, skipping');
            return $stats;
        }

        // Group stale memories by agent_scope for context-aware review
        $batches = array_chunk($staleMemories, $config->batchSize);

        foreach ($batches as $batch) {
            if ($this->haikuBudgetSpent >= $config->maxBudgetUsd) {
                $this->logger->info('NightlyAgent: budget cap reached during staleness review');
                break;
            }

            // Resolve owning agent's system prompt for context
            $agentContext = 'No specific agent — shared across all agents';
            $firstAgentScope = $batch[0]['agent_scope'] ?? '*';
            if ($firstAgentScope !== '' && $firstAgentScope !== '*') {
                $agentId = explode(',', $firstAgentScope)[0];
                $agent = $this->agentManager->getAgent($agentId);
                if ($agent !== null) {
                    $agentContext = "Agent: {$agent['name']}\n" . mb_substr($agent['system_prompt'] ?? '', 0, 500);
                }
            }

            $memoriesJson = json_encode(array_map(fn($m) => [
                'id' => $m['id'] ?? '',
                'category' => $m['category'] ?? 'fact',
                'content' => $m['content'] ?? '',
                'project_id' => $m['project_id'] ?? 'general',
                'days_since_surfaced' => round((time() - ($m['last_surfaced_at'] ?? 0)) / 86400, 1),
                'age_days' => round((time() - ($m['created_at'] ?? time())) / 86400, 1),
            ], $batch), JSON_PRETTY_PRINT);

            $prompt = str_replace(
                ['{agent_system_prompt}', '{memories}'],
                [$agentContext, $memoriesJson],
                $template,
            );

            $result = $this->callHaiku($prompt, $config);
            if ($result === null) continue;

            $verdicts = $this->parseStalenessResult($result);
            $stats['reviewed'] += count($verdicts);

            foreach ($verdicts as $verdict) {
                $memId = $verdict['id'] ?? '';
                $decision = $verdict['verdict'] ?? 'keep';
                $confidence = (float) ($verdict['confidence'] ?? 0);

                if ($confidence < ($config->stalenessConfidenceThreshold ?? 0.7)) {
                    $stats['escalated']++;
                    $this->logger->info("NightlyAgent: uncertain about stale memory {$memId} (confidence: {$confidence}), escalating");
                    continue;
                }

                if ($decision === 'delete') {
                    if ($dryRun) {
                        $this->logger->info("NightlyAgent [DRY RUN]: would delete stale memory {$memId}: " . ($verdict['reason'] ?? ''));
                    } else {
                        $this->memoryManager->deleteAnyMemory($userId, $memId);
                        $this->logger->info("NightlyAgent: deleted stale memory {$memId}: " . ($verdict['reason'] ?? ''));
                    }
                    $stats['removed']++;
                }
                // 'keep' and 'archive' — leave the memory alone for now
            }
        }

        $this->logger->info("NightlyAgent: staleness review — reviewed {$stats['reviewed']}, removed {$stats['removed']}, escalated {$stats['escalated']}");
        return $stats;
    }

    private function parseStalenessResult(string $result): array
    {
        if (!preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            return [];
        }

        $data = json_decode($matches[0], true);
        if (!is_array($data)) {
            return [];
        }

        // Support both single verdict and array of verdicts
        if (isset($data['verdicts']) && is_array($data['verdicts'])) {
            return $data['verdicts'];
        }
        if (isset($data['verdict'])) {
            return [$data];
        }

        return [];
    }

    // --- Haiku Helper ---

    private function callHaiku(string $prompt, NightlyConfig $config): ?string
    {
        $taskId = $this->taskManager->createTask($prompt, null, [
            'source' => 'nightly',
            'model' => 'claude-haiku-4-5-20251001',
            'max_turns' => 1,
            'max_budget_usd' => $config->haikuCallBudgetUsd,
        ]);

        $this->processManager->executeTask($taskId);

        $maxWait = 60;
        $elapsed = 0;
        while ($elapsed < $maxWait) {
            \Swoole\Coroutine::sleep(1);
            $elapsed++;

            $task = $this->taskManager->getTask($taskId);
            if (!$task) return null;

            $state = $task['state'] ?? '';
            if ($state === 'completed') {
                $this->haikuBudgetSpent += (float) ($task['cost_usd'] ?? 0);
                return $task['result'] ?? '';
            }
            if ($state === 'failed') {
                $this->logger->warning("NightlyAgent: Haiku call failed for task {$taskId}");
                return null;
            }
        }

        $this->logger->warning("NightlyAgent: Haiku call timed out for task {$taskId}");
        return null;
    }

    // --- Result Parsers ---

    private function parseValidationResult(string $result): array
    {
        if (!preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            return [];
        }

        $data = json_decode($matches[0], true);
        if (!is_array($data) || !isset($data['verdicts'])) {
            return [];
        }

        return is_array($data['verdicts']) ? $data['verdicts'] : [];
    }

    private function parseMergeResult(string $result): ?array
    {
        if (!preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            return null;
        }

        $data = json_decode($matches[0], true);
        if (!is_array($data) || empty($data['content'])) {
            return null;
        }

        return [
            'content' => $data['content'],
            'category' => $data['category'] ?? 'fact',
            'importance' => $data['importance'] ?? 'normal',
        ];
    }

    private function parseSummarizationResult(string $result): array
    {
        if (!preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            return [];
        }

        $data = json_decode($matches[0], true);
        if (!is_array($data) || !isset($data['summaries'])) {
            return [];
        }

        return array_filter($data['summaries'], fn($s) => is_array($s) && !empty($s['content']));
    }

}
