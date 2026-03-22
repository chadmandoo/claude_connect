<?php

declare(strict_types=1);

namespace App\Todo;

use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;
use Ramsey\Uuid\Uuid;

class TodoManager
{
    #[Inject]
    private PostgresStore $store;

    // =========================================================================
    // Section operations
    // =========================================================================

    public function createSection(string $title, string $color = 'slate'): string
    {
        $id = Uuid::uuid4()->toString();

        $existing = $this->store->listTodoSections();
        $maxSort = 0;
        foreach ($existing as $s) {
            $sort = (int) ($s['sort_order'] ?? 0);
            if ($sort > $maxSort) {
                $maxSort = $sort;
            }
        }

        $section = [
            'id' => $id,
            'title' => $title,
            'color' => $color,
            'sort_order' => $maxSort + 1,
            'collapsed' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->store->createTodoSection($id, $section);
        return $id;
    }

    public function getSection(string $id): ?array
    {
        return $this->store->getTodoSection($id);
    }

    public function updateSection(string $id, array $data): void
    {
        $allowed = ['title', 'color', 'collapsed'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($update['collapsed'])) {
            $update['collapsed'] = (bool) $update['collapsed'];
        }
        $update['updated_at'] = time();
        $this->store->updateTodoSection($id, $update);
    }

    public function deleteSection(string $id): void
    {
        $this->store->deleteTodoSection($id);
    }

    public function listSections(): array
    {
        return $this->store->listTodoSections();
    }

    public function reorderSections(array $sectionIds): void
    {
        foreach ($sectionIds as $i => $sid) {
            $this->store->updateTodoSection($sid, [
                'sort_order' => $i + 1,
                'updated_at' => time(),
            ]);
        }
    }

    // =========================================================================
    // Item operations
    // =========================================================================

    public function createItem(string $sectionId, string $title, string $priority = 'normal', string $note = '', int $dueDate = 0): string
    {
        $id = Uuid::uuid4()->toString();

        $existing = $this->store->listTodoItems($sectionId);
        $maxSort = 0;
        foreach ($existing as $item) {
            $sort = (int) ($item['sort_order'] ?? 0);
            if ($sort > $maxSort) {
                $maxSort = $sort;
            }
        }

        $item = [
            'id' => $id,
            'section_id' => $sectionId,
            'title' => $title,
            'note' => $note,
            'done' => false,
            'priority' => $priority,
            'sort_order' => $maxSort + 1,
            'due_date' => $dueDate,
            'created_at' => time(),
            'updated_at' => time(),
            'completed_at' => 0,
        ];

        $this->store->createTodoItem($id, $item);
        return $id;
    }

    public function getItem(string $id): ?array
    {
        return $this->store->getTodoItem($id);
    }

    public function updateItem(string $id, array $data): void
    {
        $allowed = ['title', 'note', 'priority', 'due_date'];
        $update = array_intersect_key($data, array_flip($allowed));
        $update['updated_at'] = time();
        $this->store->updateTodoItem($id, $update);
    }

    public function toggleItem(string $id): bool
    {
        $item = $this->store->getTodoItem($id);
        if (!$item) {
            throw new \RuntimeException("Todo item {$id} not found");
        }

        $isDone = ($item['done'] ?? '0') === '1';
        $newDone = !$isDone;

        $update = [
            'done' => $newDone,
            'updated_at' => time(),
            'completed_at' => $newDone ? time() : 0,
        ];

        $this->store->updateTodoItem($id, $update);
        return $newDone;
    }

    public function deleteItem(string $id): void
    {
        $this->store->deleteTodoItem($id);
    }

    public function listItems(string $sectionId): array
    {
        return $this->store->listTodoItems($sectionId);
    }

    public function moveItem(string $itemId, string $targetSectionId): void
    {
        $existing = $this->store->listTodoItems($targetSectionId);
        $maxSort = 0;
        foreach ($existing as $item) {
            $sort = (int) ($item['sort_order'] ?? 0);
            if ($sort > $maxSort) {
                $maxSort = $sort;
            }
        }

        $this->store->updateTodoItem($itemId, [
            'section_id' => $targetSectionId,
            'sort_order' => $maxSort + 1,
            'updated_at' => time(),
        ]);
    }

    public function reorderItems(string $sectionId, array $itemIds): void
    {
        foreach ($itemIds as $i => $iid) {
            $this->store->updateTodoItem($iid, [
                'sort_order' => $i + 1,
                'updated_at' => time(),
            ]);
        }
    }

    /**
     * Get summary counts for all sections.
     */
    public function getSectionCounts(string $sectionId): array
    {
        $items = $this->store->listTodoItems($sectionId);
        $total = count($items);
        $done = 0;
        foreach ($items as $item) {
            if (($item['done'] ?? '0') === '1') {
                $done++;
            }
        }
        return ['total' => $total, 'done' => $done, 'remaining' => $total - $done];
    }

    /**
     * Clear all completed items from a section.
     */
    public function clearCompleted(string $sectionId): int
    {
        $items = $this->store->listTodoItems($sectionId);
        $cleared = 0;
        foreach ($items as $item) {
            if (($item['done'] ?? '0') === '1') {
                $this->store->deleteTodoItem($item['id']);
                $cleared++;
            }
        }
        return $cleared;
    }
}
