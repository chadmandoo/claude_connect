<?php

declare(strict_types=1);

namespace App\Claude;

use App\Agent\AgentManager;
use App\Agent\AgentPromptBuilder;
use App\Conversation\ConversationManager;
use App\Epic\EpicManager;
use App\Item\ItemManager;
use App\Memory\MemoryManager;
use App\Project\ProjectManager;
use App\Prompts\PromptLoader;
use App\Skills\McpConfigGenerator;
use App\Skills\SkillRegistry;
use App\StateMachine\TaskManager;
use App\StateMachine\TaskState;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function Hyperf\Support\env;

/**
 * Central abstraction for executing Claude CLI processes within Swoole coroutines.
 *
 * Builds CLI commands with proper arguments, manages environment variables,
 * handles process lifecycle (execution, timeout, retry), and parses output
 * into task results with memory/work-item extraction.
 */
class ProcessManager
{
    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private OutputParser $outputParser;

    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private ItemManager $itemManager;

    #[Inject]
    private EpicManager $epicManager;

    #[Inject]
    private PromptLoader $promptLoader;

    #[Inject]
    private AgentPromptBuilder $agentPromptBuilder;

    #[Inject]
    private AgentManager $agentManager;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private SkillRegistry $skillRegistry;

    #[Inject]
    private McpConfigGenerator $mcpConfigGenerator;

    #[Inject]
    private ConversationManager $conversationManager;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    /**
     * Execute a Claude CLI task asynchronously in a Swoole coroutine.
     */
    public function executeTask(string $taskId): void
    {
        \Swoole\Coroutine::create(function () use ($taskId) {
            try {
                $this->runTask($taskId);
            } catch (Throwable $e) {
                $this->logger->error("Task {$taskId} failed: {$e->getMessage()}");

                try {
                    $this->taskManager->setTaskError($taskId, $e->getMessage());
                    $this->taskManager->transition($taskId, TaskState::FAILED);
                } catch (Throwable) {
                    // Best effort
                }
            }
        });
    }

    /**
     * Execute a task with external callbacks for streaming progress and completion.
     * Spawns a coroutine; callbacks are invoked from within that coroutine.
     */
    public function executeTaskWithCallbacks(string $taskId, ?callable $onStderrChunk = null, ?callable $onComplete = null): void
    {
        \Swoole\Coroutine::create(function () use ($taskId, $onStderrChunk, $onComplete) {
            try {
                $this->runTask($taskId, $onStderrChunk);
                if ($onComplete !== null) {
                    $task = $this->taskManager->getTask($taskId);
                    $onComplete($task);
                }
            } catch (Throwable $e) {
                $this->logger->error("Task {$taskId} failed: {$e->getMessage()}");

                try {
                    $this->taskManager->setTaskError($taskId, $e->getMessage());
                    $this->taskManager->transition($taskId, TaskState::FAILED);
                } catch (Throwable) {
                    // Best effort
                }
                if ($onComplete !== null) {
                    $task = $this->taskManager->getTask($taskId);
                    $onComplete($task);
                }
            }
        });
    }

    /**
     * Continue a conversation from a completed/failed task by resuming its Claude session.
     * Creates a new task linked to the parent, returns the new task ID.
     */
    public function continueTask(string $parentTaskId, string $prompt, array $options = []): string
    {
        $parentTask = $this->taskManager->getTask($parentTaskId);
        if (!$parentTask) {
            throw new RuntimeException("Parent task {$parentTaskId} not found");
        }

        $claudeSessionId = $parentTask['claude_session_id'] ?? '';
        if ($claudeSessionId === '') {
            throw new RuntimeException("Parent task {$parentTaskId} has no claude_session_id to resume");
        }

        // Create new task with the claude session ID as session_id (so buildCommand adds --resume)
        $taskId = $this->taskManager->createTask($prompt, $claudeSessionId, $options);
        $this->taskManager->setParentTaskId($taskId, $parentTaskId);

        $this->executeTask($taskId);

        return $taskId;
    }

    /**
     * Continue a conversation with streaming callbacks. Returns the new task ID.
     */
    public function continueTaskWithCallbacks(string $parentTaskId, string $prompt, array $options = [], ?callable $onStderrChunk = null, ?callable $onComplete = null): string
    {
        $parentTask = $this->taskManager->getTask($parentTaskId);
        if (!$parentTask) {
            throw new RuntimeException("Parent task {$parentTaskId} not found");
        }

        $claudeSessionId = $parentTask['claude_session_id'] ?? '';
        if ($claudeSessionId === '') {
            throw new RuntimeException("Parent task {$parentTaskId} has no claude_session_id to resume");
        }

        $taskId = $this->taskManager->createTask($prompt, $claudeSessionId, $options);
        $this->taskManager->setParentTaskId($taskId, $parentTaskId);

        $this->executeTaskWithCallbacks($taskId, $onStderrChunk, $onComplete);

        return $taskId;
    }

    private function runTask(string $taskId, ?callable $externalStderrCallback = null): void
    {
        $task = $this->taskManager->getTask($taskId);
        if (!$task) {
            throw new RuntimeException("Task {$taskId} not found");
        }

        $this->taskManager->transition($taskId, TaskState::RUNNING);

        $options = json_decode($task['options'] ?? '{}', true) ?: [];
        $userId = $options['web_user_id'] ?? '';

        // Handle uploaded images: save as temp files and prepend to prompt
        $uploadTempFiles = [];
        if (!empty($options['images']) && is_array($options['images'])) {
            $imageRefs = [];
            foreach ($options['images'] as $i => $img) {
                $ext = match ($img['media_type'] ?? '') {
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
                $tmpPath = "/tmp/cc_upload_{$taskId}_{$i}.{$ext}";
                $decoded = base64_decode($img['data'] ?? '', true);
                if ($decoded !== false) {
                    file_put_contents($tmpPath, $decoded);
                    $uploadTempFiles[] = $tmpPath;
                    $imageRefs[] = $tmpPath;
                }
            }
            if (!empty($imageRefs)) {
                $task['prompt'] = 'Image files attached: ' . implode(', ', $imageRefs) . "\n\n" . ($task['prompt'] ?? '');
            }
            // Remove images from options to avoid storing large base64 in Redis
            unset($options['images']);
            $task['options'] = json_encode($options);
        }

        // Inject agent-aware system prompt with scoped memory
        $prompt = $task['prompt'] ?? '';
        $taskAgentId = $options['agent_id'] ?? '';
        $agentType = $options['agent_type'] ?? '';
        $projectId = $options['project_id'] ?? 'general';

        // Build system prompt from agent
        if ($taskAgentId !== '' && $userId !== '') {
            $builtPrompt = $this->agentPromptBuilder->buildForTask($taskAgentId, $userId, $prompt, $projectId);
            if ($builtPrompt !== null) {
                $options['append_system_prompt'] = $builtPrompt;
            }
        }

        // Fallback: use default agent or generic prompt
        if (empty($options['append_system_prompt'])) {
            if ($userId !== '') {
                $defaultAgent = $this->agentManager->getDefaultAgent();
                if (!empty($defaultAgent)) {
                    $options['append_system_prompt'] = $this->agentPromptBuilder->build($defaultAgent, $userId, $prompt, $projectId !== 'general' ? $projectId : null);
                } else {
                    $memoryContext = $this->memoryManager->buildSystemPromptContext($userId, $prompt);
                    $options['append_system_prompt'] = $this->promptLoader->buildGenericPrompt($memoryContext);
                }
            } else {
                $options['append_system_prompt'] = $this->promptLoader->buildGenericPrompt('');
            }
        }
        $task['options'] = json_encode($options);
        $this->taskManager->updateTaskOptions($taskId, $options);

        // Generate MCP config if user has skills
        $mcpConfigPath = null;
        if ($userId !== '') {
            $skills = $this->skillRegistry->getSkillsForUser($userId);

            // Add cc-system MCP server with task context
            $skills['cc-system'] = [
                'command' => 'php',
                'args' => [BASE_PATH . '/bin/cc-system-mcp.php'],
                'env' => [
                    'CC_USER_ID' => $userId,
                    'CC_TASK_ID' => $taskId,
                    'CC_PROJECT_ID' => $projectId,
                    'CC_REDIS_HOST' => '127.0.0.1',
                    'CC_REDIS_PORT' => '6380',
                ],
            ];

            if (!empty($skills)) {
                $mcpConfigPath = $this->mcpConfigGenerator->generateForTask($taskId, $skills);
                if ($mcpConfigPath !== null) {
                    $options['mcp_config'] = $mcpConfigPath;
                    $task['options'] = json_encode($options);
                }
            }
        }

        $command = $this->buildCommand($task);
        $env = $this->buildEnvironment();

        $this->logger->info("Executing task {$taskId}: " . implode(' ', $command));

        // Use project cwd if no explicit cwd and agent is project-scoped
        $cwd = $options['cwd'] ?? null;
        if ($cwd === null && ($options['agent_type'] ?? '') === 'project' && ($options['project_id'] ?? 'general') !== 'general') {
            $proj = $this->projectManager->getProject($options['project_id']);
            if ($proj && !empty($proj['cwd'])) {
                $cwd = $proj['cwd'];
            }
        }

        // Build shell command with proper escaping
        $shellCommand = implode(' ', array_map('escapeshellarg', $command));

        // Export env vars into the command prefix (keys must NOT be quoted for shell assignment)
        $envPrefix = '';
        foreach ($env as $key => $val) {
            // Only allow safe env var names
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                $envPrefix .= $key . '=' . escapeshellarg($val) . ' ';
            }
        }

        // Redirect stderr to a temp file so we can capture both streams
        $stderrFile = sys_get_temp_dir() . '/cc_stderr_' . $taskId . '.txt';
        $fullCommand = $envPrefix . $shellCommand . ' 2>' . escapeshellarg($stderrFile);

        if ($cwd !== null) {
            $fullCommand = 'cd ' . escapeshellarg($cwd) . ' && ' . $fullCommand;
        }

        $this->logger->info("Task {$taskId} shell command: {$fullCommand}");

        $timeout = (int) $this->config->get('mcp.claude.process_timeout', 0);
        $taskStartTime = time();

        // Use Swoole\Coroutine\System::exec which works properly in coroutines
        $result = \Swoole\Coroutine\System::exec($fullCommand, true); // sast-ignore: this IS the centralized process execution abstraction layer

        $stdout = $result['output'] ?? '';
        $exitCode = $result['code'] ?? -1;
        $stderr = @file_get_contents($stderrFile) ?: '';
        @unlink($stderrFile);

        // Fire stderr callback if we have stderr content
        if ($stderr !== '' && $externalStderrCallback !== null) {
            $stderrLineCount = substr_count($stderr, "\n");
            $elapsed = time() - $taskStartTime;
            $externalStderrCallback($stderr, $elapsed, $stderrLineCount);
        }

        // Cleanup MCP config and uploaded temp files
        $this->cleanupMcpConfig($taskId, $mcpConfigPath);
        foreach ($uploadTempFiles as $tmpFile) {
            @unlink($tmpFile);
        }

        $timedOut = false;
        if ($timeout > 0 && (time() - $taskStartTime) >= $timeout) {
            $timedOut = true;
        }

        if ($timedOut) {
            $this->logger->warning("Task {$taskId} timed out after {$timeout}s");
            $this->taskManager->setTaskError($taskId, "Task timed out after {$timeout} seconds");
            $this->taskManager->transition($taskId, TaskState::FAILED);

            return;
        }

        $this->logger->info("Task {$taskId} completed with exit code {$exitCode}, stdout=" . strlen($stdout) . ' bytes, stderr=' . strlen($stderr) . ' bytes');
        if (strlen($stdout) === 0) {
            $this->logger->warning("Task {$taskId}: empty stdout! stderr preview: " . mb_substr($stderr, 0, 500));
        }

        $parsed = $this->outputParser->parse($stdout, $exitCode);

        if ($parsed->success) {
            if ($parsed->sessionId) {
                $this->taskManager->setClaudeSessionId($taskId, $parsed->sessionId);
            }

            // Extract and store in-conversation memory tags, then use cleaned result
            $resultText = $parsed->result;
            if ($userId !== '') {
                $extracted = OutputParser::extractMemoryTags($resultText);
                foreach ($extracted['memories'] as $mem) {
                    $this->memoryManager->storeMemory(
                        $userId,
                        $mem['category'],
                        $mem['content'],
                        $mem['importance'],
                        'inline',
                    );
                }
                $resultText = $extracted['cleaned'];
            }

            // Extract and create inline work item tags
            $projectId = $options['project_id'] ?? 'general';
            $conversationId = $options['conversation_id'] ?? '';
            if ($projectId !== 'general') {
                $workItemExtracted = OutputParser::extractWorkItemTags($resultText);
                foreach ($workItemExtracted['items'] as $wi) {
                    try {
                        $epicId = null;
                        if ($wi['epic'] !== '') {
                            $epicId = $this->findOrCreateEpicByName($projectId, $wi['epic']);
                        }
                        $this->itemManager->createItem(
                            $projectId,
                            $wi['title'],
                            $epicId,
                            $wi['description'],
                            $wi['priority'],
                            $conversationId,
                        );
                        $this->logger->info("Created work item from inline tag: {$wi['title']}");
                    } catch (Throwable $e) {
                        $this->logger->warning("Failed to create inline work item: {$e->getMessage()}");
                    }
                }
                $resultText = $workItemExtracted['cleaned'];
            }

            $this->taskManager->setTaskResult($taskId, $resultText);
            $this->taskManager->setTaskCost($taskId, $parsed->costUsd);

            // Store images if present
            if (!empty($parsed->images)) {
                $this->taskManager->setTaskImages($taskId, $parsed->images);
            }

            $this->taskManager->transition($taskId, TaskState::COMPLETED);
        } else {
            $error = $parsed->error ?? 'Unknown error';
            $fullError = $error . ($stderr ? "\nStderr: " . $stderr : '');

            // Auto-recover: if resume failed because session not found, retry as fresh session with conversation history
            $sessionId = $task['session_id'] ?? '';
            $conversationId = $options['conversation_id'] ?? '';
            if ($sessionId !== '' && $conversationId !== '' && str_contains($fullError, 'No conversation found with session ID')) {
                $this->logger->warning("Task {$taskId}: session {$sessionId} not found, retrying with conversation history");

                // Build conversation context from turns
                $turns = $this->conversationManager->getConversationTurns($conversationId);
                $historyLines = [];
                foreach ($turns as $turn) {
                    $role = ucfirst($turn['role'] ?? 'user');
                    $content = $turn['content'] ?? '';
                    if ($content !== '' && ($turn['task_id'] ?? '') !== $taskId) {
                        $historyLines[] = "[{$role}]: {$content}";
                    }
                }

                $originalPrompt = $task['prompt'] ?? '';
                if (!empty($historyLines)) {
                    $history = implode("\n\n", $historyLines);
                    $newPrompt = "Here is our conversation so far:\n\n{$history}\n\n---\n\nPlease continue. My latest message:\n\n{$originalPrompt}";
                } else {
                    $newPrompt = $originalPrompt;
                }

                // Clear session_id so buildCommand won't add --resume, update the prompt
                $this->taskManager->resetTaskForRetry($taskId, $newPrompt);

                $this->logger->info("Task {$taskId}: retrying as fresh session with " . count($historyLines) . ' turns of history');
                $this->runTask($taskId, $externalStderrCallback);

                return;
            }

            if ($parsed->sessionId) {
                $this->taskManager->setClaudeSessionId($taskId, $parsed->sessionId);
            }
            if ($parsed->costUsd > 0) {
                $this->taskManager->setTaskCost($taskId, $parsed->costUsd);
            }
            $this->taskManager->setTaskError($taskId, $fullError);
            $this->taskManager->transition($taskId, TaskState::FAILED);
        }
    }

    /**
     * Read stdout and stderr concurrently using stream_select to avoid pipe deadlock.
     *
     * Sequential stream_get_contents() deadlocks when stderr fills the OS pipe buffer (~64KB):
     * parent blocks reading stdout while child blocks writing stderr. stream_select() reads
     * from whichever pipe has data, preventing the deadlock.
     *
     * @param resource $stdout
     * @param resource $stderr
     * @param resource $process
     *
     * @return array{stdout: string, stderr: string, timed_out: bool}
     */
    private function readPipesConcurrently(mixed $stdout, mixed $stderr, mixed $process, int $timeout, ?callable $onStderrChunk = null): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $stdoutBuf = '';
        $stderrBuf = '';
        $deadline = $timeout > 0 ? time() + $timeout : 0;

        while (true) {
            // Check timeout (0 = no timeout)
            if ($deadline > 0) {
                $remaining = $deadline - time();
                if ($remaining <= 0) {
                    $this->killProcess($process);

                    return ['stdout' => $stdoutBuf, 'stderr' => $stderrBuf, 'timed_out' => true];
                }
            }

            $read = [];
            if (is_resource($stdout)) {
                $read[] = $stdout;
            }
            if (is_resource($stderr)) {
                $read[] = $stderr;
            }

            // Both pipes closed — process is done
            if (empty($read)) {
                break;
            }

            $write = null;
            $except = null;
            $selectTimeout = ($deadline > 0) ? min($deadline - time(), 5) : 5;

            $ready = @stream_select($read, $write, $except, $selectTimeout);

            if ($ready === false) {
                // stream_select error — break and let proc_close handle it
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        // Close the exhausted pipe so it's removed from the next iteration
                        if ($stream === $stdout) {
                            fclose($stdout);
                            $stdout = null;
                        } else {
                            fclose($stderr);
                            $stderr = null;
                        }
                    }
                    continue;
                }
                if ($stream === $stdout) {
                    $stdoutBuf .= $chunk;
                } else {
                    $stderrBuf .= $chunk;
                    if ($onStderrChunk !== null) {
                        $onStderrChunk($chunk);
                    }
                }
            }

            // Also check if process has exited and both pipes are drained
            $status = proc_get_status($process);
            if (!$status['running'] && !is_resource($stdout) && !is_resource($stderr)) {
                break;
            }
        }

        return ['stdout' => $stdoutBuf, 'stderr' => $stderrBuf, 'timed_out' => false];
    }

    /**
     * Kill a process gracefully: SIGTERM first, then SIGKILL after 5s grace period.
     *
     * @param resource $process
     */
    private function killProcess(mixed $process): void
    {
        $status = proc_get_status($process);
        if (!$status['running']) {
            return;
        }

        $pid = $status['pid'];

        // SIGTERM for graceful shutdown
        posix_kill($pid, SIGTERM);

        // Wait up to 5 seconds for the process to exit
        $deadline = time() + 5;
        while (time() < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return;
            }
            \Swoole\Coroutine::sleep(0.1);
        }

        // Still running — force kill
        posix_kill($pid, SIGKILL);
    }

    private function cleanupMcpConfig(string $taskId, ?string $path): void
    {
        if ($path !== null) {
            $this->mcpConfigGenerator->cleanup($taskId);
        }
    }

    /**
     * Find an existing epic by name (case-insensitive) or create a new one.
     */
    private function findOrCreateEpicByName(string $projectId, string $epicName): string
    {
        $epics = $this->epicManager->listEpics($projectId);
        foreach ($epics as $epic) {
            if (strcasecmp($epic['title'] ?? '', $epicName) === 0) {
                return $epic['id'];
            }
        }

        return $this->epicManager->createEpic($projectId, $epicName);
    }

    private function buildCommand(array $task): array
    {
        $cliPath = $this->config->get('mcp.claude.cli_path', '/Users/chadpeppers/.local/bin/claude');
        $maxTurns = $this->config->get('mcp.claude.max_turns', 25);
        $maxBudget = $this->config->get('mcp.claude.max_budget_usd', 5.00);
        $defaultModel = $this->config->get('mcp.claude.default_model', '');

        $options = json_decode($task['options'] ?? '{}', true) ?: [];

        $args = [
            $cliPath,
            '-p', $task['prompt'],
            '--output-format', 'json',
            '--dangerously-skip-permissions',
            '--max-turns', (string) ($options['max_turns'] ?? $maxTurns),
        ];

        if ($maxBudget > 0) {
            $args[] = '--max-budget-usd';
            $args[] = (string) ($options['max_budget_usd'] ?? $maxBudget);
        }

        $model = $options['model'] ?? $defaultModel;
        if ($model !== '' && $model !== null) {
            $args[] = '--model';
            $args[] = $model;
        }

        $sessionId = $task['session_id'] ?? '';
        if ($sessionId !== '') {
            $args[] = '--resume';
            $args[] = $sessionId;
        }

        if (isset($options['mcp_config']) && $options['mcp_config'] !== '') {
            $args[] = '--mcp-config';
            $args[] = $options['mcp_config'];
        }

        if (isset($options['append_system_prompt']) && $options['append_system_prompt'] !== '') {
            $args[] = '--append-system-prompt';
            $args[] = $options['append_system_prompt'];
        }

        // --allowedTools no longer needed: --dangerously-skip-permissions covers all tools

        return $args;
    }

    /**
     * Build environment variables for the Claude CLI process.
     * Critical: Unsets CLAUDECODE to prevent nested session detection.
     */
    private function buildEnvironment(): array
    {
        $env = [];

        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && !str_starts_with($key, 'HTTP_')) {
                $env[$key] = $value;
            }
        }

        // Critical: Remove env vars to prevent nested session detection
        unset($env['CLAUDECODE']);
        unset($env['CLAUDE_CODE_ENTRY_POINT']);

        $env['HOME'] = $_ENV['HOME'] ?? '/Users/chadpeppers';
        $path = $env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin';
        if (!str_contains($path, '/Users/chadpeppers/.local/bin')) {
            $env['PATH'] = '/Users/chadpeppers/.local/bin:' . $path;
        }

        // Pass SSH agent socket so Claude CLI can use SSH keys
        $sshAuthSock = $_ENV['SSH_AUTH_SOCK'] ?? $_SERVER['SSH_AUTH_SOCK'] ?? env('SSH_AUTH_SOCK', '');
        if ($sshAuthSock !== '' && file_exists($sshAuthSock)) {
            $env['SSH_AUTH_SOCK'] = $sshAuthSock;
        }

        return $env;
    }
}
