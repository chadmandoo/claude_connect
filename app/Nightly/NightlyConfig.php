<?php

declare(strict_types=1);

namespace App\Nightly;

use Hyperf\Contract\ConfigInterface;

class NightlyConfig
{
    public readonly bool $enabled;
    public readonly int $runHour;
    public readonly int $runMinute;
    public readonly float $maxBudgetUsd;
    public readonly float $haikuCallBudgetUsd;
    public readonly int $batchSize;
    public readonly int $summarizationThreshold;
    public readonly float $similarityThreshold;
    public readonly int $stalenessThresholdDays;
    public readonly float $stalenessConfidenceThreshold;

    public function __construct(ConfigInterface $config)
    {
        $this->enabled = (bool) $config->get('mcp.nightly.enabled', true);
        $this->runHour = (int) $config->get('mcp.nightly.run_hour', 2);
        $this->runMinute = (int) $config->get('mcp.nightly.run_minute', 0);
        $this->maxBudgetUsd = (float) $config->get('mcp.nightly.max_budget_usd', 1.00);
        $this->haikuCallBudgetUsd = (float) $config->get('mcp.nightly.haiku_call_budget_usd', 0.05);
        $this->batchSize = (int) $config->get('mcp.nightly.batch_size', 20);
        $this->summarizationThreshold = (int) $config->get('mcp.nightly.summarization_threshold', 50);
        $this->similarityThreshold = (float) $config->get('mcp.nightly.similarity_threshold', 0.85);
        $this->stalenessThresholdDays = (int) $config->get('mcp.nightly.staleness_threshold_days', 30);
        $this->stalenessConfidenceThreshold = (float) $config->get('mcp.nightly.staleness_confidence_threshold', 0.7);
    }

    /**
     * Check if the current time matches the configured run window (within 60s).
     */
    public function shouldRunNow(): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Chicago'));
        $hour = (int) $now->format('G');
        $minute = (int) $now->format('i');

        return $hour === $this->runHour && $minute === $this->runMinute;
    }
}
