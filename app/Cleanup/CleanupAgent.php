<?php

declare(strict_types=1);

namespace App\Cleanup;

use App\Claude\ProcessManager;
use App\Conversation\ConversationManager;
use App\Memory\MemoryManager;
use App\Project\ProjectManager;
use App\Prompts\PromptLoader;
use App\StateMachine\TaskManager;
use App\StateMachine\TaskState;
use App\Storage\PostgresStore;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Periodic garbage collection agent that triages, consolidates, and prunes old tasks and conversations.
 *
 * Runs a multi-phase cycle: reaps stale tasks, classifies items via Haiku (core/operational/ephemeral),
 * extracts knowledge from core items into memory, and deletes expired ephemeral/operational data.
 */
class CleanupAgent
{
    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private ProcessManager $processManager;

    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private ConversationManager $conversationManager;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private PromptLoader $promptLoader;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    private bool $running = false;

    private float $runBudgetSpent = 0.0;

    /**
     * Start the cleanup agent loop (runs on worker 0).
     */
    public function start(): void
    {
        $cleanupConfig = new CleanupConfig($this->config);

        if (!$cleanupConfig->enabled) {
            $this->logger->info('CleanupAgent: disabled by config');

            return;
        }

        $this->running = true;
        $this->logger->info("CleanupAgent: started (interval: {$cleanupConfig->interval}s)");

        while ($this->running) {
            \Swoole\Coroutine::sleep($cleanupConfig->interval);

            if (!$this->running) {
                break;
            }

            try {
                $this->run(dryRun: false);
            } catch (Throwable $e) {
                $this->logger->error("CleanupAgent: tick error: {$e->getMessage()}");
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Execute one full cleanup cycle.
     *
     * @return array{stale_reaped: int, triaged: int, consolidated: int, pruned_tasks: int, pruned_conversations: int, pruned_memories: int, budget_spent: float}
     */
    public function run(bool $dryRun = false, array $configOverrides = []): array
    {
        $cleanupConfig = new CleanupConfig($this->config, $configOverrides);
        $this->runBudgetSpent = 0.0;

        $this->logger->info('CleanupAgent: starting cleanup run' . ($dryRun ? ' (DRY RUN)' : ''));

        $stats = [
            'stale_reaped' => 0,
            'triaged' => 0,
            'consolidated' => 0,
            'pruned_tasks' => 0,
            'pruned_conversations' => 0,
            'pruned_memories' => 0,
            'budget_spent' => 0.0,
        ];

        // --- Phase 0: Reap stale running/pending tasks ---
        $stats['stale_reaped'] = $this->reapStaleTasks($cleanupConfig, $dryRun);

        // --- Phase 1: Gather candidates ---
        $taskCandidates = $this->gatherTaskCandidates($cleanupConfig);
        $conversationCandidates = $this->gatherConversationCandidates($cleanupConfig);

        $this->logger->info('CleanupAgent: found ' . count($taskCandidates) . ' task candidates, ' . count($conversationCandidates) . ' conversation candidates');

        if (empty($taskCandidates) && empty($conversationCandidates)) {
            $this->logger->info('CleanupAgent: no task/conversation candidates to triage');

            return $stats;
        }

        // --- Phase 2: Triage (batch classify via Haiku) ---
        $allCandidates = array_merge(
            array_map(fn (array $t) => $this->formatTaskForTriage($t), $taskCandidates),
            array_map(fn (array $c) => $this->formatConversationForTriage($c), $conversationCandidates),
        );

        $classifications = $this->triageBatched($allCandidates, $cleanupConfig);
        $stats['triaged'] = count($classifications);

        $coreCounts = count(array_filter($classifications, fn ($c) => $c['classification'] === 'core'));
        $opCounts = count(array_filter($classifications, fn ($c) => $c['classification'] === 'operational'));
        $ephCounts = count(array_filter($classifications, fn ($c) => $c['classification'] === 'ephemeral'));
        $this->logger->info("CleanupAgent: triage results — core: {$coreCounts}, operational: {$opCounts}, ephemeral: {$ephCounts}");

        // --- Phase 3: Consolidation (extract knowledge from core items) ---
        $coreItems = array_filter(
            $classifications,
            fn (array $c) =>
            $c['classification'] === 'core' && ($c['extract_memory'] ?? false),
        );

        if (!empty($coreItems)) {
            $consolidated = $this->consolidate(array_values($coreItems), $allCandidates, $cleanupConfig, $dryRun);
            $stats['consolidated'] = $consolidated;
        }

        // Mark core/operational conversations as learned (knowledge extracted or not needed)
        $learnableItems = array_filter(
            $classifications,
            fn (array $c) =>
            in_array($c['classification'], ['core', 'operational'], true),
        );
        foreach ($learnableItems as $c) {
            $candidate = $this->findCandidate($allCandidates, $c['id']);
            if ($candidate && ($candidate['type'] ?? '') === 'conversation') {
                if ($dryRun) {
                    $this->logger->info("CleanupAgent [DRY RUN]: would mark conversation {$c['id']} as learned");
                } else {
                    $this->conversationManager->markLearned($c['id']);
                }
            }
        }

        // --- Phase 4: Prune ---
        // Prune ephemeral immediately
        $ephemeralItems = array_values(array_filter(
            $classifications,
            fn (array $c) =>
            $c['classification'] === 'ephemeral',
        ));
        $stats = $this->pruneItems($ephemeralItems, $allCandidates, $dryRun, $stats);

        // Prune operational items only if older than 2x retention
        $doubleRetentionCutoff = time() - ($cleanupConfig->retentionDaysTasks * 86400 * 2);
        $operationalItems = array_filter(
            $classifications,
            fn (array $c) =>
            $c['classification'] === 'operational',
        );
        $oldOperational = array_values(array_filter($operationalItems, function (array $c) use ($allCandidates, $doubleRetentionCutoff) {
            $item = $this->findCandidate($allCandidates, $c['id']);
            $createdAt = (int) ($item['created_at'] ?? 0);

            return $createdAt > 0 && $createdAt < $doubleRetentionCutoff;
        }));
        if (!empty($oldOperational)) {
            $stats = $this->pruneItems($oldOperational, $allCandidates, $dryRun, $stats);
        }

        // --- Phase 4b: Prune old learned conversations (past retention) ---
        $learnedRetentionCutoff = $cleanupConfig->retentionCutoffConversations();
        $learnedConvIds = $this->store->getOldConversationIds($learnedRetentionCutoff, $cleanupConfig->maxItemsPerRun);
        foreach ($learnedConvIds as $convId) {
            $conv = $this->conversationManager->getConversation($convId);
            if (!$conv || ($conv['state'] ?? '') !== 'learned') {
                continue;
            }
            if ($dryRun) {
                $summary = mb_substr($conv['summary'] ?? '', 0, 80);
                $this->logger->info("CleanupAgent [DRY RUN]: would prune learned conversation {$convId} — \"{$summary}\"");
            } else {
                $this->conversationManager->deleteConversation($convId);
                $this->logger->debug("CleanupAgent: pruned learned conversation {$convId}");
            }
            $stats['pruned_conversations']++;
        }

        $stats['budget_spent'] = round($this->runBudgetSpent, 6);

        $this->logger->info('CleanupAgent: run complete', $stats);

        return $stats;
    }

    // --- Stale Task Reaper ---

    private function reapStaleTasks(CleanupConfig $config, bool $dryRun): int
    {
        $timeout = $config->staleTaskTimeoutSeconds;
        $cutoff = time() - $timeout;
        $reaped = 0;

        // Check running tasks
        $runningTasks = $this->taskManager->listTasks('running', 100);
        foreach ($runningTasks as $task) {
            $startedAt = (int) ($task['started_at'] ?? 0);
            if ($startedAt <= 0 || $startedAt > $cutoff) {
                continue;
            }

            $taskId = $task['id'] ?? '';
            $pid = (int) ($task['pid'] ?? 0);

            // Verify process is actually dead
            if ($pid > 0 && posix_kill($pid, 0)) {
                continue; // Still alive, leave it
            }

            $age = time() - $startedAt;
            $ageMin = round($age / 60);
            $prompt = mb_substr($task['prompt'] ?? '', 0, 80);

            if ($dryRun) {
                $this->logger->info("CleanupAgent [DRY RUN]: would reap stale task {$taskId} (running {$ageMin}m, PID {$pid} dead) — \"{$prompt}\"");
            } else {
                $this->taskManager->setTaskError($taskId, "Reaped: process died after {$ageMin} minutes without completing");
                $this->taskManager->transition($taskId, TaskState::FAILED);
                $this->logger->info("CleanupAgent: reaped stale task {$taskId} (running {$ageMin}m, PID {$pid} dead) — \"{$prompt}\"");
            }
            $reaped++;
        }

        // Check pending tasks (stuck before even starting)
        $pendingTasks = $this->taskManager->listTasks('pending', 100);
        foreach ($pendingTasks as $task) {
            $createdAt = (int) ($task['created_at'] ?? 0);
            if ($createdAt <= 0 || $createdAt > $cutoff) {
                continue;
            }

            $taskId = $task['id'] ?? '';
            $age = time() - $createdAt;
            $ageMin = round($age / 60);
            $prompt = mb_substr($task['prompt'] ?? '', 0, 80);

            if ($dryRun) {
                $this->logger->info("CleanupAgent [DRY RUN]: would reap stale pending task {$taskId} (pending {$ageMin}m) — \"{$prompt}\"");
            } else {
                $this->taskManager->setTaskError($taskId, "Reaped: stuck in pending state for {$ageMin} minutes");
                $this->taskManager->transition($taskId, TaskState::FAILED);
                $this->logger->info("CleanupAgent: reaped stale pending task {$taskId} (pending {$ageMin}m) — \"{$prompt}\"");
            }
            $reaped++;
        }

        if ($reaped > 0) {
            $this->logger->info("CleanupAgent: reaped {$reaped} stale tasks");
        }

        return $reaped;
    }

    // --- Candidate Gathering ---

    private function gatherTaskCandidates(CleanupConfig $config): array
    {
        $cutoff = $config->retentionCutoffTasks();
        $taskIds = $this->store->getOldTaskIds($cutoff, $config->maxItemsPerRun);

        $candidates = [];
        foreach ($taskIds as $taskId) {
            $task = $this->taskManager->getTask($taskId);
            if (!$task) {
                continue;
            }
            $state = $task['state'] ?? '';
            if ($state === 'pending' || $state === 'running') {
                continue;
            }
            $candidates[] = $task;
        }

        return $candidates;
    }

    private function gatherConversationCandidates(CleanupConfig $config): array
    {
        $cutoff = $config->retentionCutoffConversations();
        $convIds = $this->store->getOldConversationIds($cutoff, $config->maxItemsPerRun);

        $candidates = [];
        foreach ($convIds as $convId) {
            $conv = $this->conversationManager->getConversation($convId);
            if (!$conv) {
                continue;
            }
            $convState = $conv['state'] ?? '';
            if ($convState === 'active' || $convState === 'learned') {
                continue;
            }
            $candidates[] = $conv;
        }

        return $candidates;
    }

    // --- Triage Formatting ---

    private function formatTaskForTriage(array $task): array
    {
        // Use top-level project_id, fall back to conversation lookup for older tasks
        $projectId = $task['project_id'] ?? '';
        if ($projectId === '') {
            $projectId = 'general';
            $conversationId = $task['conversation_id'] ?? '';
            if ($conversationId !== '') {
                $conv = $this->conversationManager->getConversation($conversationId);
                if ($conv && !empty($conv['project_id'])) {
                    $projectId = $conv['project_id'];
                }
            }
        }

        return [
            'id' => $task['id'] ?? '',
            'type' => 'task',
            'created_at' => (int) ($task['created_at'] ?? 0),
            'state' => $task['state'] ?? '',
            'prompt' => mb_substr($task['prompt'] ?? '', 0, 200),
            'result_summary' => mb_substr($task['result'] ?? '', 0, 300),
            'cost_usd' => (float) ($task['cost_usd'] ?? 0),
            'options' => $task['options'] ?? '{}',
            'project_id' => $projectId,
        ];
    }

    private function formatConversationForTriage(array $conv): array
    {
        return [
            'id' => $conv['id'] ?? '',
            'type' => 'conversation',
            'created_at' => (int) ($conv['created_at'] ?? 0),
            'conversation_type' => $conv['type'] ?? 'task',
            'state' => $conv['state'] ?? '',
            'summary' => mb_substr($conv['summary'] ?? '', 0, 300),
            'project_id' => $conv['project_id'] ?? 'general',
            'turn_count' => (int) ($conv['turn_count'] ?? 0),
            'cost_usd' => (float) ($conv['total_cost_usd'] ?? 0),
        ];
    }

    // --- Batch Triage via Haiku ---

    private function triageBatched(array $candidates, CleanupConfig $config): array
    {
        $allClassifications = [];
        $batches = array_chunk($candidates, $config->batchSize);

        foreach ($batches as $batch) {
            if ($this->runBudgetSpent >= $config->maxBudgetUsd) {
                $this->logger->warning('CleanupAgent: budget cap reached during triage, stopping');
                break;
            }

            $classifications = $this->triageBatch($batch, $config);
            $allClassifications = array_merge($allClassifications, $classifications);
        }

        return $allClassifications;
    }

    private function triageBatch(array $batch, CleanupConfig $config): array
    {
        $template = $this->promptLoader->load('cleanup/triage');
        if ($template === '') {
            $this->logger->error('CleanupAgent: triage prompt not found');

            return $this->defaultClassifications($batch, 'operational');
        }

        $itemsJson = json_encode(
            array_map(fn (array $item) => [
                'id' => $item['id'],
                'type' => $item['type'],
                'prompt' => $item['prompt'] ?? $item['summary'] ?? '',
                'result_summary' => $item['result_summary'] ?? '',
                'state' => $item['state'] ?? '',
                'age_days' => round((time() - ($item['created_at'] ?? time())) / 86400, 1),
            ], $batch),
            JSON_PRETTY_PRINT,
        );

        $prompt = str_replace('{items}', $itemsJson, $template);

        $taskId = $this->taskManager->createTask($prompt, null, [
            'source' => 'cleanup',
            'model' => 'claude-haiku-4-5-20251001',
            'max_turns' => 1,
            'max_budget_usd' => $config->haikuCallBudgetUsd,
        ]);

        $this->processManager->executeTask($taskId);

        $maxWait = 30;
        $elapsed = 0;
        while ($elapsed < $maxWait) {
            \Swoole\Coroutine::sleep(1);
            $elapsed++;

            $task = $this->taskManager->getTask($taskId);
            if (!$task) {
                return $this->defaultClassifications($batch, 'operational');
            }

            $state = $task['state'] ?? '';
            if ($state === 'completed') {
                $this->runBudgetSpent += (float) ($task['cost_usd'] ?? 0);

                return $this->parseTriageResult($task['result'] ?? '', $batch);
            }
            if ($state === 'failed') {
                $this->logger->warning('CleanupAgent: triage batch failed, defaulting to operational');

                return $this->defaultClassifications($batch, 'operational');
            }
        }

        $this->logger->warning('CleanupAgent: triage batch timed out');

        return $this->defaultClassifications($batch, 'operational');
    }

    private function parseTriageResult(string $result, array $batch): array
    {
        if (!preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            return $this->defaultClassifications($batch, 'operational');
        }

        $data = json_decode($matches[0], true);
        if (!is_array($data) || !isset($data['classifications'])) {
            return $this->defaultClassifications($batch, 'operational');
        }

        $validCategories = ['core', 'operational', 'ephemeral'];
        $classifications = [];

        foreach ($data['classifications'] as $c) {
            $id = $c['id'] ?? '';
            $classification = $c['classification'] ?? 'operational';
            if (!in_array($classification, $validCategories, true)) {
                $classification = 'operational';
            }
            $classifications[] = [
                'id' => $id,
                'classification' => $classification,
                'reason' => $c['reason'] ?? '',
                'extract_memory' => (bool) ($c['extract_memory'] ?? false),
            ];
        }

        // Ensure all batch items are covered
        $classifiedIds = array_column($classifications, 'id');
        foreach ($batch as $item) {
            if (!in_array($item['id'], $classifiedIds, true)) {
                $classifications[] = [
                    'id' => $item['id'],
                    'classification' => 'operational',
                    'reason' => 'not classified by model',
                    'extract_memory' => false,
                ];
            }
        }

        return $classifications;
    }

    private function defaultClassifications(array $batch, string $default): array
    {
        return array_map(fn (array $item) => [
            'id' => $item['id'],
            'classification' => $default,
            'reason' => 'default (triage unavailable)',
            'extract_memory' => false,
        ], $batch);
    }

    // --- Consolidation ---

    private function consolidate(array $coreItems, array $allCandidates, CleanupConfig $config, bool $dryRun): int
    {
        if ($this->runBudgetSpent >= $config->maxBudgetUsd) {
            $this->logger->info('CleanupAgent: budget cap reached, skipping consolidation');

            return 0;
        }

        $template = $this->promptLoader->load('cleanup/consolidate');
        if ($template === '') {
            $this->logger->error('CleanupAgent: consolidate prompt not found');

            return 0;
        }

        // Gather full data for core items
        $itemsForConsolidation = [];
        foreach ($coreItems as $c) {
            $candidate = $this->findCandidate($allCandidates, $c['id']);
            if ($candidate) {
                $itemsForConsolidation[] = $candidate;
            }
        }

        if (empty($itemsForConsolidation)) {
            return 0;
        }

        $userId = $this->config->get('mcp.web.user_id', 'web_user');
        $existingMemories = $this->memoryManager->getStructuredMemories($userId, 100);
        $existingMemoriesJson = json_encode(array_map(fn ($m) => [
            'id' => $m['id'] ?? '',
            'category' => $m['category'] ?? '',
            'content' => $m['content'] ?? '',
        ], $existingMemories), JSON_PRETTY_PRINT);

        // Build available projects list for the prompt
        $workspaces = $this->projectManager->listWorkspaces();
        $availableProjectsJson = json_encode(array_map(fn ($p) => [
            'id' => $p['id'] ?? '',
            'name' => $p['name'] ?? '',
            'description' => $p['description'] ?? '',
        ], $workspaces), JSON_PRETTY_PRINT);

        $batches = array_chunk($itemsForConsolidation, $config->batchSize);
        $totalConsolidated = 0;

        foreach ($batches as $batch) {
            if ($this->runBudgetSpent >= $config->maxBudgetUsd) {
                break;
            }

            $itemsJson = json_encode(array_map(fn (array $item) => [
                'id' => $item['id'],
                'type' => $item['type'],
                'prompt' => $item['prompt'] ?? $item['summary'] ?? '',
                'result_summary' => $item['result_summary'] ?? '',
                'project_id' => $item['project_id'] ?? 'general',
            ], $batch), JSON_PRETTY_PRINT);

            $prompt = str_replace(
                ['{items}', '{available_projects}', '{existing_memories}'],
                [$itemsJson, $availableProjectsJson, $existingMemoriesJson],
                $template,
            );

            $taskId = $this->taskManager->createTask($prompt, null, [
                'source' => 'cleanup',
                'model' => 'claude-haiku-4-5-20251001',
                'max_turns' => 1,
                'max_budget_usd' => $config->haikuCallBudgetUsd,
            ]);

            $this->processManager->executeTask($taskId);

            $maxWait = 30;
            $elapsed = 0;
            while ($elapsed < $maxWait) {
                \Swoole\Coroutine::sleep(1);
                $elapsed++;

                $task = $this->taskManager->getTask($taskId);
                if (!$task) {
                    break;
                }

                $state = $task['state'] ?? '';
                if ($state === 'completed') {
                    $this->runBudgetSpent += (float) ($task['cost_usd'] ?? 0);
                    $stored = $this->processConsolidationResult($task['result'] ?? '', $userId, $dryRun);
                    $totalConsolidated += $stored;
                    break;
                }
                if ($state === 'failed') {
                    $this->logger->warning('CleanupAgent: consolidation batch failed');
                    break;
                }
            }
        }

        return $totalConsolidated;
    }

    private function processConsolidationResult(string $result, string $userId, bool $dryRun): int
    {
        if (!preg_match('/\{[\s\S]*\}/', $result, $matches)) {
            return 0;
        }

        $data = json_decode($matches[0], true);
        if (!is_array($data)) {
            return 0;
        }

        $stored = 0;

        if (!empty($data['memories']) && is_array($data['memories'])) {
            foreach ($data['memories'] as $mem) {
                $category = $mem['category'] ?? 'fact';
                $content = $mem['content'] ?? '';
                $importance = $mem['importance'] ?? 'normal';
                $projectId = $mem['project_id'] ?? 'general';

                if ($content === '' || !in_array($category, ['preference', 'project', 'fact', 'context', 'rule', 'conversation'], true)) {
                    continue;
                }

                if ($dryRun) {
                    $this->logger->info("CleanupAgent [DRY RUN]: would store memory [{$category}/{$importance}] project={$projectId}: {$content}");
                } else {
                    if ($projectId !== 'general' && in_array($category, ['project', 'context'], true)) {
                        $this->memoryManager->storeProjectMemory($userId, $projectId, $category, $content, $importance, 'cleanup:consolidation');
                    } else {
                        $this->memoryManager->storeMemory($userId, $category, $content, $importance, 'cleanup:consolidation');
                    }
                    $this->logger->debug("CleanupAgent: stored memory [{$category}/{$importance}]: {$content}");
                }
                $stored++;
            }
        }

        // Handle duplicate removal
        if (!empty($data['duplicates_found']) && is_array($data['duplicates_found']) && !$dryRun) {
            foreach ($data['duplicates_found'] as $dupId) {
                if (is_string($dupId) && $dupId !== '') {
                    $this->memoryManager->deleteStructuredMemory($userId, $dupId);
                    $this->logger->info("CleanupAgent: removed duplicate memory {$dupId}");
                }
            }
        }

        return $stored;
    }

    // --- Pruning ---

    private function pruneItems(array $classifications, array $allCandidates, bool $dryRun, array $stats): array
    {
        foreach ($classifications as $c) {
            $id = $c['id'];
            $candidate = $this->findCandidate($allCandidates, $id);

            if (!$candidate) {
                continue;
            }

            $type = $candidate['type'] ?? '';

            if ($type === 'task') {
                $task = $this->taskManager->getTask($id);
                if (!$task) {
                    continue;
                }

                $state = $task['state'] ?? '';
                if ($state === 'pending' || $state === 'running') {
                    continue;
                }

                if ($dryRun) {
                    $prompt = mb_substr($task['prompt'] ?? '', 0, 80);
                    $this->logger->info("CleanupAgent [DRY RUN]: would prune task {$id} ({$c['classification']}: {$c['reason']}) — \"{$prompt}\"");
                } else {
                    // Clean up user task index
                    $options = json_decode($task['options'] ?? '{}', true) ?: [];
                    $webUserId = $options['web_user_id'] ?? '';
                    if ($webUserId !== '') {
                        $this->store->removeUserTask($webUserId, $id);
                    }
                    $this->store->deleteTask($id);
                    $this->logger->debug("CleanupAgent: pruned task {$id}");
                }
                $stats['pruned_tasks']++;
                continue;
            }

            if ($type === 'conversation') {
                $conv = $this->conversationManager->getConversation($id);
                if (!$conv || ($conv['state'] ?? '') === 'active') {
                    continue;
                }

                if ($dryRun) {
                    $summary = mb_substr($conv['summary'] ?? '', 0, 80);
                    $this->logger->info("CleanupAgent [DRY RUN]: would prune conversation {$id} ({$c['classification']}: {$c['reason']}) — \"{$summary}\"");
                } else {
                    $this->conversationManager->deleteConversation($id);
                    $this->logger->debug("CleanupAgent: pruned conversation {$id}");
                }
                $stats['pruned_conversations']++;
            }
        }

        return $stats;
    }

    // --- Helpers ---

    private function findCandidate(array $candidates, string $id): ?array
    {
        foreach ($candidates as $c) {
            if (($c['id'] ?? '') === $id) {
                return $c;
            }
        }

        return null;
    }
}
