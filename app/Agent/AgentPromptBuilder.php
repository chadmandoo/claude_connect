<?php

declare(strict_types=1);

namespace App\Agent;

use App\Memory\MemoryManager;
use App\Project\ProjectManager;
use App\StateMachine\TaskManager;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;

/**
 * Builds composite system prompts for agents by combining the agent's base prompt
 * with contextual data such as project lists, recent tasks, user memory, and available agents.
 */
class AgentPromptBuilder
{
    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private AgentManager $agentManager;

    #[Inject]
    private LoggerInterface $logger;

    /**
     * Build the full system prompt for an agent.
     *
     * Combines: agent's static system_prompt + date + project list + recent tasks
     * + user memory + agent memory + available agents awareness block.
     */
    public function build(array $agent, string $userId, string $currentPrompt = '', ?string $projectId = null): string
    {
        $parts = [];

        // Base agent prompt
        $systemPrompt = $agent['system_prompt'] ?? '';
        if ($systemPrompt !== '') {
            $parts[] = $systemPrompt;
        }

        // Current date
        $parts[] = "\n## Current Date\n" . date('Y-m-d H:i T');

        // Active projects
        $projects = $this->projectManager->listWorkspaces();
        if (!empty($projects)) {
            $projectList = [];
            foreach ($projects as $p) {
                $name = $p['name'] ?? 'Unnamed';
                $id = $p['id'] ?? '';
                $desc = $p['description'] ?? '';
                $cwd = $p['cwd'] ?? '';
                $line = "- **{$name}** (id: `{$id}`)";
                if ($desc !== '') {
                    $line .= " — {$desc}";
                }
                if ($cwd !== '') {
                    $line .= " [cwd: `{$cwd}`]";
                }
                $projectList[] = $line;
            }
            $parts[] = "\n## Active Projects\n" . implode("\n", $projectList);
        }

        // Recent task summaries
        $recentTasks = $this->taskManager->listTasks(null, 5);
        $supervisorTasks = array_filter($recentTasks, function ($t) {
            $options = json_decode($t['options'] ?? '{}', true);

            return ($options['dispatch_mode'] ?? '') === 'supervisor';
        });

        if (!empty($supervisorTasks)) {
            $taskLines = [];
            foreach ($supervisorTasks as $t) {
                $state = $t['state'] ?? '';
                $prompt = mb_substr($t['prompt'] ?? '', 0, 80);
                $id = $t['id'] ?? '';
                $cost = $t['cost_usd'] ?? '0';
                $taskLines[] = "- [{$state}] `{$id}`: {$prompt}" . ($cost > 0 ? " (\${$cost})" : '');
            }
            $parts[] = "\n## Recent Background Tasks\n" . implode("\n", $taskLines);
        }

        // User memory context (scoped to project + agent)
        $agentId = $agent['id'] ?? null;
        if ($userId !== '') {
            if ($projectId !== null && $projectId !== '' && $projectId !== 'general') {
                $memoryContext = $this->memoryManager->buildScopedContext($userId, $currentPrompt, $projectId, $agentId);
            } else {
                $memoryContext = $this->memoryManager->buildSystemPromptContext($userId, $currentPrompt, $agentId);
            }
            if ($memoryContext !== '') {
                $parts[] = "\n" . $memoryContext;
            }
        }

        // Available agents awareness
        $allAgents = $this->agentManager->listAgents();
        $currentSlug = $agent['slug'] ?? '';
        $otherAgents = array_filter($allAgents, fn ($a) => ($a['slug'] ?? '') !== $currentSlug);
        if (!empty($otherAgents)) {
            $agentLines = [];
            foreach ($otherAgents as $a) {
                $slug = $a['slug'] ?? '';
                $name = $a['name'] ?? '';
                $desc = $a['description'] ?? '';
                $agentLines[] = "- **{$name}** (`@{$slug}`) — {$desc}";
            }
            $parts[] = "\n## Available Agents\nYou can suggest the user talk to another agent if their request is outside your expertise:\n" . implode("\n", $agentLines);
        }

        return implode("\n", $parts);
    }

    /**
     * Build system prompt for a CLI task given an agent_id from task options.
     * Falls back to legacy agent_type behavior if no agent found.
     */
    public function buildForTask(string $agentId, string $userId, string $prompt, string $projectId = 'general'): ?string
    {
        $agent = $this->agentManager->getAgent($agentId);
        if ($agent === null) {
            return null;
        }

        return $this->build($agent, $userId, $prompt, $projectId !== 'general' ? $projectId : null);
    }
}
