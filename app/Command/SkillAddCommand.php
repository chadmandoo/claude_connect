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
class SkillAddCommand extends HyperfCommand
{
    protected ?string $name = 'skill:add';

    protected string $description = 'Register an MCP server skill';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('skill_name', InputArgument::REQUIRED, 'The skill name');
        $this->addOption('command', null, InputOption::VALUE_REQUIRED, 'The command to run');
        $this->addOption('args', null, InputOption::VALUE_REQUIRED, 'Comma-separated arguments');
        $this->addOption('scope', null, InputOption::VALUE_OPTIONAL, 'Scope: "global" or a user ID', 'global');
    }

    public function handle(): void
    {
        $name = $this->input->getArgument('skill_name');
        $command = $this->input->getOption('command');
        $argsStr = $this->input->getOption('args');
        $scope = $this->input->getOption('scope');

        if (!$command) {
            $this->error('--command is required');
            return;
        }

        $config = ['command' => $command];

        if ($argsStr) {
            $config['args'] = explode(',', $argsStr);
        }

        $registry = $this->container->get(SkillRegistry::class);

        if ($scope === 'global') {
            $registry->registerGlobal($name, $config);
        } else {
            $registry->registerForUser($scope, $name, $config);
        }

        $this->info("Skill '{$name}' registered in scope '{$scope}'");
        $this->line('Config: ' . json_encode($config, JSON_PRETTY_PRINT));
    }
}
