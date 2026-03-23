<?php

declare(strict_types=1);

namespace App\Command;

use App\Memory\MemoryManager;
use App\Storage\PostgresStore;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * CLI command `memory:list` to display stored memory facts and recent conversation log for a given user.
 */
#[Command]
class MemoryListCommand extends HyperfCommand
{
    protected ?string $name = 'memory:list';

    protected string $description = 'List stored memory facts for a user';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $userId = $this->input->getArgument('user_id');
        $memoryManager = $this->container->get(MemoryManager::class);

        $facts = $memoryManager->getFacts($userId);

        if (empty($facts)) {
            $this->info("No memory facts for user {$userId}.");

            return;
        }

        $this->info("Memory facts for {$userId}:");
        foreach ($facts as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        $store = $this->container->get(PostgresStore::class);
        $log = $store->getMemoryLog($userId, 10);

        if (!empty($log)) {
            $this->info("\nRecent conversation log:");
            foreach ($log as $entry) {
                $this->line("  - {$entry}");
            }
        }
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('user_id', InputArgument::REQUIRED, 'The user ID');
    }
}
