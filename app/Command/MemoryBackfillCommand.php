<?php

declare(strict_types=1);

namespace App\Command;

use App\Conversation\ConversationManager;
use App\Embedding\EmbeddingService;
use App\Embedding\VectorStore;
use App\Memory\MemoryManager;
use App\Project\ProjectManager;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * CLI command `memory:backfill` to generate vector embeddings for existing memories
 * that are missing from the vector store, with optional conversation summary inclusion.
 */
#[Command]
class MemoryBackfillCommand extends HyperfCommand
{
    protected ?string $name = 'memory:backfill';

    protected string $description = 'Backfill vector embeddings for existing memories that are missing from the vector store';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $embeddingService = $this->container->get(EmbeddingService::class);
        $vectorStore = $this->container->get(VectorStore::class);
        $memoryManager = $this->container->get(MemoryManager::class);
        $projectManager = $this->container->get(ProjectManager::class);
        $config = $this->container->get(ConfigInterface::class);

        $dryRun = (bool) $this->input->getOption('dry-run');
        $batchSize = (int) $this->input->getOption('batch-size');
        $includeConversations = (bool) $this->input->getOption('include-conversations');
        $userId = $this->input->getOption('user-id') ?? $config->get('mcp.web.user_id', 'web_user');

        if (!$embeddingService->isAvailable()) {
            $this->error('Embedding service not available. Set VOYAGE_API_KEY in .env');

            return;
        }

        if ($dryRun) {
            $this->info('DRY RUN — no embeddings will be created');
        }

        $this->info("Backfilling embeddings for user: {$userId}");

        // Collect all memories: general + per-project
        $allMemories = [];

        // General memories
        $generalMemories = $memoryManager->getStructuredMemories($userId, 10000);
        foreach ($generalMemories as $entry) {
            if (!isset($entry['id'])) {
                continue;
            }
            $allMemories[] = [
                'id' => $entry['id'],
                'user_id' => $userId,
                'project_id' => 'general',
                'category' => $entry['category'] ?? 'fact',
                'importance' => $entry['importance'] ?? 'normal',
                'content' => $entry['content'] ?? '',
                'created_at' => (int) ($entry['created_at'] ?? time()),
            ];
        }
        $this->line('  General memories found: ' . count($generalMemories));

        // Project memories
        $workspaces = $projectManager->listWorkspaces();
        foreach ($workspaces as $project) {
            $pid = $project['id'] ?? '';
            if ($pid === '') {
                continue;
            }

            $projectMemories = $memoryManager->getProjectMemories($userId, $pid, 10000);
            foreach ($projectMemories as $entry) {
                if (!isset($entry['id'])) {
                    continue;
                }
                $allMemories[] = [
                    'id' => $entry['id'],
                    'user_id' => $userId,
                    'project_id' => $pid,
                    'category' => $entry['category'] ?? 'fact',
                    'importance' => $entry['importance'] ?? 'normal',
                    'content' => $entry['content'] ?? '',
                    'created_at' => (int) ($entry['created_at'] ?? time()),
                ];
            }
            if (!empty($projectMemories)) {
                $projectName = $project['name'] ?? $pid;
                $this->line("  Project '{$projectName}' memories found: " . count($projectMemories));
            }
        }

        // Conversation summaries (optional)
        if ($includeConversations) {
            $conversationManager = $this->container->get(ConversationManager::class);
            $conversations = $conversationManager->listConversations(null, 500);
            $convCount = 0;
            foreach ($conversations as $conv) {
                $summary = $conv['summary'] ?? '';
                if ($summary === '') {
                    continue;
                }

                $convId = $conv['id'] ?? '';
                $vectorId = "conv_{$convId}";
                $allMemories[] = [
                    'id' => $vectorId,
                    'user_id' => $userId,
                    'project_id' => $conv['project_id'] ?? 'general',
                    'category' => 'conversation',
                    'importance' => 'normal',
                    'content' => $summary,
                    'created_at' => (int) ($conv['created_at'] ?? time()),
                ];
                $convCount++;
            }
            $this->line("  Conversation summaries found: {$convCount}");
        }

        $this->info('Total memories found: ' . count($allMemories));

        // Filter out already-embedded
        $needsEmbedding = [];
        foreach ($allMemories as $memory) {
            if (!$vectorStore->exists($memory['id'])) {
                $needsEmbedding[] = $memory;
            }
        }

        $alreadyEmbedded = count($allMemories) - count($needsEmbedding);
        $this->info("Already embedded: {$alreadyEmbedded}");
        $this->info('Needs embedding: ' . count($needsEmbedding));

        if (empty($needsEmbedding)) {
            $this->info('Nothing to backfill — all memories have vectors.');

            return;
        }

        if ($dryRun) {
            $this->info('Would embed ' . count($needsEmbedding) . " memories in batches of {$batchSize}");

            return;
        }

        // Embed in batches
        $batches = array_chunk($needsEmbedding, $batchSize);
        $totalEmbedded = 0;
        $batchNum = 0;

        foreach ($batches as $batch) {
            $batchNum++;
            $embedded = $embeddingService->embedBatch($batch);
            $totalEmbedded += $embedded;
            $this->line("  Batch {$batchNum}/" . count($batches) . ": embedded {$embedded}/" . count($batch));
        }

        $this->info('');
        $this->info('=== Backfill Complete ===');
        $this->line("  Total embedded: {$totalEmbedded}");
        $this->line('  Failed: ' . (count($needsEmbedding) - $totalEmbedded));
        $this->info('========================');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('user-id', 'u', InputOption::VALUE_REQUIRED, 'User ID to backfill (default: WEB_USER_ID from config)');
        $this->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Batch size for embedding API calls', '64');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be embedded without making API calls');
        $this->addOption('include-conversations', null, InputOption::VALUE_NONE, 'Also backfill conversation summaries into the vector store');
    }
}
