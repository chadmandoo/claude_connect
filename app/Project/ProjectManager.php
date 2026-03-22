<?php

declare(strict_types=1);

namespace App\Project;

use App\Storage\PostgresStore;
use App\Storage\RedisStore;
use Hyperf\Di\Annotation\Inject;
use Ramsey\Uuid\Uuid;

class ProjectManager
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private RedisStore $redis;

    public function createProject(
        string $goal,
        string $userId,
        array $options = [],
    ): string {
        $projectId = Uuid::uuid4()->toString();

        $project = [
            'id' => $projectId,
            'goal' => $goal,
            'plan' => '[]',
            'state' => ProjectState::PLANNING->value,
            'current_step' => 0,
            'total_steps' => 0,
            'completed_steps' => 0,
            'total_cost_usd' => '0.00',
            'max_iterations' => $options['max_iterations'] ?? 20,
            'max_budget_usd' => $options['max_budget_usd'] ?? '10.00',
            'checkpoint_interval' => $options['checkpoint_interval'] ?? 5,
            'current_task_id' => '',
            'retry_count' => 0,
            'created_at' => time(),
            'updated_at' => time(),
            'completed_at' => 0,
            'paused_reason' => '',
            'error' => '',
            'waiting_for_reply' => '0',
            'cwd' => $options['cwd'] ?? '',
            'user_id' => $userId,
        ];

        $this->store->createProject($projectId, $project);

        $this->addHistory($projectId, null, ProjectState::PLANNING);

        return $projectId;
    }

    public function createWorkspace(
        string $name,
        string $description,
        string $userId,
        ?string $cwd = null,
    ): string {
        $projectId = Uuid::uuid4()->toString();

        $project = [
            'id' => $projectId,
            'name' => $name,
            'description' => $description,
            'goal' => $name,
            'plan' => '[]',
            'state' => ProjectState::WORKSPACE->value,
            'current_step' => 0,
            'total_steps' => 0,
            'completed_steps' => 0,
            'total_cost_usd' => '0.00',
            'max_iterations' => 0,
            'max_budget_usd' => '0.00',
            'checkpoint_interval' => 0,
            'current_task_id' => '',
            'retry_count' => 0,
            'created_at' => time(),
            'updated_at' => time(),
            'completed_at' => 0,
            'paused_reason' => '',
            'error' => '',
            'waiting_for_reply' => '0',
            'cwd' => $cwd ?? '',
            'user_id' => $userId,
        ];

        $this->store->createProject($projectId, $project);
        $this->store->setProjectName($name, $projectId);

        return $projectId;
    }

    public function ensureGeneralProject(string $userId): string
    {
        $existingId = $this->store->getProjectByName('General');
        if ($existingId !== null) {
            return $existingId;
        }

        try {
            $id = $this->createWorkspace('General', 'Default workspace for general conversations', $userId);
        } catch (\Throwable) {
            // Race condition: another worker created it first
            $existingId = $this->store->getProjectByName('General');
            if ($existingId !== null) {
                return $existingId;
            }
            throw new \RuntimeException('Failed to create General project');
        }

        return $id;
    }

    public function getByName(string $name): ?array
    {
        $id = $this->store->getProjectByName($name);
        if ($id === null) {
            return null;
        }
        return $this->getProject($id);
    }

    public function listWorkspaces(): array
    {
        return $this->store->listWorkspaceProjects();
    }

    public function updateWorkspace(string $projectId, array $data): void
    {
        $allowed = ['name', 'description', 'cwd', 'default_agent_id'];
        $update = array_intersect_key($data, array_flip($allowed));
        $update['updated_at'] = time();

        // If name changed, update the name index
        if (isset($update['name'])) {
            $old = $this->getProject($projectId);
            if ($old && !empty($old['name']) && $old['name'] !== $update['name']) {
                $this->store->removeProjectName($old['name']);
            }
            $this->store->setProjectName($update['name'], $projectId);
        }

        $this->store->updateProject($projectId, $update);
    }

    public function getProject(string $projectId): ?array
    {
        return $this->store->getProject($projectId);
    }

    public function transition(string $projectId, ProjectState $targetState, ?string $reason = null): void
    {
        $project = $this->store->getProject($projectId);
        if (!$project) {
            throw new \RuntimeException("Project {$projectId} not found");
        }

        $currentState = ProjectState::from($project['state']);

        if (!$currentState->canTransitionTo($targetState)) {
            throw new \RuntimeException(
                "Invalid project transition from {$currentState->value} to {$targetState->value}"
            );
        }

        $update = [
            'state' => $targetState->value,
            'updated_at' => time(),
        ];

        if ($targetState->isTerminal()) {
            $update['completed_at'] = time();
            $this->redis->clearActiveProject();
        }

        if ($targetState === ProjectState::PAUSED && $reason !== null) {
            $update['paused_reason'] = $reason;
        }

        $this->store->updateProject($projectId, $update);
        $this->addHistory($projectId, $currentState, $targetState, $reason);
    }

    public function updateField(string $projectId, string $field, string|int|float $value): void
    {
        $this->store->updateProject($projectId, [$field => $value, 'updated_at' => time()]);
    }

    public function getActiveProjectId(): ?string
    {
        return $this->redis->getActiveProjectId();
    }

    public function setActiveProject(string $projectId): void
    {
        $this->redis->setActiveProject($projectId);
    }

    public function clearActiveProject(): void
    {
        $this->redis->clearActiveProject();
    }

    public function updatePlan(string $projectId, array $plan): void
    {
        $this->store->updateProject($projectId, [
            'plan' => json_encode($plan),
            'total_steps' => count($plan),
            'updated_at' => time(),
        ]);
    }

    public function addStepResult(string $projectId, array $stepResult): void
    {
        $this->store->addProjectStep($projectId, $stepResult);
    }

    public function getStepResults(string $projectId): array
    {
        return $this->store->getProjectSteps($projectId);
    }

    public function incrementCost(string $projectId, float $amount): void
    {
        $project = $this->store->getProject($projectId);
        if (!$project) {
            return;
        }
        $current = (float) ($project['total_cost_usd'] ?? 0);
        $this->store->updateProject($projectId, [
            'total_cost_usd' => number_format($current + $amount, 4, '.', ''),
        ]);
    }

    public function setCurrentTaskId(string $projectId, string $taskId): void
    {
        $this->store->updateProject($projectId, [
            'current_task_id' => $taskId,
            'updated_at' => time(),
        ]);
    }

    public function incrementRetryCount(string $projectId): int
    {
        $project = $this->store->getProject($projectId);
        $count = (int) ($project['retry_count'] ?? 0) + 1;
        $this->store->updateProject($projectId, ['retry_count' => $count]);
        return $count;
    }

    public function resetRetryCount(string $projectId): void
    {
        $this->store->updateProject($projectId, ['retry_count' => 0]);
    }

    public function listProjects(?string $state = null, int $limit = 20): array
    {
        return $this->store->listProjects($state, $limit);
    }

    public function getProjectHistory(string $projectId): array
    {
        return $this->store->getProjectHistory($projectId);
    }

    private function addHistory(string $projectId, ?ProjectState $from, ProjectState $to, ?string $reason = null): void
    {
        $entry = [
            'from' => $from?->value,
            'to' => $to->value,
            'timestamp' => time(),
        ];
        if ($reason !== null) {
            $entry['reason'] = $reason;
        }
        $this->store->addProjectHistory($projectId, $entry);
    }
}
