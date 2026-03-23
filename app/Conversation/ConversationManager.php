<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Manages multi-turn conversation lifecycle including creation, turn tracking, and completion.
 */
class ConversationManager
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private LoggerInterface $logger;

    public function createConversation(
        string $userId,
        ConversationType $type = ConversationType::TASK,
        string $projectId = 'general',
        string $source = 'web',
        ?string $agentId = null,
    ): string {
        $id = Uuid::uuid4()->toString();

        $conversation = [
            'id' => $id,
            'user_id' => $userId,
            'type' => $type->value,
            'state' => ConversationState::ACTIVE->value,
            'project_id' => $projectId,
            'source' => $source,
            'summary' => '',
            'key_takeaways' => '[]',
            'total_cost_usd' => '0.00',
            'turn_count' => 0,
            'agent_id' => $agentId,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->store->createConversation($id, $conversation);

        if ($projectId !== '' && $projectId !== 'general') {
            $this->store->addConversationToProject($projectId, $id);
        }

        $this->logger->info("Conversation: created {$id} type={$type->value} project={$projectId}");

        return $id;
    }

    public function getConversation(string $id): ?array
    {
        return $this->store->getConversation($id);
    }

    public function addTurn(
        string $id,
        string $role,
        string $content,
        ?string $taskId = null,
        float $cost = 0.0,
    ): void {
        $turn = [
            'role' => $role,
            'content' => mb_substr($content, 0, 5000),
            'task_id' => $taskId ?? '',
            'cost_usd' => number_format($cost, 6, '.', ''),
            'timestamp' => time(),
        ];

        $this->store->addConversationTurn($id, $turn);

        $update = ['updated_at' => time()];

        $conversation = $this->store->getConversation($id);
        if ($conversation) {
            $update['turn_count'] = (int) ($conversation['turn_count'] ?? 0) + 1;
        }

        $this->store->updateConversation($id, $update);

        if ($cost > 0) {
            $this->incrementCost($id, $cost);
        }
    }

    public function completeConversation(string $id): void
    {
        $this->store->updateConversation($id, [
            'state' => ConversationState::COMPLETED->value,
            'updated_at' => time(),
        ]);
        $this->logger->info("Conversation: completed {$id}");
    }

    public function updateSummary(string $id, string $summary, array $takeaways = []): void
    {
        $this->store->updateConversation($id, [
            'summary' => $summary,
            'key_takeaways' => json_encode($takeaways),
            'updated_at' => time(),
        ]);
    }

    public function listConversations(?string $projectId = null, int $limit = 30): array
    {
        return $this->store->listConversations($projectId, $limit);
    }

    public function getConversationTurns(string $id): array
    {
        return $this->store->getConversationTurns($id);
    }

    public function incrementCost(string $id, float $cost): void
    {
        $conversation = $this->store->getConversation($id);
        if (!$conversation) {
            return;
        }
        $current = (float) ($conversation['total_cost_usd'] ?? 0);
        $this->store->updateConversation($id, [
            'total_cost_usd' => number_format($current + $cost, 6, '.', ''),
        ]);
    }

    public function markLearned(string $id): void
    {
        $this->store->updateConversation($id, [
            'state' => ConversationState::LEARNED->value,
            'updated_at' => time(),
        ]);
        $this->logger->info("Conversation: marked learned {$id}");
    }

    public function deleteConversation(string $id): void
    {
        $this->store->deleteConversation($id);
        $this->logger->info("Conversation: deleted {$id}");
    }
}
