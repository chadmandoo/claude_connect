<?php

declare(strict_types=1);

namespace App\Command;

use App\Cleanup\CleanupAgent;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * CLI command `cleanup:run` to manually trigger the cleanup agent cycle
 * with optional dry-run mode and retention period override.
 */
#[Command]
class CleanupRunCommand extends HyperfCommand
{
    protected ?string $name = 'cleanup:run';

    protected string $description = 'Run the cleanup agent (triage, consolidate, prune old tasks/conversations)';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $days = $this->input->getOption('days');

        $overrides = [];
        if ($days !== null) {
            $days = (int) $days;
            $overrides['retention_days_tasks'] = $days;
            $overrides['retention_days_conversations'] = $days;
            $this->info("Retention override: {$days} days for tasks and conversations");
        }

        if ($dryRun) {
            $this->info('Running cleanup in DRY RUN mode (no deletions will occur)...');
        } else {
            $this->info('Running cleanup...');
        }

        $agent = $this->container->get(CleanupAgent::class);
        $stats = $agent->run(dryRun: $dryRun, configOverrides: $overrides);

        $this->info('');
        $this->info('=== Cleanup Results ===');
        $this->line("  Stale tasks reaped:   {$stats['stale_reaped']}");
        $this->line("  Triaged:              {$stats['triaged']}");
        $this->line("  Consolidated:         {$stats['consolidated']}");
        $this->line("  Pruned tasks:         {$stats['pruned_tasks']}");
        $this->line("  Pruned conversations: {$stats['pruned_conversations']}");
        $this->line("  Pruned threads:       {$stats['pruned_threads']}");
        $this->line("  Pruned memories:      {$stats['pruned_memories']}");
        $this->line('  Budget spent:         $' . number_format($stats['budget_spent'], 4));
        $this->info('======================');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be cleaned up without deleting anything');
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Override retention days for both tasks and conversations');
    }
}
