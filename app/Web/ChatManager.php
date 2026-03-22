<?php

declare(strict_types=1);

namespace App\Web;

use App\StateMachine\TaskManager;
use App\Claude\ProcessManager;
use App\Memory\MemoryManager;
use App\Conversation\ConversationManager;
use App\Conversation\ConversationType;
use App\Item\ItemManager;
use App\Agent\AgentManager;
use App\Agent\AgentPromptBuilder;
use App\Project\ProjectManager;
use App\Prompts\PromptLoader;
use App\Workflow\TemplateResolver;
use App\Storage\PostgresStore;
use App\Chat\AnthropicClient;
use App\Chat\ChatToolHandler;
use App\Chat\ChatConversationStore;
use App\Chat\ToolDefinitions;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Contract\ConfigInterface;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Server;

class ChatManager
{
    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private ProcessManager $processManager;

    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private ConversationManager $conversationManager;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private PromptLoader $promptLoader;

    #[Inject]
    private ItemManager $itemManager;

    #[Inject]
    private TemplateResolver $templateResolver;

    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private WebAuthManager $authManager;

    #[Inject]
    private AnthropicClient $anthropicClient;

    #[Inject]
    private ChatToolHandler $chatToolHandler;

    #[Inject]
    private ChatConversationStore $chatConversationStore;

    #[Inject]
    private ToolDefinitions $toolDefinitions;

    #[Inject]
    private AgentManager $agentManager;

    #[Inject]
    private AgentPromptBuilder $agentPromptBuilder;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    public function sendChat(
        Server $server,
        int $fd,
        string $prompt,
        ?string $template = null,
        ?string $parentTaskId = null,
        ?string $conversationId = null,
        array $images = [],
        ?string $agentId = null,
    ): void {
        // Branch: use Anthropic API for chat if enabled
        if ($this->config->get('mcp.chat.enabled') && $this->config->get('mcp.chat.api_key') !== '') {
            $this->sendChatApi($server, $fd, $prompt, $template, $parentTaskId, $conversationId, $images, $agentId);
            return;
        }

        $userId = $this->authManager->getUserId();

        $templateConfig = $this->templateResolver->resolve($template, $prompt);
        $templateName = $templateConfig['name'] ?? 'standard';

        $this->logger->info("Web chat: template={$templateName}, prompt=" . mb_substr($prompt, 0, 60));

        $options = [
            'web_user_id' => $userId,
            'max_turns' => $templateConfig['max_turns'] ?? 25,
            'max_budget_usd' => $templateConfig['max_budget_usd'] ?? 5.00,
            'workflow_template' => $templateName,
        ];

        // Resolve or create conversation
        if ($conversationId === null && $parentTaskId !== null && $parentTaskId !== '') {
            // Look up conversation from parent task
            $parentTask = $this->taskManager->getTask($parentTaskId);
            if ($parentTask && !empty($parentTask['conversation_id'])) {
                $conversationId = $parentTask['conversation_id'];
            }
        }

        // Resolve agent
        $agent = null;
        if ($agentId !== null && $agentId !== '') {
            $agent = $this->agentManager->getAgent($agentId);
        }
        if ($agent === null) {
            $agent = $this->agentManager->getDefaultAgent();
        }
        $resolvedAgentId = $agent['id'] ?? '';
        $agentSlug = $agent['slug'] ?? 'pm';

        $agentType = $agentSlug === 'project' ? 'project' : 'pm';
        $projectName = 'General';
        $routedProjectId = 'general';
        $convType = ConversationType::TASK;

        // Use agent's project scope if set
        $agentProjectId = $agent['project_id'] ?? '';
        if ($agentProjectId !== '' && $agentProjectId !== '0') {
            $routedProjectId = $agentProjectId;
            $agentType = 'project';
            $project = $this->projectManager->getProject($routedProjectId);
            if ($project) {
                $projectName = $project['name'] ?? 'General';
            }
        }

        if ($conversationId === null || $conversationId === '') {
            $conversationId = $this->conversationManager->createConversation(
                $userId,
                $convType,
                $routedProjectId,
                'web',
                $resolvedAgentId,
            );

            $this->logger->info("Agent: slug={$agentSlug} project={$projectName}");
        } else {
            // Existing conversation — look up its project and type
            $existingConv = $this->conversationManager->getConversation($conversationId);
            if ($existingConv) {
                $routedProjectId = $existingConv['project_id'] ?? 'general';
                $convType = ConversationType::tryFrom($existingConv['type'] ?? '') ?? ConversationType::TASK;
                $agentType = ($routedProjectId !== 'general') ? 'project' : 'pm';
                // Use agent from conversation if not explicitly provided
                if (($agentId === null || $agentId === '') && !empty($existingConv['agent_id'])) {
                    $existingAgent = $this->agentManager->getAgent($existingConv['agent_id']);
                    if ($existingAgent) {
                        $agent = $existingAgent;
                        $resolvedAgentId = $agent['id'];
                        $agentSlug = $agent['slug'] ?? 'pm';
                    }
                }
                $project = $this->projectManager->getProject($routedProjectId);
                if ($project) {
                    $projectName = $project['name'] ?? 'General';
                }
            } else {
                $this->logger->warning("ChatManager: conversation {$conversationId} not found, creating new");
                $conversationId = $this->conversationManager->createConversation(
                    $userId,
                    $convType,
                    $routedProjectId,
                    'web',
                    $resolvedAgentId,
                );
            }
        }

        $options['conversation_id'] = $conversationId;
        $options['agent_type'] = $agentType;
        $options['agent_id'] = $resolvedAgentId;
        $options['project_id'] = $routedProjectId;

        if (!empty($images)) {
            $options['images'] = $images;
        }

        // Continue conversation or start new
        if ($parentTaskId !== null && $parentTaskId !== '') {
            $this->continueChat($server, $fd, $prompt, $parentTaskId, $options, $userId, $conversationId, $convType->value);
            return;
        }

        // All conversation types dispatch to external worker via supervisor queue.
        // The external task-worker.php process handles CLI execution outside of Swoole.
        $this->autoDispatchTask($server, $fd, $prompt, $userId, $conversationId, $routedProjectId, $projectName, $agentType, $options, $images);
    }

    private function sendChatApi(
        Server $server,
        int $fd,
        string $prompt,
        ?string $template,
        ?string $parentTaskId,
        ?string $conversationId,
        array $images = [],
        ?string $agentId = null,
    ): void {
        $userId = $this->authManager->getUserId();
        $this->logger->info("Web chat (API mode): prompt=" . mb_substr($prompt, 0, 60));

        // Resolve or create conversation (reuse existing routing logic)
        if ($conversationId === null && $parentTaskId !== null && $parentTaskId !== '') {
            $parentTask = $this->taskManager->getTask($parentTaskId);
            if ($parentTask && !empty($parentTask['conversation_id'])) {
                $conversationId = $parentTask['conversation_id'];
            }
        }

        // Resolve agent
        $agent = null;
        if ($agentId !== null && $agentId !== '') {
            $agent = $this->agentManager->getAgent($agentId);
        }
        if ($agent === null) {
            $agent = $this->agentManager->getDefaultAgent();
        }
        $resolvedAgentId = $agent['id'] ?? '';
        $agentSlug = $agent['slug'] ?? 'pm';

        $agentType = $agentSlug === 'project' ? 'project' : 'pm';
        $projectName = 'General';
        $routedProjectId = 'general';
        $convType = ConversationType::DISCUSSION;

        // Use agent's project scope if set
        $agentProjectId = $agent['project_id'] ?? '';
        if ($agentProjectId !== '' && $agentProjectId !== '0') {
            $routedProjectId = $agentProjectId;
            $agentType = 'project';
            $project = $this->projectManager->getProject($routedProjectId);
            if ($project) {
                $projectName = $project['name'] ?? 'General';
            }
        }

        if ($conversationId === null || $conversationId === '') {
            $conversationId = $this->conversationManager->createConversation(
                $userId,
                $convType,
                $routedProjectId,
                'web',
                $resolvedAgentId,
            );
        } else {
            $existingConv = $this->conversationManager->getConversation($conversationId);
            if ($existingConv) {
                $routedProjectId = $existingConv['project_id'] ?? 'general';
                $convType = ConversationType::tryFrom($existingConv['type'] ?? '') ?? ConversationType::DISCUSSION;
                $agentType = ($routedProjectId !== 'general') ? 'project' : 'pm';
                // Use agent from conversation if not explicitly provided
                if (($agentId === null || $agentId === '') && !empty($existingConv['agent_id'])) {
                    $existingAgent = $this->agentManager->getAgent($existingConv['agent_id']);
                    if ($existingAgent) {
                        $agent = $existingAgent;
                        $resolvedAgentId = $agent['id'];
                        $agentSlug = $agent['slug'] ?? 'pm';
                    }
                }
                $project = $this->projectManager->getProject($routedProjectId);
                if ($project) {
                    $projectName = $project['name'] ?? 'General';
                }
            } else {
                $this->logger->warning("ChatManager: conversation {$conversationId} not found in API mode, creating new");
                $conversationId = $this->conversationManager->createConversation(
                    $userId,
                    $convType,
                    $routedProjectId,
                    'web',
                    $resolvedAgentId,
                );
            }
        }

        // Send ack immediately with agent info
        $apiTaskId = 'api_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->pushJson($server, $fd, [
            'type' => 'chat.ack',
            'task_id' => $apiTaskId,
            'conversation_id' => $conversationId,
            'agent_type' => $agentType,
            'project_name' => $projectName,
            'agent' => [
                'id' => $resolvedAgentId,
                'slug' => $agentSlug,
                'name' => $agent['name'] ?? 'PM',
                'color' => $agent['color'] ?? '#6366f1',
                'icon' => $agent['icon'] ?? 'bot',
            ],
        ]);

        $startTime = time();

        // Build system prompt from agent
        $systemPrompt = $this->agentPromptBuilder->build(
            $agent,
            $userId,
            $prompt,
            $routedProjectId !== 'general' ? $routedProjectId : null,
        );

        // Get existing history
        $history = $this->chatConversationStore->getHistory($conversationId);

        // Inject recent task completions as context
        $recentTasks = $this->taskManager->listTasks('completed', 3);
        $completedContext = [];
        foreach ($recentTasks as $t) {
            $options = json_decode($t['options'] ?? '{}', true);
            if (($options['dispatch_mode'] ?? '') !== 'supervisor') {
                continue;
            }
            $completedAt = $t['completed_at'] ?? $t['updated_at'] ?? '';
            $taskPrompt = mb_substr($t['prompt'] ?? '', 0, 80);
            $taskResult = mb_substr($t['result'] ?? '', 0, 300);
            $taskId = $t['id'] ?? '';
            if ($taskResult !== '') {
                $completedContext[] = "Task `{$taskId}` ({$taskPrompt}): {$taskResult}";
            }
        }

        if (!empty($completedContext)) {
            $contextBlock = "[System context: Recently completed tasks]\n" . implode("\n", $completedContext);
            // If this is the start of a conversation, inject as the first assistant-acknowledged context
            if (empty($history)) {
                $history[] = ['role' => 'user', 'content' => $contextBlock];
                $history[] = ['role' => 'assistant', 'content' => 'I see the recent task results. How can I help?'];
            }
        }

        // Add current user message (multimodal if images present)
        if (!empty($images)) {
            $content = [];
            foreach ($images as $img) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $img['media_type'],
                        'data' => $img['data'],
                    ],
                ];
            }
            $content[] = ['type' => 'text', 'text' => $prompt];
            $userMessage = ['role' => 'user', 'content' => $content];
        } else {
            $userMessage = ['role' => 'user', 'content' => $prompt];
        }
        $history[] = $userMessage;

        // Sanitize history to fix any tool_use/tool_result mismatches (e.g. from compaction)
        $history = $this->chatConversationStore->sanitizeHistory($history);

        // Set up tool handler with conversation context
        $this->chatToolHandler->setUserId($userId);
        $this->chatToolHandler->setConversationId($conversationId);

        // Call Anthropic API (handles tool_use loop internally)
        $tools = $this->toolDefinitions->getTools();
        $result = $this->anthropicClient->sendMessage($systemPrompt, $history, $tools, $this->chatToolHandler);

        $responseText = $result['response'] ?? '';
        $updatedMessages = $result['messages'] ?? $history;
        $usage = $result['usage'] ?? [];
        $cost = $usage['cost_usd'] ?? 0.0;
        $duration = time() - $startTime;

        // Store the full message exchange in chat history
        // Clear and replace with updated messages (includes tool_use/tool_result)
        $this->chatConversationStore->deleteHistory($conversationId);
        foreach ($updatedMessages as $msg) {
            $this->chatConversationStore->appendMessage($conversationId, $msg);
        }

        // Record turns in ConversationManager
        $this->conversationManager->addTurn($conversationId, 'user', $prompt, '');
        $this->conversationManager->addTurn($conversationId, 'assistant', $responseText, '', $cost);

        // Send response
        $this->pushJson($server, $fd, [
            'type' => 'chat.result',
            'task_id' => $apiTaskId,
            'conversation_id' => $conversationId,
            'result' => $responseText,
            'claude_session_id' => '',
            'cost_usd' => $cost,
            'duration' => $duration,
            'images' => [],
        ]);

        // Extract memories from this exchange
        if ($responseText !== '') {
            $this->extractMemoryAsync(
                ['prompt' => $prompt, 'result' => $responseText],
                $userId,
                $routedProjectId,
                $conversationId,
                $convType->value,
            );
        }

        // Check if compaction is needed
        if ($this->chatConversationStore->needsCompaction($conversationId)) {
            $this->compactChatHistoryAsync($conversationId);
        }
    }

    /**
     * Compact chat history asynchronously using a summarization call.
     */
    private function compactChatHistoryAsync(string $conversationId): void
    {
        \Swoole\Coroutine::create(function () use ($conversationId) {
            try {
                $history = $this->chatConversationStore->getHistory($conversationId);
                if (count($history) <= 10) {
                    return;
                }

                // Take the older messages to summarize
                $olderMessages = array_slice($history, 0, count($history) - 10);
                $summaryText = '';
                foreach ($olderMessages as $msg) {
                    $role = $msg['role'] ?? '';
                    $content = is_string($msg['content'] ?? null) ? $msg['content'] : '[tool interaction]';
                    $summaryText .= "{$role}: " . mb_substr($content, 0, 200) . "\n";
                }
                $summaryText = mb_substr($summaryText, 0, 2000);

                // Use a quick API call to summarize
                $summaryResult = $this->anthropicClient->sendMessage(
                    'Summarize this conversation in 2-3 sentences, capturing the key topics, decisions, and any tasks created.',
                    [['role' => 'user', 'content' => $summaryText]],
                    [],
                    $this->chatToolHandler,
                );

                $summary = $summaryResult['response'] ?? 'Previous conversation context.';
                $this->chatConversationStore->compactHistory($conversationId, $summary, 10);
            } catch (\Throwable $e) {
                $this->logger->warning("Chat history compaction failed: {$e->getMessage()}");
            }
        });
    }

    private function continueChat(Server $server, int $fd, string $prompt, string $parentTaskId, array $options, string $userId, string $conversationId, string $conversationType = 'task'): void
    {
        $projectId = $options['project_id'] ?? 'general';

        try {
            $startTime = time();
            $newTaskId = null;

            $onStderrChunk = function (string $chunk, int $elapsed, int $stderrLines) use ($server, $fd, &$newTaskId) {
                if (!$server->isEstablished($fd)) {
                    return;
                }
                $this->pushJson($server, $fd, [
                    'type' => 'chat.progress',
                    'task_id' => $newTaskId ?? '',
                    'elapsed' => $elapsed,
                    'stderr_lines' => $stderrLines,
                    'timestamp' => time(),
                ]);
            };

            $onComplete = function (?array $task) use ($server, $fd, &$newTaskId, $userId, $startTime, $conversationId, $projectId, $conversationType) {
                $taskId = $newTaskId ?? '';
                if (!$task) {
                    $this->pushJson($server, $fd, [
                        'type' => 'chat.error',
                        'task_id' => $taskId,
                        'error' => 'Task not found after completion',
                    ]);
                    return;
                }

                $state = $task['state'] ?? '';
                $duration = time() - $startTime;
                $cost = (float) ($task['cost_usd'] ?? 0);

                if ($state === 'completed') {
                    $images = [];
                    if (!empty($task['images'])) {
                        $images = json_decode($task['images'], true) ?: [];
                    }

                    $result = $task['result'] ?? '';

                    // Always record assistant turn (even if browser disconnected)
                    $this->conversationManager->addTurn($conversationId, 'assistant', $result, $taskId, $cost);

                    $this->pushJson($server, $fd, [
                        'type' => 'chat.result',
                        'task_id' => $taskId,
                        'conversation_id' => $conversationId,
                        'result' => $result,
                        'claude_session_id' => $task['claude_session_id'] ?? '',
                        'cost_usd' => $cost,
                        'duration' => $duration,
                        'images' => $images,
                    ]);
                } else {
                    $this->pushJson($server, $fd, [
                        'type' => 'chat.error',
                        'task_id' => $taskId,
                        'error' => $task['error'] ?? 'Task failed',
                    ]);
                }

                // Always run extract_memory (even if browser disconnected)
                $this->extractMemoryAsync($task, $userId, $projectId, $conversationId, $conversationType);
            };

            $newTaskId = $this->processManager->continueTaskWithCallbacks(
                $parentTaskId,
                $prompt,
                $options,
                $onStderrChunk,
                $onComplete
            );

            $this->store->addUserTask($userId, $newTaskId);

            // Record user turn
            $this->conversationManager->addTurn($conversationId, 'user', $prompt, $newTaskId);

            // Resolve agent/project info for the ack
            $ackAgentType = $options['agent_type'] ?? 'pm';
            $ackProjectName = 'General';
            $ackProjectId = $options['project_id'] ?? 'general';
            if ($ackProjectId !== 'general') {
                $ackProject = $this->projectManager->getProject($ackProjectId);
                if ($ackProject) {
                    $ackProjectName = $ackProject['name'] ?? 'General';
                }
            }

            $ack = [
                'type' => 'chat.ack',
                'task_id' => $newTaskId,
                'conversation_id' => $conversationId,
                'agent_type' => $ackAgentType,
                'project_name' => $ackProjectName,
            ];
            $ackAgentId = $options['agent_id'] ?? '';
            if ($ackAgentId !== '') {
                $ackAgent = $this->agentManager->getAgent($ackAgentId);
                if ($ackAgent) {
                    $ack['agent'] = [
                        'id' => $ackAgent['id'] ?? '',
                        'slug' => $ackAgent['slug'] ?? '',
                        'name' => $ackAgent['name'] ?? 'PM',
                        'color' => $ackAgent['color'] ?? '#6366f1',
                        'icon' => $ackAgent['icon'] ?? 'bot',
                    ];
                }
            }
            $this->pushJson($server, $fd, $ack);
        } catch (\Throwable $e) {
            $this->logger->error("Continue chat failed: {$e->getMessage()}");
            $this->pushJson($server, $fd, [
                'type' => 'chat.error',
                'task_id' => $parentTaskId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractMemoryAsync(array $task, string $userId, string $projectId = 'general', string $conversationId = '', string $conversationType = 'task'): void
    {
        \Swoole\Coroutine::create(function () use ($task, $userId, $projectId, $conversationId, $conversationType) {
            try {
                $prompt = $task['prompt'] ?? '';
                $result = $task['result'] ?? '';
                if ($prompt === '' || $result === '' || $userId === '') {
                    return;
                }

                $prompt = mb_substr($prompt, 0, 1000);
                $result = mb_substr($result, 0, 2000);

                $extractionTemplate = $this->promptLoader->loadExtractionPrompt($conversationType);
                $extractionPrompt = str_replace(
                    ['{prompt}', '{result}'],
                    [$prompt, $result],
                    $extractionTemplate,
                );

                $extractionTaskId = $this->taskManager->createTask($extractionPrompt, null, [
                    'source' => 'extraction',
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_turns' => 1,
                    'max_budget_usd' => 0.05,
                ]);

                $this->processManager->executeTask($extractionTaskId);

                $maxWait = 30;
                $elapsed = 0;
                while ($elapsed < $maxWait) {
                    \Swoole\Coroutine::sleep(1);
                    $elapsed++;

                    $extractionTask = $this->taskManager->getTask($extractionTaskId);
                    if (!$extractionTask) {
                        return;
                    }

                    $state = $extractionTask['state'] ?? '';
                    if ($state === 'completed') {
                        $extractResult = $extractionTask['result'] ?? '';
                        $this->parseAndStoreMemory($userId, $extractResult, $projectId, $conversationId);
                        $this->taskManager->deleteTask($extractionTaskId);
                        return;
                    }

                    if ($state === 'failed') {
                        $this->taskManager->deleteTask($extractionTaskId);
                        return;
                    }
                }

                // Timed out — clean up the extraction task
                $this->taskManager->deleteTask($extractionTaskId);
            } catch (\Throwable $e) {
                $this->logger->warning("Memory extraction failed: {$e->getMessage()}");
            }
        });
    }

    /**
     * Auto-dispatch a task to the supervisor for background execution.
     * Sends an immediate ack + response to the user without waiting.
     */
    private function autoDispatchTask(
        Server $server,
        int $fd,
        string $prompt,
        string $userId,
        string $conversationId,
        string $projectId,
        string $projectName,
        string $agentType,
        array $options,
        array $images = [],
    ): void {
        $templateConfig = $this->templateResolver->resolve($options['workflow_template'] ?? null, $prompt);

        $taskOptions = [
            'web_user_id' => $userId,
            'dispatch_mode' => 'supervisor',
            'agent_type' => $agentType,
            'project_id' => $projectId,
            'conversation_id' => $conversationId,
            'max_turns' => $templateConfig['max_turns'] ?? 25,
            'max_budget_usd' => $templateConfig['max_budget_usd'] ?? 5.00,
            'workflow_template' => $templateConfig['name'] ?? 'standard',
        ];

        if (!empty($images)) {
            $taskOptions['images'] = $images;
        }

        $taskId = $this->taskManager->createTask($prompt, null, $taskOptions);
        $this->store->addUserTask($userId, $taskId);

        // Record conversation turns
        $this->conversationManager->addTurn($conversationId, 'user', $prompt, $taskId);

        $projectLabel = ($projectId !== 'general') ? " for **{$projectName}**" : '';
        $responseText = "Got it — I've dispatched that as a background task{$projectLabel}.\n\n"
            . "**Task ID:** `{$taskId}`\n\n"
            . "I'll notify you when it's done. You can ask me for a status update anytime.";

        $this->conversationManager->addTurn($conversationId, 'assistant', $responseText, $taskId);

        // Resolve agent for ack
        $ackAgent = null;
        $ackAgentId = $options['agent_id'] ?? '';
        if ($ackAgentId !== '') {
            $ackAgent = $this->agentManager->getAgent($ackAgentId);
        }

        // Send ack + immediate result
        $ack = [
            'type' => 'chat.ack',
            'task_id' => $taskId,
            'conversation_id' => $conversationId,
            'agent_type' => $agentType,
            'project_name' => $projectName,
        ];
        if ($ackAgent) {
            $ack['agent'] = [
                'id' => $ackAgent['id'] ?? '',
                'slug' => $ackAgent['slug'] ?? '',
                'name' => $ackAgent['name'] ?? 'PM',
                'color' => $ackAgent['color'] ?? '#6366f1',
                'icon' => $ackAgent['icon'] ?? 'bot',
            ];
        }
        $this->pushJson($server, $fd, $ack);

        $this->pushJson($server, $fd, [
            'type' => 'chat.result',
            'task_id' => $taskId,
            'conversation_id' => $conversationId,
            'result' => $responseText,
            'claude_session_id' => '',
            'cost_usd' => 0,
            'duration' => 0,
            'images' => [],
        ]);

        $this->logger->info("Auto-dispatched task {$taskId} to external worker (project={$projectId}, agent={$agentType})");
    }

    private function parseAndStoreMemory(string $userId, string $extractResult, string $projectId = 'general', string $conversationId = ''): void
    {
        $data = $this->extractJson($extractResult);
        if ($data === null) {
            return;
        }

        if (!empty($data['summary'])) {
            $summary = $data['summary'];
            if (!empty($data['topics']) && is_array($data['topics'])) {
                $summary .= ' [' . implode(', ', $data['topics']) . ']';
            }
            $this->memoryManager->logConversation($userId, $summary);
        }

        if (!empty($data['memories']) && is_array($data['memories'])) {
            foreach ($data['memories'] as $mem) {
                if (!is_array($mem)) {
                    continue;
                }
                $category = $mem['category'] ?? 'fact';
                $content = $mem['content'] ?? '';
                $importance = $mem['importance'] ?? 'normal';

                if ($content === '' || !in_array($category, ['preference', 'project', 'fact', 'context', 'rule', 'conversation'], true)) {
                    continue;
                }

                // Route: preference/fact → always general; project/context → project scope when known
                if ($projectId !== 'general' && in_array($category, ['project', 'context'], true)) {
                    $this->memoryManager->storeProjectMemory($userId, $projectId, $category, $content, $importance, 'extraction');
                } else {
                    $this->memoryManager->storeMemory($userId, $category, $content, $importance, 'extraction');
                }
            }
        }

        // Create work items for non-general projects
        if ($projectId !== 'general' && !empty($data['work_items']) && is_array($data['work_items'])) {
            $existingItems = $this->itemManager->listItemsByProject($projectId);
            $existingTitles = array_map(
                fn(array $item) => mb_strtolower($item['title'] ?? ''),
                $existingItems,
            );

            foreach ($data['work_items'] as $wi) {
                if (!is_array($wi)) {
                    continue;
                }
                $title = $wi['title'] ?? '';
                $description = $wi['description'] ?? '';
                $priority = $wi['priority'] ?? 'normal';

                if ($title === '') {
                    continue;
                }

                if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
                    $priority = 'normal';
                }

                // Dedup by case-insensitive title match
                if (in_array(mb_strtolower($title), $existingTitles, true)) {
                    continue;
                }

                try {
                    $this->itemManager->createItem($projectId, $title, null, $description, $priority, $conversationId);
                    $existingTitles[] = mb_strtolower($title);
                    $this->logger->info("Created work item from extraction: {$title}");
                } catch (\Throwable $e) {
                    $this->logger->warning("Failed to create extracted work item: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Extract the first valid JSON object from a string.
     * Tries greedy regex first, falls back to balanced-brace extraction.
     */
    private function extractJson(string $text): ?array
    {
        // Try greedy match (first { to last })
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $data = json_decode($matches[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Fallback: find first balanced JSON object by counting braces
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                $escape = true;
                continue;
            }

            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    $data = json_decode($candidate, true);
                    if (is_array($data)) {
                        return $data;
                    }
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Handle an @claude mention in a channel: call the Anthropic API and post the reply back.
     */
    public function sendChannelReply(
        Server $server,
        string $channelId,
        string $userMessage,
        string $channelName,
    ): void {
        $defaultAgent = $this->agentManager->getDefaultAgent();
        $this->sendRoomAgentReply($server, $channelId, $userMessage, $channelName, $defaultAgent);
    }

    /**
     * Handle an @slug mention in a room/channel: call the Anthropic API with agent-specific prompt.
     */
    public function sendRoomAgentReply(
        Server $server,
        string $channelId,
        string $userMessage,
        string $channelName,
        array $agent,
    ): void {
        $agentSlug = $agent['slug'] ?? 'claude';
        $agentName = $agent['name'] ?? 'Claude';
        $this->logger->info("Room agent reply: channel={$channelName} agent={$agentSlug} prompt=" . mb_substr($userMessage, 0, 60));

        // Strip @mention from the prompt
        $prompt = trim(preg_replace('/@\w+\b/', '', $userMessage));
        if ($prompt === '') {
            $prompt = "Hello! How can I help in this channel?";
        }

        // Build channel-aware system prompt using agent's prompt + channel context
        $agentPrompt = $agent['system_prompt'] ?? '';
        $systemPrompt = ($agentPrompt !== '' ? $agentPrompt . "\n\n" : '')
            . "You are {$agentName}, participating in a channel called #{$channelName}. "
            . "Keep responses concise and conversational — this is a group chat, not a 1:1 session. "
            . "Use markdown for formatting when helpful.";

        // Get recent channel messages for context
        $recentMessages = $this->store->getChannelMessages($channelId, 20);
        $history = [];
        foreach ($recentMessages as $msg) {
            $author = $msg['author'] ?? 'User';
            $content = $msg['content'] ?? '';
            if ($author === 'Claude') {
                $history[] = ['role' => 'assistant', 'content' => $content];
            } else {
                $history[] = ['role' => 'user', 'content' => "[{$author}]: {$content}"];
            }
        }

        // Add the triggering message if not already in history
        if (empty($history) || ($history[count($history) - 1]['content'] ?? '') !== $userMessage) {
            $history[] = ['role' => 'user', 'content' => $userMessage];
        }

        // Ensure history alternates roles properly for the API
        $sanitized = [];
        $lastRole = null;
        foreach ($history as $msg) {
            if ($msg['role'] === $lastRole) {
                // Merge consecutive same-role messages
                $sanitized[count($sanitized) - 1]['content'] .= "\n" . $msg['content'];
            } else {
                $sanitized[] = $msg;
                $lastRole = $msg['role'];
            }
        }
        // Ensure it starts with user
        if (!empty($sanitized) && $sanitized[0]['role'] !== 'user') {
            array_unshift($sanitized, ['role' => 'user', 'content' => '[Channel context start]']);
        }
        // Ensure it ends with user
        if (!empty($sanitized) && $sanitized[count($sanitized) - 1]['role'] !== 'user') {
            $sanitized[] = ['role' => 'user', 'content' => $prompt];
        }

        try {
            $result = $this->anthropicClient->sendMessage($systemPrompt, $sanitized, [], $this->chatToolHandler);
            $responseText = $result['response'] ?? '';
        } catch (\Throwable $e) {
            $this->logger->error("Channel reply failed: {$e->getMessage()}");
            $responseText = "Sorry, I encountered an error: {$e->getMessage()}";
        }

        if ($responseText === '') {
            return;
        }

        // Save reply as a channel message attributed to the agent
        $replyMessage = [
            'id' => uniqid('msg_', true),
            'channel_id' => $channelId,
            'author' => $agentName,
            'content' => $responseText,
            'created_at' => time(),
            'agent_id' => $agent['id'] ?? null,
        ];
        $this->store->saveChannelMessage($channelId, $replyMessage);

        // Broadcast to all connected clients
        $cache = \Hyperf\Context\ApplicationContext::getContainer()->get(\App\Storage\SwooleTableCache::class);
        $allConns = $cache->getWsConnections();
        foreach ($allConns as $otherFd => $otherConn) {
            if ($server->isEstablished((int)$otherFd)) {
                $this->pushJson($server, (int)$otherFd, [
                    'type' => 'channels.message',
                    'channel_id' => $channelId,
                    'message' => $replyMessage,
                ]);
            }
        }
    }

    private function pushJson(Server $server, int $fd, array $data): void
    {
        try {
            if ($server->isEstablished($fd)) {
                $server->push($fd, json_encode($data));
            }
        } catch (\Throwable $e) {
            $this->logger->debug("WebSocket push failed for fd={$fd}: {$e->getMessage()}");
        }
    }
}
