<?php

declare(strict_types=1);

namespace App\Controller;

use App\Storage\PostgresStore;
use App\Storage\RedisStore;
use App\Storage\SwooleTableCache;
use Hyperf\Di\Annotation\Inject;
use Throwable;

/**
 * HTTP health check endpoint that reports Redis, PostgreSQL, and Swoole status
 * along with active task and session counts.
 */
class HealthController
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private RedisStore $redis;

    #[Inject]
    private SwooleTableCache $cache;

    public function index(): array
    {
        try {
            $redisOk = $this->redis->ping();
        } catch (Throwable $e) {
            $redisOk = false;
        }

        try {
            $postgresOk = $this->store->ping();
        } catch (Throwable $e) {
            $postgresOk = false;
        }

        $activeTasks = $this->cache->getActiveTasks();
        $activeSessions = $this->cache->getActiveSessions();

        $healthy = $redisOk && $postgresOk;

        return [
            'status' => $healthy ? 'healthy' : 'degraded',
            'timestamp' => date('c'),
            'checks' => [
                'redis' => $redisOk ? 'ok' : 'error',
                'postgres' => $postgresOk ? 'ok' : 'error',
                'swoole' => 'ok',
            ],
            'stats' => [
                'active_tasks' => count($activeTasks),
                'active_sessions' => count($activeSessions),
                'worker_id' => \Swoole\Coroutine::getCid(),
            ],
        ];
    }
}
