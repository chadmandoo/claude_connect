<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\AgentManager;
use App\Project\ProjectManager;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class AgentCreateCommand extends HyperfCommand
{
    protected ?string $name = 'agent:create';

    protected string $description = 'Create a new agent';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('slug', InputArgument::REQUIRED, 'Agent slug (used for @mentions)');
        $this->addArgument('agent_name', InputArgument::REQUIRED, 'Display name');
        $this->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'Agent description', '');
        $this->addOption('color', null, InputOption::VALUE_OPTIONAL, 'Hex color (e.g. #6366f1)', '#6366f1');
        $this->addOption('icon', null, InputOption::VALUE_OPTIONAL, 'Icon name (bot, code, wrench, server, briefcase)', 'bot');
        $this->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Model override', '');
        $this->addOption('project', null, InputOption::VALUE_OPTIONAL, 'Link to a project (name or UUID)');
        $this->addOption('system-prompt', 's', InputOption::VALUE_OPTIONAL, 'System prompt text or @path/to/file.md', '');
        $this->addOption('system', null, InputOption::VALUE_NONE, 'Mark as a system agent');
        $this->addOption('default', null, InputOption::VALUE_NONE, 'Set as the default agent');
    }

    public function handle(): void
    {
        $slug = $this->input->getArgument('slug');
        $agentName = $this->input->getArgument('agent_name');
        $description = $this->input->getOption('description') ?: '';
        $color = $this->input->getOption('color') ?: '#6366f1';
        $icon = $this->input->getOption('icon') ?: 'bot';
        $model = $this->input->getOption('model') ?: '';
        $projectRef = $this->input->getOption('project');
        $promptInput = $this->input->getOption('system-prompt') ?: '';
        $isSystem = $this->input->getOption('system');
        $isDefault = $this->input->getOption('default');

        $agentManager = $this->container->get(AgentManager::class);

        // Check for duplicate slug
        $existing = $agentManager->getAgentBySlug($slug);
        if ($existing) {
            $this->error("Agent with slug '{$slug}' already exists (id: {$existing['id']})");
            return;
        }

        // Resolve project
        $projectId = null;
        if ($projectRef) {
            $projectManager = $this->container->get(ProjectManager::class);
            // Try as name first, then as UUID
            $project = $projectManager->getByName($projectRef);
            if (!$project) {
                $project = $projectManager->getProject($projectRef);
            }
            if (!$project) {
                $this->error("Project '{$projectRef}' not found");
                return;
            }
            $projectId = $project['id'];
        }

        // Resolve system prompt — if starts with @ treat as file path
        $systemPrompt = $promptInput;
        if (str_starts_with($promptInput, '@')) {
            $filePath = substr($promptInput, 1);
            if (!file_exists($filePath)) {
                $this->error("Prompt file not found: {$filePath}");
                return;
            }
            $systemPrompt = file_get_contents($filePath);
        }

        $id = $agentManager->createAgent([
            'slug' => $slug,
            'name' => $agentName,
            'description' => $description,
            'system_prompt' => $systemPrompt,
            'model' => $model,
            'project_id' => $projectId,
            'is_system' => $isSystem,
            'is_default' => $isDefault,
            'color' => $color,
            'icon' => $icon,
        ]);

        $this->info("Created agent '{$agentName}' (@{$slug})");
        $this->line("  ID:    {$id}");
        $this->line("  Color: {$color}");
        $this->line("  Icon:  {$icon}");
        if ($model) {
            $this->line("  Model: {$model}");
        }
        if ($projectId) {
            $this->line("  Project: {$projectId}");
        }
        if ($systemPrompt) {
            $this->line("  Prompt: " . mb_substr($systemPrompt, 0, 80) . (mb_strlen($systemPrompt) > 80 ? '...' : ''));
        }
    }
}
