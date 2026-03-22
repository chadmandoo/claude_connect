<?php

declare(strict_types=1);

namespace App\Command;

use App\Memory\MemoryManager;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

#[Command]
class MemorySetCommand extends HyperfCommand
{
    protected ?string $name = 'memory:set';

    protected string $description = 'Set a memory fact for a user';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('user_id', InputArgument::REQUIRED, 'The user ID');
        $this->addArgument('key', InputArgument::REQUIRED, 'The fact key');
        $this->addArgument('value', InputArgument::REQUIRED, 'The fact value');
    }

    public function handle(): void
    {
        $userId = $this->input->getArgument('user_id');
        $key = $this->input->getArgument('key');
        $value = $this->input->getArgument('value');

        $memoryManager = $this->container->get(MemoryManager::class);
        $memoryManager->remember($userId, $key, $value);

        $this->info("Set memory fact for {$userId}: {$key} = {$value}");
    }
}
