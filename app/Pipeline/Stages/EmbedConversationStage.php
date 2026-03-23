<?php

declare(strict_types=1);

namespace App\Pipeline\Stages;

use App\Conversation\ConversationManager;
use App\Embedding\EmbeddingService;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStage;
use Psr\Log\LoggerInterface;

/**
 * Pipeline stage that generates a vector embedding of the conversation summary.
 *
 * Embeds the conversation summary into the vector store for future semantic search,
 * enabling retrieval of relevant past conversations during prompt building.
 */
class EmbedConversationStage implements PipelineStage
{
    public function __construct(
        private readonly ConversationManager $conversationManager,
        private readonly EmbeddingService $embeddingService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'embed_conversation';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return $context->conversationId !== ''
            && $this->embeddingService->isAvailable();
    }

    public function execute(PipelineContext $context): array
    {
        $conversationId = $context->conversationId;
        $conversation = $this->conversationManager->getConversation($conversationId);

        if (!$conversation) {
            return ['success' => true, 'skipped' => 'conversation not found'];
        }

        $summary = $conversation['summary'] ?? '';
        if ($summary === '') {
            return ['success' => true, 'skipped' => 'no summary to embed'];
        }

        $userId = $context->userId;
        $projectId = $conversation['project_id'] ?? 'general';
        $vectorId = "conv_{$conversationId}";

        $success = $this->embeddingService->embedMemory(
            $vectorId,
            $userId,
            $projectId,
            'conversation',
            'normal',
            $summary,
            (int) ($conversation['created_at'] ?? time()),
        );

        if ($success) {
            $this->logger->info("EmbedConversation: embedded conversation {$conversationId}");
        }

        return ['success' => $success];
    }
}
