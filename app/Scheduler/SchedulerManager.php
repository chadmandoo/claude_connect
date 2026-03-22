<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;

/**
 * Manages scheduled jobs stored in PostgreSQL. Each job has:
 * - id, name, description
 * - schedule (cron-like: interval_seconds or hour:minute for daily)
 * - enabled (bool)
 * - handler (class or callback identifier)
 * - last_run, next_run, last_result, run_count
 */
class SchedulerManager
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private LoggerInterface $logger;

    /**
     * Register default scheduled jobs (called on startup).
     */
    public function registerDefaults(array $config): void
    {
        $defaults = [
            [
                'id' => 'nightly_consolidation',
                'name' => 'Nightly Consolidation',
                'description' => 'Backfill embeddings, deduplicate memories, validate against codebase',
                'schedule_type' => 'daily',
                'schedule_hour' => (int) ($config['nightly']['run_hour'] ?? 2),
                'schedule_minute' => (int) ($config['nightly']['run_minute'] ?? 0),
                'enabled' => (bool) ($config['nightly']['enabled'] ?? true),
                'handler' => 'nightly',
            ],
            [
                'id' => 'cleanup',
                'name' => 'Cleanup & Pruning',
                'description' => 'Reap stale tasks, classify old items, prune ephemeral data',
                'schedule_type' => 'interval',
                'schedule_seconds' => (int) ($config['cleanup']['interval'] ?? 21600),
                'enabled' => (bool) ($config['cleanup']['enabled'] ?? true),
                'handler' => 'cleanup',
            ],
            [
                'id' => 'supervisor_health',
                'name' => 'Supervisor Health Check',
                'description' => 'Check running tasks for stalls, update statuses, nudge stuck processes',
                'schedule_type' => 'interval',
                'schedule_seconds' => 60,
                'enabled' => true,
                'handler' => 'supervisor_health',
            ],
            [
                'id' => 'memory_sync',
                'name' => 'Memory Sync',
                'description' => 'Sync and organize agent memory, merge duplicates',
                'schedule_type' => 'interval',
                'schedule_seconds' => 3600,
                'enabled' => false,
                'handler' => 'memory_sync',
            ],
            [
                'id' => 'database_backup',
                'name' => 'Database Backup',
                'description' => 'Daily pg_dump backup of the PostgreSQL database with automatic pruning',
                'schedule_type' => 'daily',
                'schedule_hour' => (int) ($config['backup']['run_hour'] ?? 3),
                'schedule_minute' => (int) ($config['backup']['run_minute'] ?? 0),
                'enabled' => (bool) ($config['backup']['enabled'] ?? true),
                'handler' => 'database_backup',
            ],
        ];

        foreach ($defaults as $job) {
            // Only register if not already in Redis (preserve user toggles)
            $existing = $this->store->getScheduledJob($job['id']);
            if (!$existing) {
                $job['last_run'] = 0;
                $job['next_run'] = $this->calculateNextRun($job);
                $job['last_result'] = '';
                $job['last_duration'] = 0;
                $job['run_count'] = 0;
                $job['created_at'] = time();
                $this->store->saveScheduledJob($job);
                $this->logger->info("Scheduler: registered default job '{$job['id']}'");
            }
        }
    }

    public function listJobs(): array
    {
        return $this->store->getScheduledJobs();
    }

    public function getJob(string $id): ?array
    {
        return $this->store->getScheduledJob($id);
    }

    public function toggleJob(string $id, bool $enabled): bool
    {
        $job = $this->store->getScheduledJob($id);
        if (!$job) {
            return false;
        }
        $job['enabled'] = $enabled ? '1' : '0';
        if ($enabled) {
            $job['next_run'] = $this->calculateNextRun($job);
        }
        $this->store->saveScheduledJob($job);
        return true;
    }

    public function getDueJobs(): array
    {
        $jobs = $this->listJobs();
        $now = time();
        $due = [];

        foreach ($jobs as $job) {
            if (($job['enabled'] ?? '0') !== '1' && ($job['enabled'] ?? false) !== true) {
                continue;
            }
            $nextRun = (int) ($job['next_run'] ?? 0);
            if ($nextRun > 0 && $nextRun <= $now) {
                $due[] = $job;
            }
        }

        return $due;
    }

    public function markRun(string $id, string $result, int $duration): void
    {
        $job = $this->store->getScheduledJob($id);
        if (!$job) {
            return;
        }
        $job['last_run'] = time();
        $job['last_result'] = mb_substr($result, 0, 500);
        $job['last_duration'] = $duration;
        $job['run_count'] = ((int) ($job['run_count'] ?? 0)) + 1;
        $job['next_run'] = $this->calculateNextRun($job);
        $this->store->saveScheduledJob($job);
    }

    public function calculateNextRun(array $job): int
    {
        $type = $job['schedule_type'] ?? 'interval';

        if ($type === 'daily') {
            $hour = (int) ($job['schedule_hour'] ?? 0);
            $minute = (int) ($job['schedule_minute'] ?? 0);
            $today = mktime($hour, $minute, 0);
            return $today > time() ? $today : $today + 86400;
        }

        // Interval-based
        $seconds = (int) ($job['schedule_seconds'] ?? 3600);
        $lastRun = (int) ($job['last_run'] ?? 0);
        return $lastRun > 0 ? $lastRun + $seconds : time() + 30; // First run 30s after registration
    }

    public function createJob(array $data): string
    {
        $id = $data['id'] ?? 'job_' . bin2hex(random_bytes(4));
        $job = [
            'id' => $id,
            'name' => $data['name'] ?? $id,
            'description' => $data['description'] ?? '',
            'schedule_type' => $data['schedule_type'] ?? 'interval',
            'schedule_seconds' => (int) ($data['schedule_seconds'] ?? 3600),
            'schedule_hour' => (int) ($data['schedule_hour'] ?? 0),
            'schedule_minute' => (int) ($data['schedule_minute'] ?? 0),
            'enabled' => $data['enabled'] ?? true,
            'handler' => $data['handler'] ?? 'custom',
            'last_run' => 0,
            'next_run' => 0,
            'last_result' => '',
            'last_duration' => 0,
            'run_count' => 0,
            'created_at' => time(),
        ];
        $job['next_run'] = $this->calculateNextRun($job);
        $this->store->saveScheduledJob($job);
        return $id;
    }

    public function deleteJob(string $id): void
    {
        $this->store->deleteScheduledJob($id);
    }
}
