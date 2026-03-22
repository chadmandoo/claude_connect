<?php

declare(strict_types=1);

namespace App\Memory;

use App\Embedding\VectorStore;
use App\Project\ProjectManager;
use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;

class MemoryAnalytics
{
    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private VectorStore $vectorStore;

    #[Inject]
    private PostgresStore $store;

    /**
     * Aggregate all analytics for the Memory dashboard.
     */
    public function getOverview(string $userId): array
    {
        $totalMemories = 0;
        $withVectors = 0;
        $categories = [];
        $ageBuckets = ['today' => 0, 'this_week' => 0, 'this_month' => 0, 'older' => 0];
        $projectBreakdown = [];

        $now = time();
        $dayAgo = $now - 86400;
        $weekAgo = $now - 604800;
        $monthAgo = $now - 2592000;

        // General memories
        $general = $this->memoryManager->getStructuredMemories($userId, 10000);
        $totalMemories += count($general);
        foreach ($general as $entry) {
            $cat = $entry['category'] ?? 'uncategorized';
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;

            $created = (int) ($entry['created_at'] ?? 0);
            $this->bucketByAge($created, $dayAgo, $weekAgo, $monthAgo, $ageBuckets);

            $id = $entry['id'] ?? '';
            if ($id !== '' && $this->vectorStore->exists($id)) {
                $withVectors++;
            }
        }

        // Project memories
        $workspaces = $this->projectManager->listWorkspaces();
        foreach ($workspaces as $project) {
            $pid = $project['id'] ?? '';
            if ($pid === '') continue;

            $projectMemories = $this->memoryManager->getProjectMemories($userId, $pid, 10000);
            $count = count($projectMemories);
            if ($count === 0) continue;

            $totalMemories += $count;
            $projectBreakdown[$project['name'] ?? $pid] = $count;

            foreach ($projectMemories as $entry) {
                $cat = $entry['category'] ?? 'uncategorized';
                $categories[$cat] = ($categories[$cat] ?? 0) + 1;

                $created = (int) ($entry['created_at'] ?? 0);
                $this->bucketByAge($created, $dayAgo, $weekAgo, $monthAgo, $ageBuckets);

                $id = $entry['id'] ?? '';
                if ($id !== '' && $this->vectorStore->exists($id)) {
                    $withVectors++;
                }
            }
        }

        $embeddedPct = $totalMemories > 0 ? round(($withVectors / $totalMemories) * 100) : 0;

        // Nightly history
        $nightlyHistory = $this->store->getNightlyRunHistory(10);

        return [
            'total_memories' => $totalMemories,
            'embedded_count' => $withVectors,
            'embedded_pct' => $embeddedPct,
            'project_count' => count($projectBreakdown),
            'categories' => $categories,
            'age_distribution' => $ageBuckets,
            'project_breakdown' => $projectBreakdown,
            'nightly_history' => $nightlyHistory,
        ];
    }

    private function bucketByAge(int $created, int $dayAgo, int $weekAgo, int $monthAgo, array &$buckets): void
    {
        if ($created >= $dayAgo) {
            $buckets['today']++;
        } elseif ($created >= $weekAgo) {
            $buckets['this_week']++;
        } elseif ($created >= $monthAgo) {
            $buckets['this_month']++;
        } else {
            $buckets['older']++;
        }
    }
}
