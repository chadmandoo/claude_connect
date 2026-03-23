<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Claude\ProcessManager;
use App\Epic\EpicManager;
use App\Item\ItemManager;
use App\Item\ItemState;
use App\Memory\MemoryManager;
use App\Project\ProjectManager;
use App\Prompts\PromptLoader;
use App\StateMachine\TaskManager;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Autonomous background agent that polls for assigned work items and executes them via Claude CLI.
 *
 * Builds rich prompts with item, epic, project, and memory context, then monitors task
 * execution with a 10-minute timeout. Transitions items to REVIEW on success or BLOCKED on failure.
 */
class ItemAgent
{
    #[Inject]
    private ItemManager $itemManager;

    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private ProcessManager $processManager;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private EpicManager $epicManager;

    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private PromptLoader $promptLoader;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    private bool $running = false;

    private bool $working = false;

    public function start(): void
    {
        $enabled = (bool) $this->config->get('mcp.item_agent.enabled', false);
        if (!$enabled) {
            $this->logger->info('ItemAgent: disabled by config');

            return;
        }

        $this->running = true;
        $pollInterval = (int) $this->config->get('mcp.item_agent.poll_interval', 10);

        $this->logger->info("ItemAgent: started (poll interval {$pollInterval}s)");

        while ($this->running) {
            \Swoole\Coroutine::sleep($pollInterval);

            if (!$this->running) {
                break;
            }

            if ($this->working) {
                continue; // Already working on an item
            }

            try {
                $this->tick();
            } catch (Throwable $e) {
                $this->logger->error("ItemAgent: tick error: {$e->getMessage()}");
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function tick(): void
    {
        // 1. Look for items IN_PROGRESS assigned to 'agent'
        $assignedItems = $this->itemManager->getAssignedItems('agent');
        $actionable = array_filter($assignedItems, function (array $item) {
            $state = $item['state'] ?? '';

            return $state === ItemState::IN_PROGRESS->value;
        });

        // 2. Auto-assign urgent items if configured
        if (empty($actionable) && (bool) $this->config->get('mcp.item_agent.auto_assign_urgent', false)) {
            $allowedProjects = $this->config->get('mcp.item_agent.allowed_project_ids', []);
            if (!empty($allowedProjects)) {
                foreach ($allowedProjects as $projectId) {
                    $items = $this->itemManager->listItemsByProject($projectId, 'open');
                    foreach ($items as $item) {
                        if (($item['priority'] ?? '') === 'urgent' && ($item['assigned_to'] ?? '') === '') {
                            $this->itemManager->assignItem($item['id'], 'agent');
                            $this->itemManager->transition($item['id'], ItemState::IN_PROGRESS);
                            $this->itemManager->addNote($item['id'], 'Auto-assigned to agent (urgent priority)', 'system');
                            $actionable[] = array_merge($item, ['state' => 'in_progress', 'assigned_to' => 'agent']);
                            break 2; // Only one at a time
                        }
                    }
                }
            }
        }

        if (empty($actionable)) {
            return;
        }

        // Process the first actionable item
        $item = reset($actionable);
        $this->executeItem($item);
    }

    private function executeItem(array $item): void
    {
        $itemId = $item['id'] ?? '';
        $projectId = $item['project_id'] ?? '';
        $maxBudget = (float) $this->config->get('mcp.item_agent.max_budget_per_item', 2.00);

        $this->working = true;

        $this->logger->info("ItemAgent: starting work on item {$itemId}: {$item['title']}");
        $this->itemManager->addNote($itemId, 'Agent started working on this item', 'agent');

        try {
            $prompt = $this->buildPrompt($item);

            $options = [
                'source' => 'item_agent',
                'model' => '',
                'max_turns' => 25,
                'max_budget_usd' => $maxBudget,
            ];

            // Use project CWD if available
            $project = $projectId !== '' ? $this->projectManager->getProject($projectId) : null;
            if ($project && !empty($project['cwd'])) {
                $options['cwd'] = $project['cwd'];
            }

            $taskId = $this->taskManager->createTask($prompt, null, $options);
            $this->processManager->executeTask($taskId);

            // Wait for completion (poll every 5 seconds, max 10 minutes)
            $maxWait = 600;
            $elapsed = 0;
            while ($elapsed < $maxWait) {
                \Swoole\Coroutine::sleep(5);
                $elapsed += 5;

                if (!$this->running) {
                    break;
                }

                $task = $this->taskManager->getTask($taskId);
                if (!$task) {
                    break;
                }

                $taskState = $task['state'] ?? '';

                if ($taskState === 'completed') {
                    $result = $task['result'] ?? '';
                    $cost = (float) ($task['cost_usd'] ?? 0);
                    $truncatedResult = mb_substr($result, 0, 2000);

                    $this->itemManager->addNote(
                        $itemId,
                        "Completed (cost: \${$cost}): {$truncatedResult}",
                        'agent',
                    );
                    $this->itemManager->transition($itemId, ItemState::REVIEW);
                    $this->itemManager->addNote($itemId, 'Moved to REVIEW — ready for human review', 'agent');

                    $this->logger->info("ItemAgent: completed item {$itemId}, cost \${$cost}");
                    break;
                }

                if ($taskState === 'failed') {
                    $error = $task['error'] ?? 'unknown error';
                    $this->itemManager->addNote($itemId, "Agent work failed: {$error}", 'agent');
                    $this->itemManager->transition($itemId, ItemState::BLOCKED);
                    $this->logger->warning("ItemAgent: failed item {$itemId}: {$error}");
                    break;
                }
            }

            if ($elapsed >= $maxWait) {
                $this->itemManager->addNote($itemId, 'Agent work timed out after 10 minutes', 'agent');
                $this->itemManager->transition($itemId, ItemState::BLOCKED);
                $this->logger->warning("ItemAgent: timed out on item {$itemId}");
            }
        } catch (Throwable $e) {
            $this->logger->error("ItemAgent: error on item {$itemId}: {$e->getMessage()}");

            try {
                $this->itemManager->addNote($itemId, "Agent error: {$e->getMessage()}", 'agent');
                $this->itemManager->transition($itemId, ItemState::BLOCKED);
            } catch (Throwable) {
                // Ignore transition errors in error handler
            }
        } finally {
            $this->working = false;
        }
    }

    private function buildPrompt(array $item): string
    {
        $projectId = $item['project_id'] ?? '';
        $epicId = $item['epic_id'] ?? '';
        $userId = $this->config->get('mcp.web.user_id', 'web_user');

        // Load base prompt template
        $template = $this->promptLoader->load('item_agent');
        if ($template === '') {
            $template = $this->getDefaultPrompt();
        }

        // Build context sections
        $itemContext = "## Item\n";
        $itemContext .= "**Title**: {$item['title']}\n";
        $itemContext .= '**Priority**: ' . ($item['priority'] ?? 'normal') . "\n";
        if (!empty($item['description'])) {
            $itemContext .= "**Description**: {$item['description']}\n";
        }

        // Epic context
        $epicContext = '';
        if ($epicId !== '') {
            $epic = $this->epicManager->getEpic($epicId);
            if ($epic) {
                $epicContext .= "\n## Epic Context\n";
                $epicContext .= "**Epic**: {$epic['title']}\n";
                if (!empty($epic['description'])) {
                    $epicContext .= "**Description**: {$epic['description']}\n";
                }
                // List sibling items
                $siblings = $this->itemManager->listItemsByEpic($epicId);
                if (count($siblings) > 1) {
                    $epicContext .= "\n**Other items in this epic**:\n";
                    foreach ($siblings as $sibling) {
                        if (($sibling['id'] ?? '') === ($item['id'] ?? '')) {
                            continue;
                        }
                        $sState = $sibling['state'] ?? 'open';
                        $epicContext .= "- [{$sState}] {$sibling['title']}\n";
                    }
                }
            }
        }

        // Project context
        $projectContext = '';
        if ($projectId !== '') {
            $project = $this->projectManager->getProject($projectId);
            if ($project) {
                $projectContext .= "\n## Project\n";
                $projectContext .= '**Name**: ' . ($project['name'] ?? $projectId) . "\n";
                if (!empty($project['description'])) {
                    $projectContext .= "**Description**: {$project['description']}\n";
                }
                if (!empty($project['cwd'])) {
                    $projectContext .= "**Working Directory**: {$project['cwd']}\n";
                }
            }
        }

        // Memory context
        $memoryContext = '';
        if ($projectId !== '') {
            $memories = $this->memoryManager->getProjectMemories($userId, $projectId, 50);
            if (!empty($memories)) {
                $memoryContext .= "\n## Project Memory\n";
                foreach ($memories as $mem) {
                    $memoryContext .= "- [{$mem['category']}] {$mem['content']}\n";
                }
            }
        }

        // Item notes (previous activity)
        $notesContext = '';
        $notes = $this->itemManager->getNotes($item['id'] ?? '', 10);
        if (!empty($notes)) {
            $notesContext .= "\n## Previous Activity\n";
            foreach ($notes as $note) {
                $notesContext .= "- [{$note['author']}] {$note['content']}\n";
            }
        }

        return str_replace(
            ['{item_context}', '{epic_context}', '{project_context}', '{memory_context}', '{notes_context}'],
            [$itemContext, $epicContext, $projectContext, $memoryContext, $notesContext],
            $template,
        );
    }

    private function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
            # Item Agent

            You are an autonomous agent executing a work item. Complete the described task thoroughly.

            {item_context}
            {epic_context}
            {project_context}
            {memory_context}
            {notes_context}

            ## Instructions
            1. Read and understand the item requirements
            2. Execute the work described in the item
            3. Report what you accomplished clearly
            4. Note any issues or follow-up work needed

            Be thorough but focused. Complete the task as described.
            PROMPT;
    }
}
