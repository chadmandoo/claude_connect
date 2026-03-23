<?php

declare(strict_types=1);

namespace App\Command;

use App\Skills\SkillRegistry;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * CLI command `skill:list` to display registered MCP server skills for a given scope.
 */
#[Command]
class SkillListCommand extends HyperfCommand
{
    protected ?string $name = 'skill:list';

    protected string $description = 'List registered MCP server skills';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $scope = $this->input->getOption('scope');
        $registry = $this->container->get(SkillRegistry::class);

        $skills = $registry->listSkills($scope);

        if (empty($skills)) {
            $this->info("No skills registered in scope '{$scope}'.");

            return;
        }

        $this->info("Skills in scope '{$scope}':");
        foreach ($skills as $name => $config) {
            $cmd = $config['command'] ?? 'unknown';
            $args = implode(' ', $config['args'] ?? []);
            $this->line("  {$name}: {$cmd} {$args}");
        }
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('scope', null, InputOption::VALUE_OPTIONAL, 'Scope: "builtin", "global", or a user ID', 'global');
    }
}
