<?php

declare(strict_types=1);

namespace App\Command;

use App\Skills\SkillRegistry;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class SkillRemoveCommand extends HyperfCommand
{
    protected ?string $name = 'skill:remove';

    protected string $description = 'Remove a registered MCP server skill';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('skill_name', InputArgument::REQUIRED, 'The skill name to remove');
        $this->addOption('scope', null, InputOption::VALUE_OPTIONAL, 'Scope: "global" or a user ID', 'global');
    }

    public function handle(): void
    {
        $name = $this->input->getArgument('skill_name');
        $scope = $this->input->getOption('scope');

        $registry = $this->container->get(SkillRegistry::class);
        $registry->removeSkill($scope, $name);

        $this->info("Skill '{$name}' removed from scope '{$scope}'.");
    }
}
