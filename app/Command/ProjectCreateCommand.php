<?php

declare(strict_types=1);

namespace App\Command;

use App\Project\ProjectManager;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class ProjectCreateCommand extends HyperfCommand
{
    protected ?string $name = 'project:create';

    protected string $description = 'Create a new persistent project workspace';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('project_name', InputArgument::REQUIRED, 'Project name');
        $this->addOption('cwd', null, InputOption::VALUE_OPTIONAL, 'Working directory for the project');
        $this->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'Project description', '');
    }

    public function handle(): void
    {
        $name = $this->input->getArgument('project_name');
        $cwd = $this->input->getOption('cwd');
        $description = $this->input->getOption('description') ?: '';

        $manager = $this->container->get(ProjectManager::class);

        $existing = $manager->getByName($name);
        if ($existing) {
            $this->error("Project '{$name}' already exists (id: {$existing['id']})");
            return;
        }

        $config = $this->container->get(ConfigInterface::class);
        $userId = $config->get('mcp.web.user_id', 'web_user');

        $projectId = $manager->createWorkspace($name, $description, $userId, $cwd);
        $this->info("Created project '{$name}' (id: {$projectId})");
        if ($cwd) {
            $this->line("  Working directory: {$cwd}");
        }
    }
}
