<?php

declare(strict_types=1);

namespace App\Pipeline\Stages;

use App\Claude\ProcessManager;
use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStage;
use App\Project\ProjectManager;
use App\StateMachine\TaskManager;
use Hyperf\Contract\ConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Pipeline stage that auto-detects whether a completed task is part of a larger project.
 *
 * Uses Claude Haiku to evaluate if the task has significant remaining work, and if so,
 * automatically creates a new project for multi-step orchestrated execution.
 */
class ProjectDetectionStage implements PipelineStage
{
    public function __construct(
        private readonly TaskManager $taskManager,
        private readonly ProcessManager $processManager,
        private readonly ProjectManager $projectManager,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'project_detection';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        if (!(bool) $this->config->get('mcp.project.auto_detect', true)) {
            return false;
        }

        if ($this->projectManager->getActiveProjectId() !== null) {
            return false;
        }

        $task = $context->task;

        return ($task['prompt'] ?? '') !== '' && ($task['result'] ?? '') !== '';
    }

    public function execute(PipelineContext $context): array
    {
        $task = $context->task;
        $truncatedPrompt = mb_substr($task['prompt'] ?? '', 0, 500);
        $truncatedResult = mb_substr($task['result'] ?? '', 0, 1000);

        $evalPrompt = <<<EVAL
            Analyze this task completion and determine if it's part of a larger project with remaining work.

            User asked: {$truncatedPrompt}
            Result: {$truncatedResult}

            Respond ONLY with valid JSON:
            {"is_project": true/false, "reason": "brief explanation"}

            Return is_project=true ONLY if the completed task clearly has significant remaining work that would benefit from multi-step automated execution (e.g., "build me an app" where only scaffolding was done). Return false for simple questions, lookups, single-file edits, or tasks that are genuinely complete.
            EVAL;

        $evalTaskId = $this->taskManager->createTask($evalPrompt, null, [
            'source' => 'extraction',
            'model' => 'claude-haiku-4-5-20251001',
            'max_turns' => 1,
            'max_budget_usd' => 0.05,
        ]);

        $this->processManager->executeTask($evalTaskId);

        // Wait synchronously for evaluation to complete
        $maxWait = 30;
        $elapsed = 0;
        while ($elapsed < $maxWait) {
            \Swoole\Coroutine::sleep(1);
            $elapsed++;

            $evalTask = $this->taskManager->getTask($evalTaskId);
            if (!$evalTask) {
                return ['success' => false, 'error' => 'eval task disappeared'];
            }

            if (($evalTask['state'] ?? '') === 'completed') {
                $evalResult = $evalTask['result'] ?? '';
                if (preg_match('/\{[\s\S]*\}/', $evalResult, $matches)) {
                    $data = json_decode($matches[0], true);
                    if (is_array($data) && ($data['is_project'] ?? false) === true) {
                        return $this->createProject($context);
                    }
                }

                return ['success' => true, 'is_project' => false];
            }

            if (($evalTask['state'] ?? '') === 'failed') {
                return ['success' => false, 'error' => 'eval task failed'];
            }
        }

        return ['success' => false, 'error' => 'eval timed out'];
    }

    private function createProject(PipelineContext $context): array
    {
        // Re-check no active project (race condition guard)
        if ($this->projectManager->getActiveProjectId() !== null) {
            return ['success' => true, 'is_project' => false, 'reason' => 'active project exists'];
        }

        $task = $context->task;
        $projectId = $this->projectManager->createProject(
            $task['prompt'] ?? '',
            $context->userId,
            [
                'max_iterations' => (int) $this->config->get('mcp.project.max_iterations', 20),
                'max_budget_usd' => (string) $this->config->get('mcp.project.max_budget_usd', 10.00),
                'checkpoint_interval' => (int) $this->config->get('mcp.project.checkpoint_interval', 5),
            ],
        );
        $this->projectManager->setActiveProject($projectId);

        $this->logger->info("Auto-created project {$projectId} from task completion");

        return ['success' => true, 'is_project' => true, 'project_id' => $projectId];
    }
}
