<?php

declare(strict_types=1);

namespace App\Pipeline\Stages;

use App\Claude\ProcessManager;
use App\Conversation\ConversationManager;
use App\Item\ItemManager;
use App\Memory\MemoryManager;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStage;
use App\Prompts\PromptLoader;
use App\StateMachine\TaskManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pipeline stage that uses Claude Haiku to extract structured data from a conversation.
 *
 * Generates summaries, key takeaways, memories, and work items from completed task
 * exchanges using type-specific extraction prompts, then routes extracted data to
 * the appropriate storage (memory manager, conversation manager, item manager).
 */
class ExtractConversationStage implements PipelineStage
{
    public function __construct(
        private readonly TaskManager $taskManager,
        private readonly ProcessManager $processManager,
        private readonly MemoryManager $memoryManager,
        private readonly ConversationManager $conversationManager,
        private readonly ItemManager $itemManager,
        private readonly PromptLoader $promptLoader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'extract_conversation';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return $context->userId !== '' && $context->conversationId !== '';
    }

    public function execute(PipelineContext $context): array
    {
        $task = $context->task;
        $userId = $context->userId;
        $conversationId = $context->conversationId;
        $conversationType = $context->conversationType ?: 'task';

        $prompt = $task['prompt'] ?? '';
        $result = $task['result'] ?? '';

        if ($prompt === '' || $result === '') {
            return ['success' => true, 'skipped' => 'empty prompt or result'];
        }

        $prompt = mb_substr($prompt, 0, 1000);
        $result = mb_substr($result, 0, 2000);

        // Load type-specific extraction prompt
        $extractionTemplate = $this->promptLoader->loadExtractionPrompt($conversationType);
        $extractionPrompt = str_replace(
            ['{prompt}', '{result}'],
            [$prompt, $result],
            $extractionTemplate,
        );

        $extractionTaskId = $this->taskManager->createTask($extractionPrompt, null, [
            'source' => 'extraction',
            'model' => 'claude-haiku-4-5-20251001',
            'max_turns' => 1,
            'max_budget_usd' => 0.05,
        ]);

        $this->processManager->executeTask($extractionTaskId);

        $maxWait = 30;
        $elapsed = 0;
        while ($elapsed < $maxWait) {
            \Swoole\Coroutine::sleep(1);
            $elapsed++;

            $extractionTask = $this->taskManager->getTask($extractionTaskId);
            if (!$extractionTask) {
                return ['success' => false, 'error' => 'extraction task disappeared'];
            }

            $state = $extractionTask['state'] ?? '';
            if ($state === 'completed') {
                $extractResult = $extractionTask['result'] ?? '';
                $this->processExtraction($userId, $conversationId, $conversationType, $extractResult);

                return ['success' => true];
            }

            if ($state === 'failed') {
                return ['success' => false, 'error' => 'extraction task failed'];
            }
        }

        return ['success' => false, 'error' => 'extraction timed out'];
    }

    private function processExtraction(
        string $userId,
        string $conversationId,
        string $conversationType,
        string $extractResult,
    ): void {
        if (!preg_match('/\{[\s\S]*\}/', $extractResult, $matches)) {
            return;
        }

        $data = json_decode($matches[0], true);
        if (!is_array($data)) {
            return;
        }

        // Update conversation summary and takeaways
        $summary = $data['summary'] ?? '';
        $takeaways = $data['key_takeaways'] ?? [];

        if ($summary !== '' || !empty($takeaways)) {
            $this->conversationManager->updateSummary($conversationId, $summary, $takeaways);
        }

        // Log conversation
        if ($summary !== '') {
            $logEntry = $summary;
            if (!empty($data['topics'])) {
                $logEntry .= ' [' . implode(', ', $data['topics']) . ']';
            }
            $this->memoryManager->logConversation($userId, $logEntry);
        }

        // Get project context from conversation
        $conversation = $this->conversationManager->getConversation($conversationId);
        $projectId = $conversation['project_id'] ?? 'general';

        // Store memories with type-aware routing
        if (!empty($data['memories']) && is_array($data['memories'])) {
            foreach ($data['memories'] as $mem) {
                $category = $mem['category'] ?? 'fact';
                $content = $mem['content'] ?? '';
                $importance = $mem['importance'] ?? 'normal';

                if ($content === '' || !in_array($category, ['preference', 'project', 'fact', 'context', 'rule', 'conversation'], true)) {
                    continue;
                }

                // Route based on category and project context
                if ($projectId !== 'general' && in_array($category, ['project', 'context'], true)) {
                    $this->memoryManager->storeProjectMemory($userId, $projectId, $category, $content, $importance, "extraction:{$conversationType}");
                } else {
                    $this->memoryManager->storeMemory($userId, $category, $content, $importance, "extraction:{$conversationType}");
                }
            }
        }

        // Create work items for non-general projects
        if ($projectId !== 'general' && !empty($data['work_items']) && is_array($data['work_items'])) {
            $existingItems = $this->itemManager->listItemsByProject($projectId);
            $existingTitles = array_map(
                fn (array $item) => mb_strtolower($item['title'] ?? ''),
                $existingItems,
            );

            foreach ($data['work_items'] as $wi) {
                $title = $wi['title'] ?? '';
                $description = $wi['description'] ?? '';
                $priority = $wi['priority'] ?? 'normal';

                if ($title === '') {
                    continue;
                }

                if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
                    $priority = 'normal';
                }

                // Dedup by case-insensitive title match
                if (in_array(mb_strtolower($title), $existingTitles, true)) {
                    continue;
                }

                try {
                    $this->itemManager->createItem($projectId, $title, null, $description, $priority, $conversationId);
                    $existingTitles[] = mb_strtolower($title);
                    $this->logger->info("Created work item from extraction: {$title}");
                } catch (Throwable $e) {
                    $this->logger->warning("Failed to create extracted work item: {$e->getMessage()}");
                }
            }
        }
    }
}
