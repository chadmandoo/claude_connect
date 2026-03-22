#!/usr/bin/env php
<?php

/**
 * cc-system MCP Server — stdio JSON-RPC 2.0
 *
 * Standalone script (no Hyperf, no Composer, no Swoole).
 * Gives CLI agents spawned by AgentSupervisor a way to talk back
 * to Claude Connect: check tasks, search memory, update items, etc.
 *
 * Env vars:
 *   CC_USER_ID     — owning user
 *   CC_TASK_ID     — current task ID
 *   CC_PROJECT_ID  — project scope (default "general")
 *   CC_REDIS_HOST  — Redis host (default 127.0.0.1)
 *   CC_REDIS_PORT  — Redis port (default 6380)
 */

declare(strict_types=1);

// ─── Bootstrap ───────────────────────────────────────────────────────────────

$userId    = getenv('CC_USER_ID') ?: '';
$taskId    = getenv('CC_TASK_ID') ?: '';
$projectId = getenv('CC_PROJECT_ID') ?: 'general';
$redisHost = getenv('CC_REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) (getenv('CC_REDIS_PORT') ?: 6380);

if ($userId === '') {
    fwrite(STDERR, "cc-system-mcp: CC_USER_ID is required\n");
    exit(1);
}

try {
    $redis = new \Redis();
    $redis->connect($redisHost, $redisPort, 5.0);
    $redis->ping();
} catch (\Throwable $e) {
    fwrite(STDERR, "cc-system-mcp: Redis connection failed: {$e->getMessage()}\n");
    exit(1);
}

fwrite(STDERR, "cc-system-mcp: connected to Redis {$redisHost}:{$redisPort}\n");

// ─── Helpers ─────────────────────────────────────────────────────────────────

function generateUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 1
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
           substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' .
           substr($hex, 20, 12);
}

function generateMemoryId(): string
{
    return 'mem_' . bin2hex(random_bytes(4));
}

function jsonResponse(int|string|null $id, mixed $result): string
{
    return json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => $result,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function jsonError(int|string|null $id, int $code, string $message): string
{
    return json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => ['code' => $code, 'message' => $message],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function toolResult(string $text): array
{
    return ['content' => [['type' => 'text', 'text' => $text]]];
}

function toolError(string $text): array
{
    return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
}

// ─── Item state transitions (mirrors ItemState::allowedTransitions) ──────────

$ITEM_TRANSITIONS = [
    'open'        => ['in_progress', 'done', 'cancelled'],
    'in_progress' => ['open', 'review', 'blocked', 'done', 'cancelled'],
    'review'      => ['done', 'in_progress', 'open'],
    'blocked'     => ['in_progress', 'done', 'cancelled'],
    'done'        => ['open'],
    'cancelled'   => ['open'],
];

// ─── Tool definitions ────────────────────────────────────────────────────────

$toolDefinitions = [
    [
        'name' => 'cc_check_task_status',
        'description' => 'Check the status of a task by ID. Returns state, result preview, and timing info.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'string', 'description' => 'The task ID to check'],
            ],
            'required' => ['task_id'],
        ],
    ],
    [
        'name' => 'cc_get_task_output',
        'description' => 'Get the full output/result of a completed task.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'string', 'description' => 'The task ID to get output for'],
            ],
            'required' => ['task_id'],
        ],
    ],
    [
        'name' => 'cc_list_tasks',
        'description' => 'List recent tasks. Optionally filter by state.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'state'  => ['type' => 'string', 'description' => 'Filter by state: pending, running, completed, failed. Omit for all.'],
                'limit'  => ['type' => 'integer', 'description' => 'Max tasks to return (default 20, max 50).'],
            ],
            'required' => [],
        ],
    ],
    [
        'name' => 'cc_create_task',
        'description' => 'Create a new sub-task dispatched to the supervisor for background execution.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'prompt'     => ['type' => 'string', 'description' => 'The task prompt/instructions.'],
                'agent_type' => ['type' => 'string', 'description' => 'Agent type: "pm" or "project" (default "project").'],
                'project_id' => ['type' => 'string', 'description' => 'Project scope (defaults to current project).'],
                'model'      => ['type' => 'string', 'description' => 'Model override (e.g. "sonnet", "opus"). Optional.'],
            ],
            'required' => ['prompt'],
        ],
    ],
    [
        'name' => 'cc_search_memory',
        'description' => 'Search stored memories by keyword. Searches both general and project-scoped pools.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Keyword(s) to search for in memory content.'],
                'limit' => ['type' => 'integer', 'description' => 'Max results (default 10, max 30).'],
            ],
            'required' => ['query'],
        ],
    ],
    [
        'name' => 'cc_store_memory',
        'description' => 'Store a memory/finding for future reference.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'content'    => ['type' => 'string', 'description' => 'The memory content to store.'],
                'category'   => ['type' => 'string', 'description' => 'Category: insight, decision, pattern, reference, context (default "insight").'],
                'importance' => ['type' => 'number', 'description' => 'Importance score 0.0-1.0 (default 0.5).'],
                'project_id' => ['type' => 'string', 'description' => 'Project to scope memory to (defaults to current project, "general" for global).'],
            ],
            'required' => ['content'],
        ],
    ],
    [
        'name' => 'cc_list_items',
        'description' => 'List work items for a project. Optionally filter by state.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'string', 'description' => 'Project ID (defaults to current project).'],
                'state'      => ['type' => 'string', 'description' => 'Filter by state: open, in_progress, review, blocked, done, cancelled.'],
            ],
            'required' => [],
        ],
    ],
    [
        'name' => 'cc_update_item',
        'description' => 'Update a work item state or fields.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'string', 'description' => 'The item ID to update.'],
                'state'   => ['type' => 'string', 'description' => 'New state: open, in_progress, review, blocked, done, cancelled.'],
            ],
            'required' => ['item_id', 'state'],
        ],
    ],
    [
        'name' => 'cc_list_projects',
        'description' => 'List all projects/workspaces with their current state.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max projects to return (default 20).'],
            ],
            'required' => [],
        ],
    ],
    [
        'name' => 'cc_report_progress',
        'description' => 'Report progress on the current task. Updates the UI-visible progress indicator.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'A short progress message describing what you are doing.'],
                'percent' => ['type' => 'integer', 'description' => 'Completion percentage 0-100. Optional.'],
            ],
            'required' => ['message'],
        ],
    ],
];

// ─── Tool handlers ───────────────────────────────────────────────────────────

function handleToolCall(string $name, array $args, \Redis $redis, string $userId, string $taskId, string $projectId, array $ITEM_TRANSITIONS): array
{
    switch ($name) {
        case 'cc_check_task_status':
            return handleCheckTaskStatus($args, $redis);

        case 'cc_get_task_output':
            return handleGetTaskOutput($args, $redis);

        case 'cc_list_tasks':
            return handleListTasks($args, $redis);

        case 'cc_create_task':
            return handleCreateTask($args, $redis, $userId, $taskId, $projectId);

        case 'cc_search_memory':
            return handleSearchMemory($args, $redis, $userId, $projectId);

        case 'cc_store_memory':
            return handleStoreMemory($args, $redis, $userId, $projectId);

        case 'cc_list_items':
            return handleListItems($args, $redis, $projectId);

        case 'cc_update_item':
            return handleUpdateItem($args, $redis, $ITEM_TRANSITIONS);

        case 'cc_list_projects':
            return handleListProjects($args, $redis);

        case 'cc_report_progress':
            return handleReportProgress($args, $redis, $taskId);

        default:
            return toolError("Unknown tool: {$name}");
    }
}

function handleCheckTaskStatus(array $args, \Redis $redis): array
{
    $id = $args['task_id'] ?? '';
    if ($id === '') {
        return toolError('task_id is required');
    }

    $task = $redis->hGetAll("cc:tasks:{$id}");
    if (empty($task)) {
        return toolError("Task {$id} not found");
    }

    $info = [
        'id'         => $task['id'] ?? $id,
        'state'      => $task['state'] ?? 'unknown',
        'prompt'     => mb_substr($task['prompt'] ?? '', 0, 200),
        'created_at' => $task['created_at'] ?? '',
        'updated_at' => $task['updated_at'] ?? '',
    ];

    if (($task['state'] ?? '') === 'completed') {
        $info['result_preview'] = mb_substr($task['result'] ?? '', 0, 500);
    }
    if (($task['state'] ?? '') === 'failed') {
        $info['error'] = mb_substr($task['error'] ?? '', 0, 500);
    }
    if (!empty($task['progress'])) {
        $info['progress'] = json_decode($task['progress'], true);
    }

    return toolResult(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleGetTaskOutput(array $args, \Redis $redis): array
{
    $id = $args['task_id'] ?? '';
    if ($id === '') {
        return toolError('task_id is required');
    }

    $task = $redis->hGetAll("cc:tasks:{$id}");
    if (empty($task)) {
        return toolError("Task {$id} not found");
    }

    $state = $task['state'] ?? 'unknown';
    if ($state !== 'completed' && $state !== 'failed') {
        return toolResult("Task {$id} is still {$state}. Output not available yet.");
    }

    $info = [
        'id'     => $task['id'] ?? $id,
        'state'  => $state,
        'result' => $task['result'] ?? '',
        'error'  => $task['error'] ?? '',
    ];

    return toolResult(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleListTasks(array $args, \Redis $redis): array
{
    $stateFilter = $args['state'] ?? '';
    $limit = min(max((int) ($args['limit'] ?? 20), 1), 50);

    // Fetch more than limit to allow filtering
    $fetchCount = $stateFilter !== '' ? $limit * 3 : $limit;
    $taskIds = $redis->zRevRange('cc:task_index', 0, $fetchCount - 1);

    $tasks = [];
    foreach ($taskIds as $id) {
        $task = $redis->hGetAll("cc:tasks:{$id}");
        if (empty($task)) {
            continue;
        }
        $state = $task['state'] ?? 'unknown';
        if ($stateFilter !== '' && $state !== $stateFilter) {
            continue;
        }
        $tasks[] = [
            'id'         => $task['id'] ?? $id,
            'state'      => $state,
            'prompt'     => mb_substr($task['prompt'] ?? '', 0, 100),
            'created_at' => $task['created_at'] ?? '',
        ];
        if (count($tasks) >= $limit) {
            break;
        }
    }

    return toolResult(json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleCreateTask(array $args, \Redis $redis, string $userId, string $parentTaskId, string $projectId): array
{
    $prompt = $args['prompt'] ?? '';
    if ($prompt === '') {
        return toolError('prompt is required');
    }

    $agentType = $args['agent_type'] ?? 'project';
    $taskProjectId = $args['project_id'] ?? $projectId;

    $id = generateUuid();
    $now = time();
    $options = [
        'web_user_id'   => $userId,
        'agent_type'    => $agentType,
        'project_id'    => $taskProjectId,
        'dispatch_mode' => 'supervisor',
        'parent_task_id' => $parentTaskId,
    ];
    if (isset($args['model']) && $args['model'] !== '') {
        $options['model'] = $args['model'];
    }

    $redis->hMSet("cc:tasks:{$id}", [
        'id'         => $id,
        'prompt'     => $prompt,
        'session_id' => '',
        'state'      => 'pending',
        'result'     => '',
        'error'      => '',
        'options'    => json_encode($options),
        'created_at' => (string) $now,
        'updated_at' => (string) $now,
    ]);
    $redis->zAdd('cc:task_index', $now, $id);
    if ($userId !== '') {
        $redis->zAdd("cc:user:tasks:{$userId}", $now, $id);
    }

    fwrite(STDERR, "cc-system-mcp: created sub-task {$id}\n");

    return toolResult(json_encode([
        'id'      => $id,
        'state'   => 'pending',
        'message' => 'Task created and queued for supervisor pickup.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleSearchMemory(array $args, \Redis $redis, string $userId, string $projectId): array
{
    $query = $args['query'] ?? '';
    if ($query === '') {
        return toolError('query is required');
    }

    $limit = min(max((int) ($args['limit'] ?? 10), 1), 30);
    $keywords = array_filter(array_map('strtolower', preg_split('/\s+/', $query)));

    if (empty($keywords)) {
        return toolError('query must contain searchable keywords');
    }

    $results = [];

    // Search general pool
    $generalEntries = $redis->zRevRange("cc:memories:{$userId}", 0, 199);
    foreach ($generalEntries as $entry) {
        $mem = json_decode($entry, true);
        if (!is_array($mem)) {
            continue;
        }
        $content = strtolower($mem['content'] ?? '');
        $category = strtolower($mem['category'] ?? '');
        $matchCount = 0;
        foreach ($keywords as $kw) {
            if (str_contains($content, $kw) || str_contains($category, $kw)) {
                $matchCount++;
            }
        }
        if ($matchCount > 0) {
            $results[] = ['score' => $matchCount, 'memory' => $mem, 'pool' => 'general'];
        }
    }

    // Search project-scoped pool
    if ($projectId !== '' && $projectId !== 'general') {
        $projectEntries = $redis->zRevRange("cc:memories:{$userId}:{$projectId}", 0, 199);
        foreach ($projectEntries as $entry) {
            $mem = json_decode($entry, true);
            if (!is_array($mem)) {
                continue;
            }
            $content = strtolower($mem['content'] ?? '');
            $category = strtolower($mem['category'] ?? '');
            $matchCount = 0;
            foreach ($keywords as $kw) {
                if (str_contains($content, $kw) || str_contains($category, $kw)) {
                    $matchCount++;
                }
            }
            if ($matchCount > 0) {
                $results[] = ['score' => $matchCount, 'memory' => $mem, 'pool' => $projectId];
            }
        }
    }

    // Sort by match count descending, then limit
    usort($results, fn($a, $b) => $b['score'] - $a['score']);
    $results = array_slice($results, 0, $limit);

    $output = array_map(fn($r) => [
        'id'         => $r['memory']['id'] ?? '',
        'category'   => $r['memory']['category'] ?? '',
        'content'    => $r['memory']['content'] ?? '',
        'importance' => $r['memory']['importance'] ?? 0.5,
        'pool'       => $r['pool'],
        'created_at' => $r['memory']['created_at'] ?? '',
    ], $results);

    return toolResult(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleStoreMemory(array $args, \Redis $redis, string $userId, string $defaultProjectId): array
{
    $content = $args['content'] ?? '';
    if ($content === '') {
        return toolError('content is required');
    }

    $category = $args['category'] ?? 'insight';
    $importance = max(0.0, min(1.0, (float) ($args['importance'] ?? 0.5)));
    $memProjectId = $args['project_id'] ?? $defaultProjectId;

    $id = generateMemoryId();
    $now = time();

    $entry = [
        'id'         => $id,
        'category'   => $category,
        'content'    => $content,
        'importance' => $importance,
        'source'     => 'mcp',
        'created_at' => $now,
    ];

    if ($memProjectId !== '' && $memProjectId !== 'general') {
        $entry['project_id'] = $memProjectId;
        $redis->zAdd("cc:memories:{$userId}:{$memProjectId}", $now, json_encode($entry));
    } else {
        $redis->zAdd("cc:memories:{$userId}", $now, json_encode($entry));
    }

    fwrite(STDERR, "cc-system-mcp: stored memory {$id} ({$category})\n");

    return toolResult(json_encode([
        'id'       => $id,
        'category' => $category,
        'pool'     => ($memProjectId !== '' && $memProjectId !== 'general') ? $memProjectId : 'general',
        'message'  => 'Memory stored successfully.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleListItems(array $args, \Redis $redis, string $defaultProjectId): array
{
    $itemProjectId = $args['project_id'] ?? $defaultProjectId;
    $stateFilter = $args['state'] ?? '';

    if ($itemProjectId === '' || $itemProjectId === 'general') {
        return toolError('A specific project_id is required to list items.');
    }

    $itemIds = $redis->zRevRange("cc:project:{$itemProjectId}:items", 0, 99);
    $items = [];

    foreach ($itemIds as $id) {
        $item = $redis->hGetAll("cc:items:{$id}");
        if (empty($item)) {
            continue;
        }
        $state = $item['state'] ?? 'open';
        if ($stateFilter !== '' && $state !== $stateFilter) {
            continue;
        }
        $items[] = [
            'id'          => $item['id'] ?? $id,
            'title'       => $item['title'] ?? '',
            'state'       => $state,
            'priority'    => $item['priority'] ?? 'medium',
            'epic_id'     => $item['epic_id'] ?? '',
            'assigned_to' => $item['assigned_to'] ?? '',
        ];
    }

    return toolResult(json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleUpdateItem(array $args, \Redis $redis, array $ITEM_TRANSITIONS): array
{
    $itemId = $args['item_id'] ?? '';
    $newState = $args['state'] ?? '';

    if ($itemId === '' || $newState === '') {
        return toolError('item_id and state are required');
    }

    $item = $redis->hGetAll("cc:items:{$itemId}");
    if (empty($item)) {
        return toolError("Item {$itemId} not found");
    }

    $currentState = $item['state'] ?? 'open';
    $allowed = $ITEM_TRANSITIONS[$currentState] ?? [];

    if (!in_array($newState, $allowed, true)) {
        return toolError("Cannot transition item from '{$currentState}' to '{$newState}'. Allowed: " . implode(', ', $allowed));
    }

    $updates = [
        'state'      => $newState,
        'updated_at' => (string) time(),
    ];
    if ($newState === 'done') {
        $updates['completed_at'] = (string) time();
    }

    $redis->hMSet("cc:items:{$itemId}", $updates);
    fwrite(STDERR, "cc-system-mcp: updated item {$itemId}: {$currentState} → {$newState}\n");

    return toolResult(json_encode([
        'id'        => $itemId,
        'old_state' => $currentState,
        'new_state' => $newState,
        'message'   => "Item updated: {$currentState} → {$newState}",
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleListProjects(array $args, \Redis $redis): array
{
    $limit = min(max((int) ($args['limit'] ?? 20), 1), 50);
    $projectIds = $redis->zRevRange('cc:project_index', 0, $limit - 1);

    $projects = [];
    foreach ($projectIds as $id) {
        $project = $redis->hGetAll("cc:projects:{$id}");
        if (empty($project)) {
            continue;
        }
        $projects[] = [
            'id'          => $project['id'] ?? $id,
            'name'        => $project['name'] ?? '',
            'state'       => $project['state'] ?? '',
            'description' => mb_substr($project['description'] ?? '', 0, 200),
            'cwd'         => $project['cwd'] ?? '',
        ];
    }

    return toolResult(json_encode($projects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function handleReportProgress(array $args, \Redis $redis, string $taskId): array
{
    if ($taskId === '') {
        return toolError('No current task context (CC_TASK_ID not set)');
    }

    $message = $args['message'] ?? '';
    if ($message === '') {
        return toolError('message is required');
    }

    $progress = [
        'message'    => $message,
        'updated_at' => time(),
    ];
    if (isset($args['percent'])) {
        $progress['percent'] = max(0, min(100, (int) $args['percent']));
    }

    $redis->hSet("cc:tasks:{$taskId}", 'progress', json_encode($progress));
    $redis->hSet("cc:tasks:{$taskId}", 'updated_at', (string) time());

    return toolResult(json_encode([
        'message' => 'Progress updated.',
    ]));
}

// ─── Protocol loop ───────────────────────────────────────────────────────────

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $request = json_decode($line, true);
    if (!is_array($request)) {
        fwrite(STDOUT, jsonError(null, -32700, 'Parse error'));
        fflush(STDOUT);
        continue;
    }

    $id = $request['id'] ?? null;
    $method = $request['method'] ?? '';
    $params = $request['params'] ?? [];

    fwrite(STDERR, "cc-system-mcp: ← {$method}" . ($id !== null ? " (id={$id})" : '') . "\n");

    // Notifications (no id) — don't send a response
    if ($id === null) {
        if ($method === 'notifications/initialized' || $method === 'notifications/cancelled') {
            // acknowledged, no response
        } else {
            fwrite(STDERR, "cc-system-mcp: ignoring unknown notification: {$method}\n");
        }
        continue;
    }

    $response = match ($method) {
        'initialize' => jsonResponse($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => ['tools' => new \stdClass()],
            'serverInfo'      => [
                'name'    => 'cc-system',
                'version' => '1.0.0',
            ],
        ]),

        'ping' => jsonResponse($id, new \stdClass()),

        'tools/list' => jsonResponse($id, ['tools' => $toolDefinitions]),

        'tools/call' => (function () use ($id, $params, $redis, $userId, $taskId, $projectId, $ITEM_TRANSITIONS) {
            $toolName = $params['name'] ?? '';
            $toolArgs = $params['arguments'] ?? [];
            if ($toolName === '') {
                return jsonError($id, -32602, 'Missing tool name');
            }
            $result = handleToolCall($toolName, $toolArgs, $redis, $userId, $taskId, $projectId, $ITEM_TRANSITIONS);
            return jsonResponse($id, $result);
        })(),

        default => jsonError($id, -32601, "Method not found: {$method}"),
    };

    fwrite(STDOUT, $response);
    fflush(STDOUT);
}

fwrite(STDERR, "cc-system-mcp: stdin closed, exiting\n");
