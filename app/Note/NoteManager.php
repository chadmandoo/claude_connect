<?php

declare(strict_types=1);

namespace App\Note;

use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Manages notebooks and pages for the notes system.
 *
 * Provides CRUD, reordering, and cross-notebook page movement for the
 * user-facing note-taking feature backed by PostgreSQL.
 */
class NoteManager
{
    #[Inject]
    private PostgresStore $store;

    // =========================================================================
    // Notebook operations
    // =========================================================================

    public function createNotebook(string $title, string $description = '', string $color = 'slate', string $icon = 'notebook'): string
    {
        $id = Uuid::uuid4()->toString();

        $existingNotebooks = $this->store->listNotebooks();
        $maxSort = 0;
        foreach ($existingNotebooks as $nb) {
            $sort = (int) ($nb['sort_order'] ?? 0);
            if ($sort > $maxSort) {
                $maxSort = $sort;
            }
        }

        $notebook = [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'icon' => $icon,
            'sort_order' => $maxSort + 1,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->store->createNotebook($id, $notebook);

        return $id;
    }

    public function getNotebook(string $id): ?array
    {
        return $this->store->getNotebook($id);
    }

    public function updateNotebook(string $id, array $data): void
    {
        $notebook = $this->store->getNotebook($id);
        if (!$notebook) {
            throw new RuntimeException("Notebook {$id} not found");
        }

        $allowed = ['title', 'description', 'color', 'icon'];
        $update = array_intersect_key($data, array_flip($allowed));
        $update['updated_at'] = time();

        $this->store->updateNotebook($id, $update);
    }

    public function deleteNotebook(string $id): void
    {
        $this->store->deleteNotebook($id);
    }

    public function listNotebooks(): array
    {
        return $this->store->listNotebooks();
    }

    public function reorderNotebooks(array $notebookIds): void
    {
        foreach ($notebookIds as $i => $nbId) {
            $this->store->updateNotebook($nbId, [
                'sort_order' => $i + 1,
                'updated_at' => time(),
            ]);
        }
    }

    // =========================================================================
    // Page operations
    // =========================================================================

    public function createPage(string $notebookId, string $title, string $content = ''): string
    {
        $notebook = $this->store->getNotebook($notebookId);
        if (!$notebook) {
            throw new RuntimeException("Notebook {$notebookId} not found");
        }

        $id = Uuid::uuid4()->toString();

        $existingPages = $this->store->listNotebookPages($notebookId);
        $maxSort = 0;
        foreach ($existingPages as $page) {
            $sort = (int) ($page['sort_order'] ?? 0);
            if ($sort > $maxSort) {
                $maxSort = $sort;
            }
        }

        $page = [
            'id' => $id,
            'notebook_id' => $notebookId,
            'title' => $title,
            'content' => $content,
            'pinned' => false,
            'sort_order' => $maxSort + 1,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->store->createPage($id, $page);

        return $id;
    }

    public function getPage(string $id): ?array
    {
        return $this->store->getPage($id);
    }

    public function updatePage(string $id, array $data): void
    {
        $page = $this->store->getPage($id);
        if (!$page) {
            throw new RuntimeException("Page {$id} not found");
        }

        $allowed = ['title', 'content', 'pinned'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($update['pinned'])) {
            $update['pinned'] = (bool) $update['pinned'];
        }
        $update['updated_at'] = time();

        $this->store->updatePage($id, $update);
    }

    public function deletePage(string $id): void
    {
        $this->store->deletePage($id);
    }

    public function listPages(string $notebookId): array
    {
        return $this->store->listNotebookPages($notebookId);
    }

    public function movePage(string $pageId, string $targetNotebookId): void
    {
        $page = $this->store->getPage($pageId);
        if (!$page) {
            throw new RuntimeException("Page {$pageId} not found");
        }

        $targetNotebook = $this->store->getNotebook($targetNotebookId);
        if (!$targetNotebook) {
            throw new RuntimeException("Notebook {$targetNotebookId} not found");
        }

        $existingPages = $this->store->listNotebookPages($targetNotebookId);
        $maxSort = 0;
        foreach ($existingPages as $p) {
            $sort = (int) ($p['sort_order'] ?? 0);
            if ($sort > $maxSort) {
                $maxSort = $sort;
            }
        }

        $this->store->updatePage($pageId, [
            'notebook_id' => $targetNotebookId,
            'sort_order' => $maxSort + 1,
            'updated_at' => time(),
        ]);
    }

    public function reorderPages(string $notebookId, array $pageIds): void
    {
        foreach ($pageIds as $i => $pageId) {
            $this->store->updatePage($pageId, [
                'sort_order' => $i + 1,
                'updated_at' => time(),
            ]);
        }
    }
}
