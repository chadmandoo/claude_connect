<?php

declare(strict_types=1);

namespace App\Epic;

use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Manages epic lifecycle including creation, state transitions, and automatic state refresh.
 *
 * Handles backlog epic provisioning, item-to-epic relationships, and cascading state
 * updates when child items change (e.g., auto-completing an epic when all items are done).
 */
class EpicManager
{
    #[Inject]
    private PostgresStore $store;

    public function createEpic(
        string $projectId,
        string $title,
        string $description = '',
    ): string {
        $epicId = Uuid::uuid4()->toString();

        // Get next sort order (max existing + 1)
        $existingEpics = $this->store->listProjectEpics($projectId);
        $maxSort = 0;
        foreach ($existingEpics as $e) {
            $sort = (int) ($e['sort_order'] ?? 0);
            if ($sort > $maxSort && $sort < 999999) {
                $maxSort = $sort;
            }
        }

        $epic = [
            'id' => $epicId,
            'project_id' => $projectId,
            'title' => $title,
            'description' => $description,
            'state' => EpicState::OPEN->value,
            'is_backlog' => '0',
            'sort_order' => $maxSort + 1,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->store->createEpic($epicId, $epic);
        $this->store->addEpicToProject($projectId, $epicId, (float) ($maxSort + 1));

        return $epicId;
    }

    public function ensureBacklogEpic(string $projectId): string
    {
        $existingId = $this->store->getProjectBacklogEpic($projectId);
        if ($existingId !== null) {
            // Verify it still exists
            $epic = $this->store->getEpic($existingId);
            if ($epic) {
                return $existingId;
            }
        }

        $epicId = Uuid::uuid4()->toString();

        $epic = [
            'id' => $epicId,
            'project_id' => $projectId,
            'title' => 'Backlog',
            'description' => 'Ungrouped items',
            'state' => EpicState::OPEN->value,
            'is_backlog' => '1',
            'sort_order' => 999999,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->store->createEpic($epicId, $epic);
        $this->store->addEpicToProject($projectId, $epicId, 999999.0);

        // SETNX — only set if not already set (race condition guard)
        $wasSet = $this->store->setProjectBacklogEpic($projectId, $epicId);
        if (!$wasSet) {
            // Another process beat us — clean up our duplicate and use theirs
            $this->store->deleteEpic($epicId);

            return $this->store->getProjectBacklogEpic($projectId);
        }

        return $epicId;
    }

    public function getEpic(string $epicId): ?array
    {
        return $this->store->getEpic($epicId);
    }

    public function updateEpic(string $epicId, array $data): void
    {
        $epic = $this->store->getEpic($epicId);
        if (!$epic) {
            throw new RuntimeException("Epic {$epicId} not found");
        }

        if (($epic['is_backlog'] ?? '0') === '1') {
            // Backlog epic: only allow description updates
            unset($data['title'], $data['state'], $data['sort_order']);
        }

        $allowed = ['title', 'description'];
        $update = array_intersect_key($data, array_flip($allowed));
        $update['updated_at'] = time();

        $this->store->updateEpic($epicId, $update);
    }

    public function transition(string $epicId, EpicState $targetState): void
    {
        $epic = $this->store->getEpic($epicId);
        if (!$epic) {
            throw new RuntimeException("Epic {$epicId} not found");
        }

        if (($epic['is_backlog'] ?? '0') === '1') {
            throw new RuntimeException('Cannot transition backlog epic');
        }

        $currentState = EpicState::from($epic['state']);

        if (!$currentState->canTransitionTo($targetState)) {
            throw new RuntimeException(
                "Invalid epic transition from {$currentState->value} to {$targetState->value}",
            );
        }

        $this->store->updateEpic($epicId, [
            'state' => $targetState->value,
            'updated_at' => time(),
        ]);
    }

    public function listEpics(string $projectId): array
    {
        return $this->store->listProjectEpics($projectId);
    }

    /**
     * Recalculate epic state based on item states.
     * Called after item state transitions.
     */
    public function refreshEpicState(string $epicId): void
    {
        $epic = $this->store->getEpic($epicId);
        if (!$epic || ($epic['is_backlog'] ?? '0') === '1') {
            return;
        }

        $currentState = EpicState::from($epic['state']);
        if ($currentState->isTerminal()) {
            return;
        }

        $items = $this->store->listEpicItems($epicId);
        if (empty($items)) {
            return;
        }

        $counts = ['open' => 0, 'in_progress' => 0, 'blocked' => 0, 'done' => 0, 'cancelled' => 0];
        foreach ($items as $item) {
            $s = $item['state'] ?? 'open';
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        $total = count($items);
        $completed = $counts['done'] + $counts['cancelled'];
        $active = $counts['in_progress'] + $counts['blocked'];

        // All items done/cancelled → complete the epic
        if ($completed === $total) {
            $targetState = EpicState::COMPLETED;
        } elseif ($active > 0 || $counts['done'] > 0) {
            // Some items in progress or done → epic is in progress
            $targetState = EpicState::IN_PROGRESS;
        } else {
            // All open → epic is open
            $targetState = EpicState::OPEN;
        }

        if ($targetState !== $currentState && $currentState->canTransitionTo($targetState)) {
            $this->store->updateEpic($epicId, [
                'state' => $targetState->value,
                'updated_at' => time(),
            ]);
        }
    }

    public function deleteEpic(string $epicId, string $projectId): void
    {
        $epic = $this->store->getEpic($epicId);
        if (!$epic) {
            throw new RuntimeException("Epic {$epicId} not found");
        }

        if (($epic['is_backlog'] ?? '0') === '1') {
            throw new RuntimeException('Cannot delete backlog epic');
        }

        // Move items to backlog
        $backlogId = $this->ensureBacklogEpic($projectId);
        $items = $this->store->listEpicItems($epicId);
        foreach ($items as $item) {
            $this->store->removeItemFromEpic($epicId, $item['id']);
            $nextSort = $this->store->getEpicItemCount($backlogId);
            $this->store->addItemToEpic($backlogId, $item['id'], (float) ($nextSort + 1));
            $this->store->updateItem($item['id'], [
                'epic_id' => $backlogId,
                'updated_at' => time(),
            ]);
        }

        $this->store->deleteEpic($epicId);
    }
}
