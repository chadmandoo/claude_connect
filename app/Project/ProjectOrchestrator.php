<?php

declare(strict_types=1);

namespace App\Project;

use App\StateMachine\TaskManager;
use App\Claude\ProcessManager;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Contract\ConfigInterface;
use Psr\Log\LoggerInterface;

class ProjectOrchestrator
{
    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private ProcessManager $processManager;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    private bool $running = false;

    public function start(): void
    {
        $this->running = true;
        $interval = (int) $this->config->get('mcp.project.orchestrator_interval', 5);

        $this->logger->info('ProjectOrchestrator started');

        while ($this->running) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                $this->logger->error("ProjectOrchestrator tick error: {$e->getMessage()}");
            }

            \Swoole\Coroutine::sleep($interval);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function tick(): void
    {
        $projectId = $this->projectManager->getActiveProjectId();
        if ($projectId === null) {
            return;
        }

        $project = $this->projectManager->getProject($projectId);
        if (!$project) {
            $this->projectManager->clearActiveProject();
            return;
        }

        $state = ProjectState::from($project['state']);

        // Handle PLANNING state — generate a plan
        if ($state === ProjectState::PLANNING) {
            $this->generatePlan($projectId, $project);
            return;
        }

        // Only process ACTIVE projects
        if (!$state->isRunnable()) {
            return;
        }

        // Check if waiting for user reply
        if (($project['waiting_for_reply'] ?? '0') === '1') {
            return;
        }

        // Check if there's a current task running
        $currentTaskId = $project['current_task_id'] ?? '';
        if ($currentTaskId !== '') {
            $task = $this->taskManager->getTask($currentTaskId);
            if (!$task) {
                // Task lost — treat as failure
                $this->handleStepFailure($projectId, $project, 'Task disappeared');
                return;
            }

            $taskState = $task['state'] ?? '';

            if ($taskState === 'completed') {
                $this->handleStepCompletion($projectId, $project, $task);
                return;
            }

            if ($taskState === 'failed') {
                $error = $task['error'] ?? 'Unknown error';
                $this->handleStepFailure($projectId, $project, $error);
                return;
            }

            // Still running — wait
            return;
        }

        // No current task — check safety limits before executing next step
        $safetyReason = $this->checkSafetyLimits($project);
        if ($safetyReason !== null) {
            $this->logger->info("Project {$projectId} hit safety limit: {$safetyReason}");
            $this->projectManager->transition($projectId, ProjectState::PAUSED, $safetyReason);
            // Safety limit reached
            return;
        }

        // Check if we need a user checkpoint
        if ($this->needsCheckpoint($project)) {
            $this->projectManager->transition($projectId, ProjectState::PAUSED, 'Scheduled checkpoint');
            // Scheduled checkpoint
            return;
        }

        // Check if project is complete
        $completedSteps = (int) ($project['completed_steps'] ?? 0);
        $totalSteps = (int) ($project['total_steps'] ?? 0);
        if ($completedSteps >= $totalSteps && $totalSteps > 0) {
            $this->projectManager->transition($projectId, ProjectState::COMPLETED);
            // Project completed
            return;
        }

        // Execute next step
        $this->executeNextStep($projectId, $project);
    }

    private function generatePlan(string $projectId, array $project): void
    {
        // If already has a current task running for planning, wait
        $currentTaskId = $project['current_task_id'] ?? '';
        if ($currentTaskId !== '') {
            $task = $this->taskManager->getTask($currentTaskId);
            if ($task && !in_array($task['state'] ?? '', ['completed', 'failed'], true)) {
                return; // Still running
            }

            if ($task && ($task['state'] ?? '') === 'completed') {
                $this->parsePlanResult($projectId, $project, $task['result'] ?? '');
                return;
            }

            if ($task && ($task['state'] ?? '') === 'failed') {
                // Planning failed — use single-step fallback
                $this->logger->warning("Plan generation failed for project {$projectId}, using single-step fallback");
                $this->projectManager->updatePlan($projectId, [$project['goal']]);
                $this->projectManager->transition($projectId, ProjectState::ACTIVE);
                // Plan generated (single-step fallback)
                return;
            }
        }

        $goal = $project['goal'] ?? '';
        $prompt = <<<PROMPT
Break this goal into a step-by-step plan of 3-10 concrete steps. Each step should be independently executable by a coding assistant with full filesystem access.

Goal: {$goal}

Respond ONLY with a JSON array of step descriptions. Each step should be a clear, actionable instruction.

Example:
["Create the project directory structure and initialize package.json", "Implement the main server file with Express routes", "Add input validation and error handling", "Write unit tests", "Create a README with usage instructions"]
PROMPT;

        $taskId = $this->taskManager->createTask($prompt, null, [
            'source' => 'extraction',
            'model' => 'claude-haiku-4-5-20251001',
            'max_turns' => 1,
            'max_budget_usd' => 0.05,
        ]);

        $this->projectManager->setCurrentTaskId($projectId, $taskId);
        $this->processManager->executeTask($taskId);

        $this->logger->info("Project {$projectId}: generating plan (task {$taskId})");
    }

    private function parsePlanResult(string $projectId, array $project, string $result): void
    {
        $plan = null;

        // Try to extract JSON array from result
        if (preg_match('/\[[\s\S]*\]/', $result, $matches)) {
            $plan = json_decode($matches[0], true);
        }

        if (!is_array($plan) || empty($plan)) {
            // Fallback: single step
            $plan = [$project['goal']];
            $this->logger->warning("Project {$projectId}: could not parse plan, using single-step fallback");
        }

        // Ensure all entries are strings
        $plan = array_values(array_filter(array_map(function ($step) {
            return is_string($step) ? trim($step) : null;
        }, $plan)));

        if (empty($plan)) {
            $plan = [$project['goal']];
        }

        $this->projectManager->updatePlan($projectId, $plan);
        $this->projectManager->setCurrentTaskId($projectId, '');
        $this->projectManager->transition($projectId, ProjectState::ACTIVE);

        // Plan generated

        $this->logger->info("Project {$projectId}: plan generated with " . count($plan) . " steps");
    }

    private function executeNextStep(string $projectId, array $project): void
    {
        $plan = json_decode($project['plan'] ?? '[]', true) ?: [];
        $currentStep = (int) ($project['current_step'] ?? 0);

        if ($currentStep >= count($plan)) {
            // All steps done
            $this->projectManager->transition($projectId, ProjectState::COMPLETED);
            // Project completed
            return;
        }

        $goal = $project['goal'] ?? '';
        $stepInstruction = $plan[$currentStep] ?? '';
        $completedSummaries = $this->getCompletedSummaries($projectId, $plan);
        $stepBudget = (float) $this->config->get('mcp.project.step_budget_usd', 2.00);

        // Build step prompt with full context
        $planDisplay = $this->buildPlanDisplay($plan, $currentStep);

        $prompt = <<<PROMPT
## Project Goal
{$goal}

## Full Plan
{$planDisplay}

{$completedSummaries}

## Current Step ({$this->stepLabel($currentStep, count($plan))})
{$stepInstruction}

Execute this step now. Build on any work from previous steps (check the filesystem for existing files). Focus ONLY on this step. End with a clear summary of what you accomplished.
PROMPT;

        $options = [
            'source' => 'web',
            'max_budget_usd' => $stepBudget,
        ];

        // Set working directory if project has one
        $cwd = $project['cwd'] ?? '';
        if ($cwd !== '') {
            $options['cwd'] = $cwd;
        }

        $taskId = $this->taskManager->createTask($prompt, null, $options);

        $this->projectManager->setCurrentTaskId($projectId, $taskId);
        $this->processManager->executeTask($taskId);

        // Step started

        $this->logger->info("Project {$projectId}: executing step {$currentStep} (task {$taskId})");
    }

    private function handleStepCompletion(string $projectId, array $project, array $task): void
    {
        $plan = json_decode($project['plan'] ?? '[]', true) ?: [];
        $currentStep = (int) ($project['current_step'] ?? 0);
        $result = $task['result'] ?? '';
        $costUsd = (float) ($task['cost_usd'] ?? 0);

        // Store step result
        $stepResult = [
            'step' => $currentStep,
            'instruction' => $plan[$currentStep] ?? '',
            'task_id' => $task['id'] ?? '',
            'summary' => mb_substr($result, 0, 2000),
            'cost_usd' => $costUsd,
            'completed_at' => time(),
        ];
        $this->projectManager->addStepResult($projectId, $stepResult);

        // Update counters
        $completedSteps = (int) ($project['completed_steps'] ?? 0) + 1;
        $this->projectManager->updateField($projectId, 'completed_steps', $completedSteps);
        $this->projectManager->updateField($projectId, 'current_step', $currentStep + 1);
        $this->projectManager->setCurrentTaskId($projectId, '');
        $this->projectManager->incrementCost($projectId, $costUsd);
        $this->projectManager->resetRetryCount($projectId);

        // Step completed

        $this->logger->info("Project {$projectId}: step {$currentStep} completed (cost: \${$costUsd})");

        // Re-evaluate plan every 3 steps
        if ($completedSteps > 0 && $completedSteps % 3 === 0) {
            $remaining = count($plan) - ($currentStep + 1);
            if ($remaining > 0) {
                $this->reEvaluatePlan($projectId);
            }
        }
    }

    private function handleStepFailure(string $projectId, array $project, string $error): void
    {
        $retryCount = $this->projectManager->incrementRetryCount($projectId);

        if ($retryCount <= 1) {
            // First failure — retry once
            $this->logger->info("Project {$projectId}: step failed, retrying (attempt {$retryCount})");
            $this->projectManager->setCurrentTaskId($projectId, '');
            // Next tick will re-execute the same step
            return;
        }

        // Second failure — stall
        $this->logger->warning("Project {$projectId}: step failed twice, stalling");
        $this->projectManager->updateField($projectId, 'error', $error);
        $this->projectManager->setCurrentTaskId($projectId, '');
        $this->projectManager->transition($projectId, ProjectState::STALLED, $error);
        // Project stalled
    }

    private function reEvaluatePlan(string $projectId): void
    {
        $project = $this->projectManager->getProject($projectId);
        if (!$project) {
            return;
        }

        $goal = $project['goal'] ?? '';
        $plan = json_decode($project['plan'] ?? '[]', true) ?: [];
        $currentStep = (int) ($project['current_step'] ?? 0);
        $completedSummaries = $this->getCompletedSummaries($projectId, $plan);
        $remainingSteps = array_slice($plan, $currentStep);

        $remainingJson = json_encode($remainingSteps, JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
You are re-evaluating a project plan mid-execution.

Goal: {$goal}

{$completedSummaries}

Remaining steps:
{$remainingJson}

Based on what has been completed, should the remaining steps be revised? Respond ONLY with a JSON array of the revised remaining steps. If no changes are needed, return the same steps.
PROMPT;

        $taskId = $this->taskManager->createTask($prompt, null, [
            'source' => 'extraction',
            'model' => 'claude-haiku-4-5-20251001',
            'max_turns' => 1,
            'max_budget_usd' => 0.05,
        ]);

        $this->processManager->executeTask($taskId);

        // Wait for result in a separate coroutine to avoid blocking tick
        \Swoole\Coroutine::create(function () use ($taskId, $projectId, $plan, $currentStep) {
            $maxWait = 30;
            $elapsed = 0;
            while ($elapsed < $maxWait) {
                \Swoole\Coroutine::sleep(1);
                $elapsed++;

                $task = $this->taskManager->getTask($taskId);
                if (!$task) {
                    break;
                }

                if (($task['state'] ?? '') === 'completed') {
                    $result = $task['result'] ?? '';
                    if (preg_match('/\[[\s\S]*\]/', $result, $matches)) {
                        $revisedRemaining = json_decode($matches[0], true);
                        if (is_array($revisedRemaining) && !empty($revisedRemaining)) {
                            $completedPlan = array_slice($plan, 0, $currentStep);
                            $newPlan = array_merge($completedPlan, $revisedRemaining);
                            $this->projectManager->updatePlan($projectId, $newPlan);
                            $this->logger->info("Project {$projectId}: plan re-evaluated, " . count($revisedRemaining) . " remaining steps");
                        }
                    }
                    break;
                }

                if (($task['state'] ?? '') === 'failed') {
                    $this->logger->debug("Plan re-evaluation failed for project {$projectId}");
                    break;
                }
            }
        });
    }

    private function checkSafetyLimits(array $project): ?string
    {
        $completedSteps = (int) ($project['completed_steps'] ?? 0);
        $maxIterations = (int) ($project['max_iterations'] ?? 20);
        $totalCost = (float) ($project['total_cost_usd'] ?? 0);
        $maxBudget = (float) ($project['max_budget_usd'] ?? 10.00);

        if ($completedSteps >= $maxIterations) {
            return "Maximum iterations reached ({$maxIterations})";
        }

        if ($totalCost >= $maxBudget) {
            return "Budget cap reached (\${$maxBudget})";
        }

        return null;
    }

    private function needsCheckpoint(array $project): bool
    {
        $completedSteps = (int) ($project['completed_steps'] ?? 0);
        $checkpointInterval = (int) ($project['checkpoint_interval'] ?? 5);

        return $completedSteps > 0 && $completedSteps % $checkpointInterval === 0;
    }

    // --- Pause/Resume for user replies ---

    public function pauseForReply(string $projectId): void
    {
        $this->projectManager->updateField($projectId, 'waiting_for_reply', '1');
    }

    public function resumeFromReply(string $projectId): void
    {
        $this->projectManager->updateField($projectId, 'waiting_for_reply', '0');
    }

    // --- Helpers ---

    private function getCompletedSummaries(string $projectId, array $plan): string
    {
        $results = $this->projectManager->getStepResults($projectId);
        if (empty($results)) {
            return '';
        }

        $lines = ["## Completed Steps"];
        foreach ($results as $r) {
            $stepNum = (int) ($r['step'] ?? 0) + 1;
            $instruction = $r['instruction'] ?? ($plan[$r['step'] ?? 0] ?? '');
            $summary = $r['summary'] ?? '';
            $lines[] = "### Step {$stepNum}: {$instruction}";
            $lines[] = $summary;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function buildPlanDisplay(array $plan, int $currentStep): string
    {
        $lines = [];
        foreach ($plan as $i => $step) {
            $num = $i + 1;
            if ($i < $currentStep) {
                $lines[] = "{$num}. [DONE] {$step}";
            } elseif ($i === $currentStep) {
                $lines[] = "{$num}. [NOW] {$step}";
            } else {
                $lines[] = "{$num}. [TODO] {$step}";
            }
        }
        return implode("\n", $lines);
    }

    private function stepLabel(int $stepIndex, int $totalSteps): string
    {
        return ($stepIndex + 1) . '/' . $totalSteps;
    }
}
