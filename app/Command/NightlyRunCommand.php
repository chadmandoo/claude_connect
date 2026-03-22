<?php

declare(strict_types=1);

namespace App\Command;

use App\Nightly\NightlyConsolidationAgent;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class NightlyRunCommand extends HyperfCommand
{
    protected ?string $name = 'nightly:run';

    protected string $description = 'Run the nightly memory consolidation agent (validate, deduplicate, summarize)';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be done without making changes');
        $this->addOption('skip-validation', null, InputOption::VALUE_NONE, 'Skip the project expert validation phase');
        $this->addOption('skip-dedup', null, InputOption::VALUE_NONE, 'Skip the similarity deduplication phase');
        $this->addOption('skip-summarization', null, InputOption::VALUE_NONE, 'Skip the summarization phase');
    }

    public function handle(): void
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $skipValidation = (bool) $this->input->getOption('skip-validation');
        $skipDedup = (bool) $this->input->getOption('skip-dedup');
        $skipSummarization = (bool) $this->input->getOption('skip-summarization');

        if ($dryRun) {
            $this->info('Running nightly consolidation in DRY RUN mode (no changes will be made)...');
        } else {
            $this->info('Running nightly memory consolidation...');
        }

        $skipped = [];
        if ($skipValidation) $skipped[] = 'validation';
        if ($skipDedup) $skipped[] = 'dedup';
        if ($skipSummarization) $skipped[] = 'summarization';
        if (!empty($skipped)) {
            $this->info('Skipping phases: ' . implode(', ', $skipped));
        }

        $agent = $this->container->get(NightlyConsolidationAgent::class);
        $stats = $agent->run(
            dryRun: $dryRun,
            skipValidation: $skipValidation,
            skipDedup: $skipDedup,
            skipSummarization: $skipSummarization,
        );

        $this->info('');
        $this->info('=== Nightly Consolidation Results ===');
        $this->line("  Backfilled embeddings: {$stats['backfilled']}");
        $this->line("  Memories validated:    {$stats['validated']}");
        $this->line("  Stale/inaccurate removed: {$stats['removed_stale']}");
        $this->line("  Duplicates merged:     {$stats['merged']}");
        $this->line("  Memories summarized:   {$stats['summarized']}");
        $this->line("  Orphan vectors cleaned: {$stats['orphans_cleaned']}");
        $this->line("  Haiku budget spent:    $" . number_format($stats['haiku_budget'], 4));
        $this->line("  Voyage budget spent:   $" . number_format($stats['voyage_budget'], 4));
        $this->info('=====================================');
    }
}
