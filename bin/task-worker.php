#!/usr/bin/env php
<?php
/**
 * External task worker — runs OUTSIDE Swoole, no coroutine hooks.
 * Polls PostgreSQL for pending supervisor tasks, executes Claude CLI, updates results.
 * Uses Redis only for pub/sub notifications.
 *
 * Usage: php bin/task-worker.php
 * Run alongside the Hyperf server as a separate process.
 */

declare(strict_types=1);

$pollInterval = 5; // seconds between polls
$maxParallel = 2;
$redisHost = '127.0.0.1';
$redisPort = 6380;
$prefix = 'cc:';

// Load .env
$envFile = __DIR__ . '/../.env';
$envVars = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $envVars[trim($key)] = trim($val);
    }
}

$cliPath = $envVars['CLAUDE_CLI_PATH'] ?? '/Users/chadpeppers/.local/bin/claude';
$maxTurns = (int) ($envVars['CLAUDE_MAX_TURNS'] ?? 25);
$maxBudget = (float) ($envVars['CLAUDE_MAX_BUDGET_USD'] ?? 5.00);

// Postgres config
$dbHost = $envVars['DB_HOST'] ?? '127.0.0.1';
$dbPort = $envVars['DB_PORT'] ?? '5433';
$dbName = $envVars['DB_DATABASE'] ?? 'claude_connect';
$dbUser = $envVars['DB_USERNAME'] ?? 'claude_connect';
$dbPass = $envVars['DB_PASSWORD'] ?? 'claude_connect';

echo "[worker] Task worker starting (poll every {$pollInterval}s, max parallel {$maxParallel})\n";
echo "[worker] PostgreSQL: {$dbHost}:{$dbPort}/{$dbName}, Redis: {$redisHost}:{$redisPort}, CLI: {$cliPath}\n";

// Connect to PostgreSQL
$dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
echo "[worker] PostgreSQL connected\n";

// Connect to Redis (for pub/sub only)
$redis = new Redis();
$redis->connect($redisHost, $redisPort);
$redis->ping();
echo "[worker] Redis connected (pub/sub only)\n";

$running = []; // pid => ['taskId' => ..., 'pipes' => ..., 'process' => ..., 'stdout' => '', 'stderrFile' => '']

while (true) {
    // Check completed children
    foreach ($running as $pid => $info) {
        $status = proc_get_status($info['process']);
        if (!$status['running']) {
            // Read remaining stdout
            $stdout = $info['stdout'] . stream_get_contents($info['pipes'][1]);
            fclose($info['pipes'][1]);
            $exitCode = proc_close($info['process']);

            $stderr = @file_get_contents($info['stderrFile']) ?: '';
            @unlink($info['stderrFile']);

            $taskId = $info['taskId'];
            $duration = time() - $info['startTime'];

            echo "[worker] Task {$taskId} finished (exit={$exitCode}, stdout=" . strlen($stdout) . "b, {$duration}s)\n";

            if (trim($stdout) === '') {
                // Empty output
                updateTask($pdo, $taskId, [
                    'state' => 'failed',
                    'error' => 'Empty output from Claude CLI (exit=' . $exitCode . ')' . ($stderr ? "\nStderr: " . substr($stderr, 0, 500) : ''),
                    'completed_at' => time(),
                    'updated_at' => time(),
                ]);
                recordConversationTurn($pdo, $taskId, 'failed', 'Task failed: Empty output from Claude CLI (exit=' . $exitCode . ')');
                notifyChannel($pdo, $taskId, 'failed', $info['prompt']);
                publishCompletion($redis, $prefix, $taskId, 'failed');
            } else {
                $parsed = json_decode($stdout, true);
                if ($parsed && ($parsed['type'] ?? '') === 'result') {
                    $result = $parsed['result'] ?? '';
                    $cost = (float) ($parsed['total_cost_usd'] ?? 0);
                    $sessionId = $parsed['session_id'] ?? '';

                    updateTask($pdo, $taskId, [
                        'state' => 'completed',
                        'result' => $result,
                        'cost_usd' => (string) $cost,
                        'claude_session_id' => $sessionId,
                        'completed_at' => time(),
                        'updated_at' => time(),
                    ]);

                    echo "[worker] Task {$taskId} completed (\${$cost})\n";
                    recordConversationTurn($pdo, $taskId, 'completed', $result);
                    notifyChannel($pdo, $taskId, 'completed', $info['prompt'], $result);
                    publishCompletion($redis, $prefix, $taskId, 'completed');
                } elseif ($parsed && ($parsed['is_error'] ?? false)) {
                    $error = $parsed['result'] ?? $parsed['error'] ?? 'Claude returned error';
                    updateTask($pdo, $taskId, [
                        'state' => 'failed',
                        'error' => $error,
                        'completed_at' => time(),
                        'updated_at' => time(),
                    ]);
                    recordConversationTurn($pdo, $taskId, 'failed', 'Task failed: ' . $error);
                    notifyChannel($pdo, $taskId, 'failed', $info['prompt']);
                    publishCompletion($redis, $prefix, $taskId, 'failed');
                } else {
                    // Non-JSON output, treat as success if exit 0
                    if ($exitCode === 0) {
                        updateTask($pdo, $taskId, [
                            'state' => 'completed',
                            'result' => $stdout,
                            'completed_at' => time(),
                            'updated_at' => time(),
                        ]);
                        recordConversationTurn($pdo, $taskId, 'completed', $stdout);
                        notifyChannel($pdo, $taskId, 'completed', $info['prompt'], substr($stdout, 0, 200));
                        publishCompletion($redis, $prefix, $taskId, 'completed');
                    } else {
                        updateTask($pdo, $taskId, [
                            'state' => 'failed',
                            'error' => "Exit code {$exitCode}: " . substr($stdout, 0, 500),
                            'completed_at' => time(),
                            'updated_at' => time(),
                        ]);
                        recordConversationTurn($pdo, $taskId, 'failed', "Task failed with exit code {$exitCode}");
                        notifyChannel($pdo, $taskId, 'failed', $info['prompt']);
                        publishCompletion($redis, $prefix, $taskId, 'failed');
                    }
                }
            }

            unset($running[$pid]);
        } else {
            // Read available stdout without blocking
            $chunk = fread($info['pipes'][1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $running[$pid]['stdout'] .= $chunk;
            }
        }
    }

    // Pick up new tasks if we have capacity
    if (count($running) < $maxParallel) {
        $task = pickPendingTask($pdo);
        if ($task) {
            $taskId = $task['id'];
            $prompt = $task['prompt'] ?? '';
            $options = json_decode($task['options'] ?? '{}', true) ?: [];

            echo "[worker] Starting task {$taskId}: " . substr($prompt, 0, 80) . "\n";

            // Transition to running (already locked by FOR UPDATE SKIP LOCKED)
            updateTask($pdo, $taskId, [
                'state' => 'running',
                'started_at' => time(),
                'updated_at' => time(),
            ]);

            // Build command
            $args = [
                $cliPath,
                '-p', $prompt,
                '--output-format', 'json',
                '--dangerously-skip-permissions',
                '--max-turns', (string) ($options['max_turns'] ?? $maxTurns),
            ];

            $budget = (float) ($options['max_budget_usd'] ?? $maxBudget);
            if ($budget > 0) {
                $args[] = '--max-budget-usd';
                $args[] = (string) $budget;
            }

            $sessionId = $task['session_id'] ?? '';
            if ($sessionId !== '') {
                $args[] = '--resume';
                $args[] = $sessionId;
            }

            // Append system prompt if provided (e.g. architect prompt for manager tasks)
            $appendSystemPrompt = $options['append_system_prompt'] ?? '';
            if ($appendSystemPrompt !== '') {
                $args[] = '--append-system-prompt';
                $args[] = $appendSystemPrompt;
            }

            // Determine working directory
            $cwd = $options['cwd'] ?? null;
            if ($cwd === null || $cwd === '') {
                $cwd = dirname(__DIR__); // project root
            }

            $stderrFile = sys_get_temp_dir() . '/cc_worker_stderr_' . $taskId . '.txt';

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $stderrFile, 'w'],
            ];

            $process = proc_open($args, $descriptors, $pipes, $cwd);

            if (!is_resource($process)) {
                echo "[worker] Failed to start process for task {$taskId}\n";
                updateTask($pdo, $taskId, [
                    'state' => 'failed',
                    'error' => 'Failed to start Claude CLI process',
                    'completed_at' => time(),
                    'updated_at' => time(),
                ]);
                continue;
            }

            fclose($pipes[0]); // close stdin
            stream_set_blocking($pipes[1], false); // non-blocking stdout

            $status = proc_get_status($process);
            $pid = $status['pid'];

            updateTask($pdo, $taskId, ['pid' => $pid]);

            $running[$pid] = [
                'taskId' => $taskId,
                'prompt' => substr($prompt, 0, 100),
                'process' => $process,
                'pipes' => $pipes,
                'stdout' => '',
                'stderrFile' => $stderrFile,
                'startTime' => time(),
            ];

            echo "[worker] Process started pid={$pid}\n";
        }
    }

    sleep($pollInterval);
}

/**
 * Find a pending task with dispatch_mode=supervisor using FOR UPDATE SKIP LOCKED.
 */
function pickPendingTask(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM tasks
            WHERE state = 'pending'
              AND (options::jsonb->>'dispatch_mode') = 'supervisor'
            ORDER BY created_at ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        ");
        $stmt->execute();
        $task = $stmt->fetch();

        if (!$task) {
            $pdo->rollBack();
            return null;
        }

        $pdo->commit();
        return $task;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "[worker] pickPendingTask error: {$e->getMessage()}\n";
        return null;
    }
}

/**
 * Update task fields in PostgreSQL.
 */
function updateTask(PDO $pdo, string $taskId, array $data): void
{
    if (empty($data)) {
        return;
    }

    $sets = [];
    $values = [];
    foreach ($data as $key => $value) {
        $sets[] = "{$key} = ?";
        $values[] = $value;
    }
    $values[] = $taskId;

    $sql = "UPDATE tasks SET " . implode(', ', $sets) . " WHERE id = ?";
    $pdo->prepare($sql)->execute($values);
}

/**
 * Publish task completion to Redis pub/sub so Swoole server picks it up instantly.
 */
function publishCompletion(Redis $redis, string $prefix, string $taskId, string $state): void
{
    $redis->publish($prefix . 'task_completions', json_encode([
        'task_id' => $taskId,
        'state' => $state,
        'timestamp' => time(),
    ]));
}

/**
 * Write the task result directly to the conversation as a turn in PostgreSQL.
 */
function recordConversationTurn(PDO $pdo, string $taskId, string $state, string $content): void
{
    // Get conversation_id from task
    $stmt = $pdo->prepare("SELECT conversation_id, cost_usd, options FROM tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        return;
    }

    $options = json_decode($task['options'] ?? '{}', true) ?: [];
    $conversationId = $options['conversation_id'] ?? $task['conversation_id'] ?? '';

    if ($conversationId === '' || $conversationId === null) {
        return;
    }

    // Insert conversation turn
    $pdo->prepare("
        INSERT INTO conversation_turns (conversation_id, role, content, task_id, cost_usd, created_at)
        VALUES (?, 'assistant', ?, ?, ?, ?)
    ")->execute([
        $conversationId,
        $content,
        $taskId,
        (float) ($task['cost_usd'] ?? 0),
        time(),
    ]);

    // Update conversation metadata
    $pdo->prepare("
        UPDATE conversations
        SET updated_at = ?, turn_count = turn_count + 1
        WHERE id = ?
    ")->execute([time(), $conversationId]);

    // Also store in Redis chat history for API mode context
    // (Uses a direct Redis connection since we already have one for pub/sub)
    global $redis, $prefix;
    $chatMsg = json_encode(['role' => 'assistant', 'content' => $content]);
    $redis->rPush($prefix . "chat_history:{$conversationId}", $chatMsg);
}

/**
 * Post a notification to the #system channel via PostgreSQL.
 */
function notifyChannel(PDO $pdo, string $taskId, string $state, string $prompt, string $result = ''): void
{
    $emoji = match ($state) {
        'completed' => '✅',
        'failed' => '❌',
        default => '📋',
    };

    $shortId = substr($taskId, 0, 8);
    $content = "{$emoji} **Task `{$shortId}`** → **{$state}**\n> " . substr($prompt, 0, 100);
    if ($state === 'completed' && $result !== '') {
        $content .= "\n\n" . substr($result, 0, 300);
    }

    $messageId = uniqid('sys_', true);

    try {
        $pdo->prepare("
            INSERT INTO channel_messages (id, channel_id, author, content, created_at)
            VALUES (?, 'system_channel', 'Worker', ?, ?)
        ")->execute([$messageId, $content, time()]);
    } catch (Throwable $e) {
        echo "[worker] notifyChannel error: {$e->getMessage()}\n";
    }
}
