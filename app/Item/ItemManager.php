<?php

declare(strict_types=1);

namespace App\Item;

use App\Epic\EpicManager;
use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;
use Ramsey\Uuid\Uuid;

class ItemManager
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private EpicManager $epicManager;

    public function createItem(
        string $projectId,
        string $title,
        ?string $epicId = null,
        string $description = '',
        string $priority = 'normal',
        string $conversationId = '',
    ): string {
        // Default to backlog if no epic specified
        if ($epicId === null || $epicId === '') {
            $epicId = $this->epicManager->ensureBacklogEpic($projectId);
        }

        $itemId = Uuid::uuid4()->toString();

        // Get next sort order within epic
        $existingItems = $this->store->listEpicItems($epicId);
        $maxSort = 0;
        foreach ($existingItems as $item) {
            $sort = (int) ($item['sort_order'] ?? 0);
            if ($sort > $maxSort) {
                $maxSort = $sort;
            }
        }

        $item = [
            'id' => $itemId,
            'epic_id' => $epicId,
            'project_id' => $projectId,
            'title' => $title,
            'description' => $description,
            'state' => ItemState::OPEN->value,
            'priority' => $priority,
            'sort_order' => $maxSort + 1,
            'conversation_id' => $conversationId,
            'created_at' => time(),
            'updated_at' => time(),
            'completed_at' => 0,
        ];

        $this->store->createItem($itemId, $item);
        $this->store->addItemToEpic($epicId, $itemId, (float) ($maxSort + 1));
        $this->store->addItemToProject($projectId, $itemId);

        // Link to conversation if provided
        if ($conversationId !== '') {
            $this->store->linkItemToConversation($itemId, $conversationId);
        }

        return $itemId;
    }

    public function getItem(string $itemId): ?array
    {
        return $this->store->getItem($itemId);
    }

    public function updateItem(string $itemId, array $data): void
    {
        $item = $this->store->getItem($itemId);
        if (!$item) {
            throw new \RuntimeException("Item {$itemId} not found");
        }

        $allowed = ['title', 'description', 'priority'];
        $update = array_intersect_key($data, array_flip($allowed));
        $update['updated_at'] = time();

        $this->store->updateItem($itemId, $update);
    }

    public function transition(string $itemId, ItemState $targetState): void
    {
        $item = $this->store->getItem($itemId);
        if (!$item) {
            throw new \RuntimeException("Item {$itemId} not found");
        }

        $currentState = ItemState::from($item['state']);

        if (!$currentState->canTransitionTo($targetState)) {
            throw new \RuntimeException(
                "Invalid item transition from {$currentState->value} to {$targetState->value}"
            );
        }

        $update = [
            'state' => $targetState->value,
            'updated_at' => time(),
        ];

        if ($targetState === ItemState::DONE || $targetState === ItemState::CANCELLED) {
            $update['completed_at'] = time();
        }

        if ($targetState === ItemState::OPEN && $currentState === ItemState::DONE) {
            $update['completed_at'] = 0; // reopen
        }

        $this->store->updateItem($itemId, $update);

        // Auto-update parent epic state
        $epicId = $item['epic_id'] ?? '';
        if ($epicId !== '') {
            $this->epicManager->refreshEpicState($epicId);
        }
    }

    public function moveToEpic(string $itemId, string $newEpicId): void
    {
        $item = $this->store->getItem($itemId);
        if (!$item) {
            throw new \RuntimeException("Item {$itemId} not found");
        }

        $oldEpicId = $item['epic_id'] ?? '';

        if ($oldEpicId === $newEpicId) {
            return;
        }

        // Verify target epic exists and belongs to the same project
        $newEpic = $this->store->getEpic($newEpicId);
        if (!$newEpic) {
            throw new \RuntimeException("Epic {$newEpicId} not found");
        }

        $itemProjectId = $item['project_id'] ?? '';
        $epicProjectId = $newEpic['project_id'] ?? '';
        if ($itemProjectId !== '' && $epicProjectId !== '' && $itemProjectId !== $epicProjectId) {
            throw new \RuntimeException("Cannot move item to epic in a different project");
        }

        // Remove from old epic
        if ($oldEpicId !== '') {
            $this->store->removeItemFromEpic($oldEpicId, $itemId);
        }

        // Add to new epic
        $nextSort = $this->store->getEpicItemCount($newEpicId);
        $this->store->addItemToEpic($newEpicId, $itemId, (float) ($nextSort + 1));

        $this->store->updateItem($itemId, [
            'epic_id' => $newEpicId,
            'updated_at' => time(),
        ]);
    }

    public function listItemsByEpic(string $epicId): array
    {
        return $this->store->listEpicItems($epicId);
    }

    public function listItemsByProject(string $projectId, ?string $state = null): array
    {
        return $this->store->listProjectItems($projectId, $state);
    }

    public function deleteItem(string $itemId): void
    {
        $this->store->deleteItem($itemId);
    }

    public function linkConversation(string $itemId, string $conversationId): void
    {
        $this->store->linkItemToConversation($itemId, $conversationId);
    }

    public function getLinkedConversations(string $itemId): array
    {
        return $this->store->getItemConversations($itemId);
    }

    public function getLinkedItems(string $conversationId): array
    {
        return $this->store->getConversationItems($conversationId);
    }

    public function getProjectItemCounts(string $projectId): array
    {
        $items = $this->store->listProjectItems($projectId);
        $counts = ['open' => 0, 'in_progress' => 0, 'review' => 0, 'blocked' => 0, 'done' => 0, 'cancelled' => 0, 'total' => 0];
        foreach ($items as $item) {
            $state = $item['state'] ?? 'open';
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
            $counts['total']++;
        }
        return $counts;
    }

    public function assignItem(string $itemId, string $assignee): void
    {
        $item = $this->store->getItem($itemId);
        if (!$item) {
            throw new \RuntimeException("Item {$itemId} not found");
        }
        $this->store->updateItem($itemId, [
            'assigned_to' => $assignee,
            'updated_at' => time(),
        ]);
    }

    public function unassignItem(string $itemId): void
    {
        $item = $this->store->getItem($itemId);
        if (!$item) {
            throw new \RuntimeException("Item {$itemId} not found");
        }
        $this->store->updateItem($itemId, [
            'assigned_to' => '',
            'updated_at' => time(),
        ]);
    }

    public function getAssignedItems(string $assignee): array
    {
        // Scan all items across all projects — used by ItemAgent
        $itemIds = $this->store->getAllItemIds();
        $results = [];
        foreach ($itemIds as $itemId) {
            $item = $this->store->getItem($itemId);
            if ($item && ($item['assigned_to'] ?? '') === $assignee) {
                $results[] = $item;
            }
        }
        return $results;
    }

    public function addNote(string $itemId, string $content, string $author): void
    {
        $this->store->addItemNote($itemId, $content, $author);
    }

    public function getNotes(string $itemId, int $limit = 20): array
    {
        return $this->store->getItemNotes($itemId, $limit);
    }
}
