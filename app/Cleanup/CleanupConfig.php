<?php

declare(strict_types=1);

namespace App\Cleanup;

use Hyperf\Contract\ConfigInterface;

/**
 * Immutable configuration for the cleanup agent, sourced from Hyperf config with optional overrides.
 *
 * Defines retention periods, batch sizes, budget limits, and stale task timeout thresholds.
 */
class CleanupConfig
{
    public readonly bool $enabled;

    public readonly int $interval;

    public readonly int $retentionDaysTasks;

    public readonly int $retentionDaysConversations;

    public readonly int $batchSize;

    public readonly float $maxBudgetUsd;

    public readonly float $haikuCallBudgetUsd;

    public readonly int $maxItemsPerRun;

    public readonly int $staleTaskTimeoutSeconds;

    public function __construct(ConfigInterface $config, array $overrides = [])
    {
        $this->enabled = (bool) $config->get('mcp.cleanup.enabled', true);
        $this->interval = (int) $config->get('mcp.cleanup.interval', 21600);
        $this->retentionDaysTasks = (int) ($overrides['retention_days_tasks'] ?? $config->get('mcp.cleanup.retention_days_tasks', 7));
        $this->retentionDaysConversations = (int) ($overrides['retention_days_conversations'] ?? $config->get('mcp.cleanup.retention_days_conversations', 14));
        $this->batchSize = (int) $config->get('mcp.cleanup.batch_size', 15);
        $this->maxBudgetUsd = (float) $config->get('mcp.cleanup.max_budget_usd', 0.50);
        $this->haikuCallBudgetUsd = (float) $config->get('mcp.cleanup.haiku_call_budget_usd', 0.05);
        $this->maxItemsPerRun = (int) $config->get('mcp.cleanup.max_items_per_run', 200);
        $this->staleTaskTimeoutSeconds = (int) $config->get('mcp.cleanup.stale_task_timeout', 5400); // 90 minutes
    }

    public function retentionCutoffTasks(): int
    {
        return time() - ($this->retentionDaysTasks * 86400);
    }

    public function retentionCutoffConversations(): int
    {
        return time() - ($this->retentionDaysConversations * 86400);
    }
}
