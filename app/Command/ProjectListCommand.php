<?php

declare(strict_types=1);

namespace App\Command;

use App\Project\ProjectManager;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class ProjectListCommand extends HyperfCommand
{
    protected ?string $name = 'project:list';

    protected string $description = 'List all project workspaces';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $manager = $this->container->get(ProjectManager::class);
        $workspaces = $manager->listWorkspaces();

        if (empty($workspaces)) {
            $this->info('No project workspaces found.');
            return;
        }

        $this->info('Project Workspaces:');
        foreach ($workspaces as $p) {
            $name = $p['name'] ?? $p['goal'] ?? 'Unnamed';
            $id = $p['id'] ?? '';
            $cwd = $p['cwd'] ?? '';
            $desc = $p['description'] ?? '';
            $created = date('Y-m-d H:i', (int) ($p['created_at'] ?? 0));

            $this->line("  [{$id}] {$name}");
            if ($desc) {
                $this->line("    Description: {$desc}");
            }
            if ($cwd) {
                $this->line("    CWD: {$cwd}");
            }
            $this->line("    Created: {$created}");
        }
    }
}
