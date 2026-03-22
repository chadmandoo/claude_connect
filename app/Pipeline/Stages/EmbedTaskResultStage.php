<?php

declare(strict_types=1);

namespace App\Pipeline\Stages;

use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStage;
use App\StateMachine\TaskManager;
use App\Embedding\EmbeddingService;
use Psr\Log\LoggerInterface;

class EmbedTaskResultStage implements PipelineStage
{
    public function __construct(
        private readonly TaskManager $taskManager,
        private readonly EmbeddingService $embeddingService,
        private readonly LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'embed_task_result';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return $this->embeddingService->isAvailable();
    }

    public function execute(PipelineContext $context): array
    {
        $task = $context->task;
        $taskId = $task['id'] ?? '';
        $result = $task['result'] ?? '';

        // Only embed tasks with substantive results
        if (mb_strlen($result) < 100) {
            return ['success' => true, 'skipped' => 'result too short'];
        }

        $userId = $context->userId;
        $options = json_decode($task['options'] ?? '{}', true) ?: [];
        $projectId = $options['project_id'] ?? 'general';
        $vectorId = "task_{$taskId}";

        // Truncate to 500 chars for embedding
        $truncated = mb_substr($result, 0, 500);

        $success = $this->embeddingService->embedMemory(
            $vectorId,
            $userId,
            $projectId,
            'task',
            'normal',
            $truncated,
            (int) ($task['created_at'] ?? time()),
        );

        if ($success) {
            $this->logger->info("EmbedTaskResult: embedded task {$taskId}");
        }

        return ['success' => $success];
    }
}
