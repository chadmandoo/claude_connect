<?php

declare(strict_types=1);

namespace App\Web;

use App\Agent\AgentManager;
use App\Claude\SessionManager;
use App\Conversation\ConversationManager;
use App\Embedding\EmbeddingService;
use App\Epic\EpicManager;
use App\Epic\EpicState;
use App\Item\ItemManager;
use App\Item\ItemPriority;
use App\Item\ItemState;
use App\Memory\MemoryManager;
use App\Note\NoteManager;
use App\Project\ProjectManager;
use App\Scheduler\SchedulerManager;
use App\StateMachine\TaskManager;
use App\Storage\PostgresStore;
use App\Storage\RedisStore;
use App\Storage\SwooleTableCache;
use App\Todo\TodoManager;
use DateTime;
use Hyperf\Di\Annotation\Inject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Throwable;

/**
 * Swoole WebSocket server handler managing connection lifecycle and message routing.
 *
 * Handles open/close/message events, authenticates connections, dispatches incoming
 * JSON messages to appropriate managers (chat, memory, project, epic, item, todo,
 * notes, scheduler, agents), and manages heartbeat ping/pong.
 */
class WebSocketHandler
{
    #[Inject]
    private WebAuthManager $authManager;

    #[Inject]
    private ChatManager $chatManager;

    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private ConversationManager $conversationManager;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private EpicManager $epicManager;

    #[Inject]
    private ItemManager $itemManager;

    #[Inject]
    private NoteManager $noteManager;

    #[Inject]
    private TodoManager $todoManager;

    #[Inject]
    private EmbeddingService $embeddingService;

    #[Inject]
    private SessionManager $sessionManager;

    #[Inject]
    private SchedulerManager $schedulerManager;

    #[Inject]
    private AgentManager $agentManager;

    #[Inject]
    private SwooleTableCache $cache;

    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private RedisStore $redis;

    #[Inject]
    private TaskNotifier $taskNotifier;

    #[Inject]
    private ContainerInterface $container;

    #[Inject]
    private LoggerInterface $logger;

    private int $pingInterval = 30;

    private ?Server $serverInstance = null;

    /**
     * Connection opened: validates token from query param, sends auth status.
     */
    public function onOpen(Server $server, Request $request): void
    {
        // Ensure TaskNotifier has server reference for broadcasting
        $this->taskNotifier->setServer($server);

        $fd = $request->fd;

        // Validate auth token from query string
        $token = $request->get['token'] ?? '';
        $authenticated = $token !== '' && $this->authManager->validateToken($token);

        if ($authenticated) {
            $userId = $this->authManager->getUserId();
            $this->cache->setWsConnection($fd, $userId);
            $this->logger->info("WebSocket: authenticated connection fd={$fd} userId={$userId}");

            $this->pushJson($server, $fd, [
                'type' => 'auth.ok',
                'user_id' => $userId,
            ]);

            // Start ping timer
            $this->startPingTimer($server, $fd);
        } else {
            $this->logger->info("WebSocket: unauthenticated connection fd={$fd}");
            $this->pushJson($server, $fd, ['type' => 'auth.required']);
        }
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $data = json_decode($frame->data, true);

        if (!is_array($data) || !isset($data['type'])) {
            $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Invalid message format']);

            return;
        }

        $type = $data['type'];

        // Auth message can be sent without being authenticated
        if ($type === 'auth') {
            $this->handleAuth($server, $fd, $data);

            return;
        }

        // Pong: update last-seen timestamp
        if ($type === 'pong') {
            $this->cache->updateWsConnectionPong($fd);

            return;
        }

        // All other messages require authentication
        $conn = $this->cache->getWsConnection($fd);
        if (!$conn) {
            $this->pushJson($server, $fd, ['type' => 'auth.required']);

            return;
        }

        // Dispatch to handler in a coroutine
        \Swoole\Coroutine::create(function () use ($server, $fd, $type, $data) {
            try {
                $this->dispatch($server, $fd, $type, $data);
            } catch (Throwable $e) {
                $this->logger->error("WebSocket dispatch error: {$e->getMessage()}");
                $this->pushJson($server, $fd, [
                    'type' => 'error',
                    'id' => $data['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function onClose(mixed $server, int $fd, int $reactorId): void
    {
        // onClose fires for all connections (HTTP + WS), only clean up tracked WS connections
        $conn = $this->cache->getWsConnection($fd);
        if ($conn) {
            $this->logger->info("WebSocket: closed fd={$fd} userId={$conn['user_id']}");
            $this->cache->removeWsConnection($fd);
        }
    }

    private function handleAuth(Server $server, int $fd, array $data): void
    {
        $password = $data['password'] ?? '';
        $token = $this->authManager->authenticate($password);

        if ($token === null) {
            $this->pushJson($server, $fd, ['type' => 'auth.error', 'error' => 'Invalid password']);

            return;
        }

        $userId = $this->authManager->getUserId();
        $this->cache->setWsConnection($fd, $userId);
        $this->logger->info("WebSocket: authenticated via message fd={$fd} userId={$userId}");

        $this->pushJson($server, $fd, [
            'type' => 'auth.ok',
            'user_id' => $userId,
            'token' => $token,
        ]);

        $this->startPingTimer($server, $fd);
    }

    private function dispatch(Server $server, int $fd, string $type, array $data): void
    {
        switch ($type) {
            case 'chat.send':
                $prompt = trim($data['prompt'] ?? '');
                if ($prompt === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Empty prompt']);

                    return;
                }
                // Validate images: array of {data: base64, media_type: string}
                $images = [];
                if (!empty($data['images']) && is_array($data['images'])) {
                    foreach ($data['images'] as $img) {
                        if (!is_array($img) || empty($img['data']) || empty($img['media_type'])) {
                            continue;
                        }
                        if (!in_array($img['media_type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                            continue;
                        }
                        $images[] = [
                            'data' => $img['data'],
                            'media_type' => $img['media_type'],
                        ];
                    }
                }
                $this->chatManager->sendChat(
                    $server,
                    $fd,
                    $prompt,
                    $data['template'] ?? null,
                    $data['parent_task_id'] ?? null,
                    $data['conversation_id'] ?? null,
                    $images,
                    $data['agent_id'] ?? null,
                );
                break;

            case 'tasks.list':
                $state = $data['state'] ?? null;
                $limit = min((int) ($data['limit'] ?? 50), 100);
                $allTasks = $this->taskManager->listTasks($state !== '' ? $state : null, $limit * 2);
                // Filter out internal system tasks
                $tasks = array_values(array_filter($allTasks, function (array $task) {
                    $source = $task['source'] ?? ($task['conversation_id'] ?? '' !== '' ? 'web' : 'system');

                    return $source === 'web';
                }));
                $tasks = array_slice($tasks, 0, $limit);
                // Strip large fields for list view
                $stripped = array_map(function (array $task) {
                    return [
                        'id' => $task['id'] ?? '',
                        'prompt' => mb_substr($task['prompt'] ?? '', 0, 200),
                        'state' => $task['state'] ?? '',
                        'cost_usd' => (float) ($task['cost_usd'] ?? 0),
                        'created_at' => (int) ($task['created_at'] ?? 0),
                        'completed_at' => (int) ($task['completed_at'] ?? 0),
                        'claude_session_id' => $task['claude_session_id'] ?? '',
                        'parent_task_id' => $task['parent_task_id'] ?? '',
                        'conversation_id' => $task['conversation_id'] ?? '',
                        'project_id' => $task['project_id'] ?? json_decode($task['options'] ?? '{}', true)['project_id'] ?? 'general',
                        'workflow_template' => json_decode($task['options'] ?? '{}', true)['workflow_template'] ?? '',
                    ];
                }, $tasks);
                $this->pushJson($server, $fd, [
                    'type' => 'tasks.list',
                    'id' => $data['id'] ?? null,
                    'tasks' => $stripped,
                ]);
                break;

            case 'tasks.get':
                $taskId = $data['task_id'] ?? '';
                if ($taskId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing task_id']);

                    return;
                }
                $task = $this->taskManager->getTask($taskId);
                if (!$task) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Task not found']);

                    return;
                }
                $this->pushJson($server, $fd, [
                    'type' => 'tasks.detail',
                    'id' => $data['id'] ?? null,
                    'task' => [
                        'id' => $task['id'] ?? '',
                        'prompt' => $task['prompt'] ?? '',
                        'state' => $task['state'] ?? '',
                        'result' => $task['result'] ?? '',
                        'error' => $task['error'] ?? '',
                        'cost_usd' => (float) ($task['cost_usd'] ?? 0),
                        'created_at' => (int) ($task['created_at'] ?? 0),
                        'started_at' => (int) ($task['started_at'] ?? 0),
                        'completed_at' => (int) ($task['completed_at'] ?? 0),
                        'claude_session_id' => $task['claude_session_id'] ?? '',
                        'parent_task_id' => $task['parent_task_id'] ?? '',
                        'conversation_id' => $task['conversation_id'] ?? '',
                        'project_id' => $task['project_id'] ?? json_decode($task['options'] ?? '{}', true)['project_id'] ?? 'general',
                    ],
                ]);
                break;

            case 'memory.get':
                $memoryId = $data['memory_id'] ?? '';
                if ($memoryId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing memory_id']);

                    return;
                }
                $userId = $this->authManager->getUserId();
                $memory = $this->memoryManager->getMemory($userId, $memoryId);
                if ($memory === null) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Memory not found']);

                    return;
                }
                $this->pushJson($server, $fd, [
                    'type' => 'memory.detail',
                    'id' => $data['id'] ?? null,
                    'memory' => $memory,
                ]);
                break;

            case 'memory.list':
                $userId = $this->authManager->getUserId();
                $memProjectId = $data['project_id'] ?? null;
                $facts = $this->memoryManager->getFacts($userId);
                $projectMemories = [];
                if ($memProjectId && $memProjectId !== '' && $memProjectId !== 'general') {
                    // Scoped to a specific project: general + project memories
                    $memories = $this->memoryManager->getStructuredMemories($userId, 200);
                    $count = $this->memoryManager->getStructuredMemoryCount($userId);
                    $projectMemories = $this->memoryManager->getProjectMemories($userId, $memProjectId, 200);
                } else {
                    // No project filter: return ALL memories (general + all project-scoped)
                    $memories = $this->memoryManager->getAllMemories($userId, 200);
                    $count = $this->memoryManager->getAllMemoryCount($userId);
                }
                $this->pushJson($server, $fd, [
                    'type' => 'memory.list',
                    'id' => $data['id'] ?? null,
                    'facts' => $facts,
                    'memories' => $memories,
                    'project_memories' => $projectMemories,
                    'count' => $count,
                    'project_id' => $memProjectId,
                ]);
                break;

            case 'memory.update':
                $memoryId = $data['memory_id'] ?? '';
                if ($memoryId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing memory_id']);

                    return;
                }
                $userId = $this->authManager->getUserId();
                // Look up the memory's current project to scope the update query
                $existingMem = $this->memoryManager->getMemory($userId, $memoryId);
                $currentProjectId = $existingMem['project_id'] ?? null;
                $updateData = [];
                if (isset($data['content'])) {
                    $updateData['content'] = $data['content'];
                }
                if (isset($data['importance'])) {
                    $updateData['importance'] = $data['importance'];
                }
                if (isset($data['category'])) {
                    $updateData['category'] = $data['category'];
                }
                if (isset($data['memory_type'])) {
                    $updateData['type'] = $data['memory_type'];
                }
                if (isset($data['agent_scope'])) {
                    $updateData['agent_scope'] = $data['agent_scope'];
                }
                // Allow changing the project scope
                if (array_key_exists('project_id', $data)) {
                    $newProjectId = $data['project_id'];
                    $updateData['project_id'] = ($newProjectId !== null && $newProjectId !== '' && $newProjectId !== 'general') ? $newProjectId : null;
                }
                if (!empty($updateData)) {
                    $this->store->updateMemoryEntry($userId, $currentProjectId, $memoryId, $updateData);
                }
                $this->pushJson($server, $fd, [
                    'type' => 'memory.updated',
                    'id' => $data['id'] ?? null,
                    'memory_id' => $memoryId,
                ]);
                break;

            case 'memory.search':
                $query = trim($data['query'] ?? '');
                if ($query === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing query']);

                    return;
                }
                $userId = $this->authManager->getUserId();
                $searchProjectId = $data['project_id'] ?? null;
                $searchLimit = min((int) ($data['limit'] ?? 20), 50);

                if (!$this->embeddingService->isAvailable()) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Semantic search not configured (VOYAGE_API_KEY missing)']);

                    return;
                }

                $searchType = $data['search_type'] ?? 'all';
                $results = $this->embeddingService->semanticSearch(
                    $query,
                    $userId,
                    $searchProjectId !== '' ? $searchProjectId : null,
                    $searchLimit,
                    $searchType,
                );
                $this->pushJson($server, $fd, [
                    'type' => 'memory.search',
                    'id' => $data['id'] ?? null,
                    'query' => $query,
                    'results' => $results,
                ]);
                break;

            case 'memory.delete':
                $memoryId = $data['memory_id'] ?? '';
                if ($memoryId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing memory_id']);

                    return;
                }
                $userId = $this->authManager->getUserId();
                $this->memoryManager->deleteAnyMemory($userId, $memoryId);
                $this->pushJson($server, $fd, [
                    'type' => 'memory.deleted',
                    'id' => $data['id'] ?? null,
                    'memory_id' => $memoryId,
                ]);
                break;

            case 'memory.create':
                $content = trim($data['content'] ?? '');
                if ($content === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing content']);

                    return;
                }
                $userId = $this->authManager->getUserId();
                $category = $data['category'] ?? 'context';
                $importance = $data['importance'] ?? 'normal';
                $memType = $data['memory_type'] ?? $data['type_value'] ?? 'project';
                $agentScope = $data['agent_scope'] ?? '*';
                $memProjectId = $data['project_id'] ?? null;
                if ($memProjectId && $memProjectId !== '' && $memProjectId !== 'general') {
                    $memoryId = $this->memoryManager->storeProjectMemory(
                        $userId,
                        $memProjectId,
                        $category,
                        $content,
                        $importance,
                        'web',
                        $memType,
                        $agentScope,
                    );
                } else {
                    $memoryId = $this->memoryManager->storeMemory(
                        $userId,
                        $category,
                        $content,
                        $importance,
                        'web',
                        $memType,
                        $agentScope,
                    );
                }
                $this->pushJson($server, $fd, [
                    'type' => 'memory.created',
                    'id' => $data['id'] ?? null,
                    'memory_id' => $memoryId,
                ]);
                break;

            case 'conversations.list':
                $projectId = $data['project_id'] ?? null;
                $limit = min((int) ($data['limit'] ?? 30), 100);
                $showArchived = (bool) ($data['show_archived'] ?? false);
                $typeFilter = $data['conv_type'] ?? null;
                $conversations = $this->conversationManager->listConversations(
                    $projectId !== '' ? $projectId : null,
                    $limit,
                );
                if (!$showArchived) {
                    $conversations = array_values(array_filter(
                        $conversations,
                        fn (array $c) =>
                        ($c['state'] ?? 'active') === 'active',
                    ));
                }
                if ($typeFilter !== null && $typeFilter !== '') {
                    $conversations = array_values(array_filter(
                        $conversations,
                        fn (array $c) =>
                        ($c['type'] ?? '') === $typeFilter,
                    ));
                }
                $this->pushJson($server, $fd, [
                    'type' => 'conversations.list',
                    'id' => $data['id'] ?? null,
                    'conversations' => $conversations,
                ]);
                break;

            case 'conversations.get':
                $convId = $data['conversation_id'] ?? '';
                if ($convId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing conversation_id']);

                    return;
                }
                $conv = $this->conversationManager->getConversation($convId);
                if (!$conv) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Conversation not found']);

                    return;
                }
                $turns = $this->conversationManager->getConversationTurns($convId);
                $this->pushJson($server, $fd, [
                    'type' => 'conversations.detail',
                    'id' => $data['id'] ?? null,
                    'conversation' => $conv,
                    'turns' => $turns,
                ]);
                break;

            case 'conversations.update':
                $convId = $data['conversation_id'] ?? '';
                if ($convId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing conversation_id']);

                    return;
                }
                $convUpdate = [];
                if (isset($data['title']) && trim($data['title']) !== '') {
                    $convUpdate['title'] = trim($data['title']);
                }
                if (!empty($convUpdate)) {
                    $convUpdate['updated_at'] = time();
                    $this->store->updateConversation($convId, $convUpdate);
                }
                $this->pushJson($server, $fd, [
                    'type' => 'conversations.updated',
                    'id' => $data['id'] ?? null,
                    'conversation_id' => $convId,
                ]);
                break;

            case 'conversations.complete':
                $convId = $data['conversation_id'] ?? '';
                if ($convId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing conversation_id']);

                    return;
                }
                $this->conversationManager->completeConversation($convId);
                $this->pushJson($server, $fd, [
                    'type' => 'conversations.completed',
                    'id' => $data['id'] ?? null,
                    'conversation_id' => $convId,
                ]);
                break;

            case 'projects.list':
                $workspaces = $this->projectManager->listWorkspaces();
                $stripped = array_map(function (array $p) {
                    $pid = $p['id'] ?? '';
                    $itemCounts = $pid !== '' ? $this->itemManager->getProjectItemCounts($pid) : [];

                    return [
                        'id' => $pid,
                        'name' => $p['name'] ?? $p['goal'] ?? 'Unnamed',
                        'description' => $p['description'] ?? '',
                        'state' => $p['state'] ?? '',
                        'cwd' => $p['cwd'] ?? '',
                        'total_cost_usd' => (float) ($p['total_cost_usd'] ?? 0),
                        'created_at' => (int) ($p['created_at'] ?? 0),
                        'updated_at' => (int) ($p['updated_at'] ?? 0),
                        'item_counts' => $itemCounts,
                    ];
                }, $workspaces);
                $this->pushJson($server, $fd, [
                    'type' => 'projects.list',
                    'id' => $data['id'] ?? null,
                    'projects' => $stripped,
                ]);
                break;

            case 'projects.get':
                $projectId = $data['project_id'] ?? '';
                if ($projectId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project_id']);

                    return;
                }
                $project = $this->projectManager->getProject($projectId);
                if (!$project) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Project not found']);

                    return;
                }
                $itemCounts = $this->itemManager->getProjectItemCounts($projectId);
                $epicCount = count($this->epicManager->listEpics($projectId));
                $this->pushJson($server, $fd, [
                    'type' => 'projects.detail',
                    'id' => $data['id'] ?? null,
                    'project' => [
                        'id' => $project['id'] ?? '',
                        'name' => $project['name'] ?? $project['goal'] ?? 'Unnamed',
                        'description' => $project['description'] ?? '',
                        'state' => $project['state'] ?? '',
                        'cwd' => $project['cwd'] ?? '',
                        'total_cost_usd' => (float) ($project['total_cost_usd'] ?? 0),
                        'created_at' => (int) ($project['created_at'] ?? 0),
                        'updated_at' => (int) ($project['updated_at'] ?? 0),
                        'epic_count' => $epicCount,
                        'item_counts' => $itemCounts,
                    ],
                ]);
                break;

            case 'projects.create':
                $projectName = trim($data['name'] ?? '');
                if ($projectName === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project name']);

                    return;
                }
                $existing = $this->projectManager->getByName($projectName);
                if ($existing) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => "Project '{$projectName}' already exists"]);

                    return;
                }
                $userId = $this->authManager->getUserId();
                $projectId = $this->projectManager->createWorkspace(
                    $projectName,
                    $data['description'] ?? '',
                    $userId,
                    $data['cwd'] ?? null,
                );
                $this->pushJson($server, $fd, [
                    'type' => 'projects.created',
                    'id' => $data['id'] ?? null,
                    'project_id' => $projectId,
                    'name' => $projectName,
                ]);
                break;

            case 'projects.update':
                $projectId = $data['project_id'] ?? '';
                if ($projectId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project_id']);

                    return;
                }
                $project = $this->projectManager->getProject($projectId);
                if (!$project) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Project not found']);

                    return;
                }
                $updateData = [];
                if (isset($data['name'])) {
                    $updateData['name'] = $data['name'];
                }
                if (isset($data['description'])) {
                    $updateData['description'] = $data['description'];
                }
                if (isset($data['cwd'])) {
                    $updateData['cwd'] = $data['cwd'];
                }
                if (array_key_exists('default_agent_id', $data)) {
                    $updateData['default_agent_id'] = $data['default_agent_id'] ?: null;
                }
                if (!empty($updateData)) {
                    $this->projectManager->updateWorkspace($projectId, $updateData);
                }
                $updatedProject = $this->projectManager->getProject($projectId);
                $this->pushJson($server, $fd, [
                    'type' => 'projects.updated',
                    'id' => $data['id'] ?? null,
                    'project_id' => $projectId,
                    'project' => $updatedProject,
                ]);
                break;

            case 'projects.delete':
                $projectId = $data['project_id'] ?? '';
                if ($projectId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project_id']);

                    return;
                }
                $project = $this->projectManager->getProject($projectId);
                if (!$project) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Project not found']);

                    return;
                }
                $projectName = $project['name'] ?? $project['goal'] ?? '';
                if (strtolower($projectName) === 'general') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Cannot delete the General project']);

                    return;
                }
                // Delete all epics and items in the project
                $epics = $this->epicManager->listEpics($projectId);
                foreach ($epics as $epic) {
                    $items = $this->store->listEpicItems($epic['id']);
                    foreach ($items as $item) {
                        $this->itemManager->deleteItem($item['id']);
                    }
                    $this->store->deleteEpic($epic['id']);
                }
                // Delete the project itself
                $this->store->deleteProject($projectId);
                $this->pushJson($server, $fd, [
                    'type' => 'projects.deleted',
                    'id' => $data['id'] ?? null,
                    'project_id' => $projectId,
                ]);
                break;

            case 'tasks.delete':
                $taskId = $data['task_id'] ?? '';
                if ($taskId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing task_id']);

                    return;
                }
                $task = $this->taskManager->getTask($taskId);
                if (!$task) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Task not found']);

                    return;
                }
                $taskState = $task['state'] ?? '';
                if ($taskState === 'running' || $taskState === 'pending') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Cannot delete a ' . $taskState . ' task']);

                    return;
                }
                $userId = $this->authManager->getUserId();
                $this->store->removeUserTask($userId, $taskId);
                $this->taskManager->deleteTask($taskId);
                $this->pushJson($server, $fd, [
                    'type' => 'tasks.deleted',
                    'id' => $data['id'] ?? null,
                    'task_id' => $taskId,
                ]);
                break;

            case 'conversations.archive':
                $convId = $data['conversation_id'] ?? '';
                if ($convId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing conversation_id']);

                    return;
                }
                $conv = $this->conversationManager->getConversation($convId);
                if (!$conv) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Conversation not found']);

                    return;
                }
                $this->conversationManager->completeConversation($convId);
                $this->pushJson($server, $fd, [
                    'type' => 'conversations.archived',
                    'id' => $data['id'] ?? null,
                    'conversation_id' => $convId,
                ]);
                break;

            case 'conversations.delete':
                $convId = $data['conversation_id'] ?? '';
                if ($convId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing conversation_id']);

                    return;
                }
                $this->conversationManager->deleteConversation($convId);
                $this->pushJson($server, $fd, [
                    'type' => 'conversations.deleted',
                    'id' => $data['id'] ?? null,
                    'conversation_id' => $convId,
                ]);
                break;

            case 'epics.list':
                $projectId = $data['project_id'] ?? '';
                if ($projectId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project_id']);

                    return;
                }
                $this->epicManager->ensureBacklogEpic($projectId);
                $epics = $this->epicManager->listEpics($projectId);
                $epicsWithCounts = array_map(function (array $epic) {
                    $items = $this->store->listEpicItems($epic['id']);
                    $counts = ['open' => 0, 'in_progress' => 0, 'review' => 0, 'blocked' => 0, 'done' => 0, 'cancelled' => 0];
                    foreach ($items as $item) {
                        $s = $item['state'] ?? 'open';
                        if (isset($counts[$s])) {
                            $counts[$s]++;
                        }
                    }
                    $epic['item_counts'] = $counts;
                    $total = count($items);
                    $epic['progress'] = $total > 0
                        ? round(($counts['done'] + $counts['cancelled']) / $total * 100)
                        : 0;

                    return $epic;
                }, $epics);
                $this->pushJson($server, $fd, [
                    'type' => 'epics.list',
                    'id' => $data['id'] ?? null,
                    'epics' => $epicsWithCounts,
                ]);
                break;

            case 'epics.create':
                $projectId = $data['project_id'] ?? '';
                if ($projectId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project_id']);

                    return;
                }
                $title = trim($data['title'] ?? '');
                if ($title === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing title']);

                    return;
                }
                $epicId = $this->epicManager->createEpic(
                    $projectId,
                    $title,
                    $data['description'] ?? '',
                );
                $this->pushJson($server, $fd, [
                    'type' => 'epics.created',
                    'id' => $data['id'] ?? null,
                    'epic_id' => $epicId,
                    'project_id' => $projectId,
                ]);
                break;

            case 'epics.update':
                $epicId = $data['epic_id'] ?? '';
                if ($epicId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing epic_id']);

                    return;
                }
                $updateData = [];
                if (isset($data['title'])) {
                    $updateData['title'] = $data['title'];
                }
                if (isset($data['description'])) {
                    $updateData['description'] = $data['description'];
                }

                if (isset($data['state'])) {
                    $targetState = EpicState::tryFrom($data['state']);
                    if ($targetState === null) {
                        $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Invalid epic state']);

                        return;
                    }
                    $this->epicManager->transition($epicId, $targetState);
                }

                if (!empty($updateData)) {
                    $this->epicManager->updateEpic($epicId, $updateData);
                }

                $epic = $this->epicManager->getEpic($epicId);
                $this->pushJson($server, $fd, [
                    'type' => 'epics.updated',
                    'id' => $data['id'] ?? null,
                    'epic_id' => $epicId,
                    'epic' => $epic,
                ]);
                break;

            case 'epics.reorder':
                $projectId = $data['project_id'] ?? '';
                if ($projectId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project_id']);

                    return;
                }
                $epicIds = $data['epic_ids'] ?? [];
                if (!is_array($epicIds) || empty($epicIds)) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing epic_ids array']);

                    return;
                }
                foreach ($epicIds as $i => $eid) {
                    $epic = $this->epicManager->getEpic($eid);
                    if ($epic && ($epic['is_backlog'] ?? '0') !== '1') {
                        $sortOrder = $i + 1;
                        $this->store->updateEpic($eid, ['sort_order' => $sortOrder, 'updated_at' => time()]);
                        // Update the zset score for ordering
                        $this->store->addEpicToProject($projectId, $eid, (float) $sortOrder);
                    }
                }
                $this->pushJson($server, $fd, [
                    'type' => 'epics.reordered',
                    'id' => $data['id'] ?? null,
                    'project_id' => $projectId,
                ]);
                break;

            case 'epics.delete':
                $epicId = $data['epic_id'] ?? '';
                if ($epicId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing epic_id']);

                    return;
                }
                $epic = $this->epicManager->getEpic($epicId);
                if (!$epic) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Epic not found']);

                    return;
                }
                $this->epicManager->deleteEpic($epicId, $epic['project_id']);
                $this->pushJson($server, $fd, [
                    'type' => 'epics.deleted',
                    'id' => $data['id'] ?? null,
                    'epic_id' => $epicId,
                    'project_id' => $epic['project_id'],
                ]);
                break;

            case 'items.list':
                $projectId = $data['project_id'] ?? '';
                $epicId = $data['epic_id'] ?? '';
                if ($epicId !== '') {
                    $items = $this->itemManager->listItemsByEpic($epicId);
                } elseif ($projectId !== '') {
                    $stateFilter = $data['state'] ?? null;
                    $items = $this->itemManager->listItemsByProject($projectId, $stateFilter !== '' ? $stateFilter : null);
                } else {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project_id or epic_id']);

                    return;
                }
                $this->pushJson($server, $fd, [
                    'type' => 'items.list',
                    'id' => $data['id'] ?? null,
                    'items' => $items,
                ]);
                break;

            case 'items.create':
                $projectId = $data['project_id'] ?? '';
                if ($projectId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing project_id']);

                    return;
                }
                $title = trim($data['title'] ?? '');
                if ($title === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing title']);

                    return;
                }
                $priority = $data['priority'] ?? 'normal';
                if (ItemPriority::tryFrom($priority) === null) {
                    $priority = 'normal';
                }
                $itemId = $this->itemManager->createItem(
                    $projectId,
                    $title,
                    $data['epic_id'] ?? null,
                    $data['description'] ?? '',
                    $priority,
                    $data['conversation_id'] ?? '',
                );
                $item = $this->itemManager->getItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'items.created',
                    'id' => $data['id'] ?? null,
                    'item_id' => $itemId,
                    'item' => $item,
                ]);
                break;

            case 'items.update':
                $itemId = $data['item_id'] ?? '';
                if ($itemId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id']);

                    return;
                }
                $updateData = [];
                if (isset($data['title'])) {
                    $updateData['title'] = $data['title'];
                }
                if (isset($data['description'])) {
                    $updateData['description'] = $data['description'];
                }
                if (isset($data['priority'])) {
                    if (ItemPriority::tryFrom($data['priority']) !== null) {
                        $updateData['priority'] = $data['priority'];
                    }
                }

                if (isset($data['state'])) {
                    $targetState = ItemState::tryFrom($data['state']);
                    if ($targetState === null) {
                        $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Invalid item state']);

                        return;
                    }
                    $this->itemManager->transition($itemId, $targetState);
                }

                if (!empty($updateData)) {
                    $this->itemManager->updateItem($itemId, $updateData);
                }

                $item = $this->itemManager->getItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'items.updated',
                    'id' => $data['id'] ?? null,
                    'item_id' => $itemId,
                    'item' => $item,
                ]);
                break;

            case 'items.move':
                $itemId = $data['item_id'] ?? '';
                $epicId = $data['epic_id'] ?? '';
                if ($itemId === '' || $epicId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id or epic_id']);

                    return;
                }
                $this->itemManager->moveToEpic($itemId, $epicId);
                $item = $this->itemManager->getItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'items.moved',
                    'id' => $data['id'] ?? null,
                    'item_id' => $itemId,
                    'item' => $item,
                ]);
                break;

            case 'items.reorder':
                $epicId = $data['epic_id'] ?? '';
                if ($epicId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing epic_id']);

                    return;
                }
                $itemIds = $data['item_ids'] ?? [];
                if (!is_array($itemIds) || empty($itemIds)) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_ids array']);

                    return;
                }
                foreach ($itemIds as $i => $iid) {
                    $sortOrder = $i + 1;
                    $this->store->removeItemFromEpic($epicId, $iid);
                    $this->store->addItemToEpic($epicId, $iid, (float) $sortOrder);
                    $this->store->updateItem($iid, ['sort_order' => $sortOrder, 'updated_at' => time()]);
                }
                $this->pushJson($server, $fd, [
                    'type' => 'items.reordered',
                    'id' => $data['id'] ?? null,
                    'epic_id' => $epicId,
                ]);
                break;

            case 'items.delete':
                $itemId = $data['item_id'] ?? '';
                if ($itemId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id']);

                    return;
                }
                $item = $this->itemManager->getItem($itemId);
                if (!$item) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Item not found']);

                    return;
                }
                $this->itemManager->deleteItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'items.deleted',
                    'id' => $data['id'] ?? null,
                    'item_id' => $itemId,
                    'project_id' => $item['project_id'] ?? '',
                ]);
                break;

            case 'nightly.status':
                $nightlyHistory = $this->store->getNightlyRunHistory(1);
                $lastRun = $nightlyHistory[0] ?? null;
                $configService = $this->container->get(\Hyperf\Contract\ConfigInterface::class);
                $nightlyConfig = $configService->get('mcp.nightly', []);
                $runHour = $nightlyConfig['run_hour'] ?? 2;
                $runMinute = $nightlyConfig['run_minute'] ?? 0;
                $nextRun = new DateTime('today ' . sprintf('%02d:%02d', $runHour, $runMinute));
                if ($nextRun->getTimestamp() <= time()) {
                    $nextRun->modify('+1 day');
                }
                $this->pushJson($server, $fd, [
                    'type' => 'nightly.status',
                    'id' => $data['id'] ?? null,
                    'last_run' => $lastRun ? ($lastRun['timestamp'] ?? $lastRun) : 'never',
                    'last_run_stats' => $lastRun,
                    'next_run' => $nextRun->format('Y-m-d H:i:s'),
                    'lock_held' => $this->redis->hasLock('nightly:lock'),
                    'history' => $this->store->getNightlyRunHistory(10),
                ]);
                break;

            case 'memory.analytics':
                $userId = $this->authManager->getUserId();
                $analytics = $this->container->get(\App\Memory\MemoryAnalytics::class);
                $overview = $analytics->getOverview($userId);
                $this->pushJson($server, $fd, array_merge(
                    ['type' => 'memory.analytics', 'id' => $data['id'] ?? null],
                    $overview,
                ));
                break;

            case 'items.notes':
                $itemId = $data['item_id'] ?? '';
                if ($itemId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id']);

                    return;
                }
                $notes = $this->store->getItemNotes($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'items.notes',
                    'id' => $data['id'] ?? null,
                    'item_id' => $itemId,
                    'notes' => $notes,
                ]);
                break;

            case 'items.addNote':
                $itemId = $data['item_id'] ?? '';
                $noteContent = trim($data['content'] ?? '');
                if ($itemId === '' || $noteContent === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id or content']);

                    return;
                }
                $userId = $this->authManager->getUserId();
                $this->store->addItemNote($itemId, $noteContent, $userId);
                $this->pushJson($server, $fd, [
                    'type' => 'items.noteAdded',
                    'id' => $data['id'] ?? null,
                    'item_id' => $itemId,
                ]);
                break;

            case 'items.assign':
                $itemId = $data['item_id'] ?? '';
                $assignee = $data['assignee'] ?? '';
                if ($itemId === '' || $assignee === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id or assignee']);

                    return;
                }
                $this->itemManager->assignItem($itemId, $assignee);
                $item = $this->itemManager->getItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'items.assigned',
                    'id' => $data['id'] ?? null,
                    'item_id' => $itemId,
                    'assignee' => $assignee,
                    'item' => $item,
                ]);
                break;

            case 'sessions.list':
                $sessions = $this->sessionManager->listSessions();
                $this->pushJson($server, $fd, [
                    'type' => 'sessions.list',
                    'id' => $data['id'] ?? null,
                    'sessions' => $sessions,
                ]);
                break;

                // conversations.detail is an alias for conversations.get for the new React frontend
            case 'conversations.detail':
                // Forward to conversations.get handler
                $this->dispatch($server, $fd, 'conversations.get', $data);
                break;

            case 'channels.list':
                $channels = $this->store->getChannels();
                // Attach agents to each channel
                foreach ($channels as &$ch) {
                    $ch['agents'] = $this->store->getRoomAgents($ch['id']);
                }
                unset($ch);
                $this->pushJson($server, $fd, [
                    'type' => 'channels.list',
                    'id' => $data['id'] ?? null,
                    'channels' => $channels,
                ]);
                break;

            case 'channels.create':
                $channelName = trim($data['name'] ?? '');
                $channelDesc = trim($data['description'] ?? '');
                if ($channelName === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Channel name required']);

                    return;
                }
                $channelId = uniqid('ch_', true);
                $channel = [
                    'id' => $channelId,
                    'name' => $channelName,
                    'description' => $channelDesc,
                    'member_count' => 1,
                    'created_at' => time(),
                ];
                $this->store->saveChannel($channel);
                // Add agents to the new channel
                $agentIds = $data['agent_ids'] ?? [];
                if (is_array($agentIds)) {
                    foreach ($agentIds as $i => $agId) {
                        $this->agentManager->addAgentToRoom($channelId, $agId, $i === 0);
                    }
                }
                $channel['agents'] = $this->store->getRoomAgents($channelId);
                $this->pushJson($server, $fd, [
                    'type' => 'channels.created',
                    'id' => $data['id'] ?? null,
                    'channel' => $channel,
                ]);
                break;

            case 'channels.detail':
                $channelId = $data['channel_id'] ?? '';
                if ($channelId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing channel_id']);

                    return;
                }
                $channel = $this->store->getChannel($channelId);
                if (!$channel) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Channel not found']);

                    return;
                }
                $channelMessages = $this->store->getChannelMessages($channelId);
                $roomAgents = $this->agentManager->getRoomAgents($channelId);
                $this->pushJson($server, $fd, [
                    'type' => 'channels.detail',
                    'id' => $data['id'] ?? null,
                    'channel' => $channel,
                    'messages' => $channelMessages,
                    'agents' => $roomAgents,
                ]);
                break;

            case 'channels.send':
                $channelId = $data['channel_id'] ?? '';
                $content = trim($data['content'] ?? '');
                if ($channelId === '' || $content === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing channel_id or content']);

                    return;
                }
                $conn = $this->cache->getWsConnection($fd);
                $author = $conn['user_id'] ?? 'User';
                $message = [
                    'id' => uniqid('msg_', true),
                    'channel_id' => $channelId,
                    'author' => $author,
                    'content' => $content,
                    'created_at' => time(),
                ];
                $this->store->saveChannelMessage($channelId, $message);
                // Broadcast to all connected clients
                $this->pushJson($server, $fd, [
                    'type' => 'channels.send',
                    'id' => $data['id'] ?? null,
                    'message' => $message,
                ]);
                // Broadcast to other connections
                $allConns = $this->cache->getWsConnections();
                foreach ($allConns as $otherFd => $otherConn) {
                    if ((int) $otherFd !== $fd && $server->isEstablished((int) $otherFd)) {
                        $this->pushJson($server, (int) $otherFd, [
                            'type' => 'channels.message',
                            'channel_id' => $channelId,
                            'message' => $message,
                        ]);
                    }
                }

                // Route message to an agent:
                // 1. @slug mention → route to that specific agent
                // 2. No mention → route to channel's default agent (if any)
                $mentionedAgent = null;
                if (preg_match('/@(\w[\w-]*)/', $content, $mentionMatches)) {
                    $mentionSlug = $mentionMatches[1];
                    if (strtolower($mentionSlug) === 'claude') {
                        $mentionedAgent = $this->agentManager->getRoomDefaultAgent($channelId);
                        if (!$mentionedAgent) {
                            $mentionedAgent = $this->agentManager->getDefaultAgent();
                        }
                    } else {
                        $mentionedAgent = $this->store->getAgentBySlug($mentionSlug);
                    }
                } else {
                    // No @ mention — route to channel's default agent
                    $mentionedAgent = $this->agentManager->getRoomDefaultAgent($channelId);
                }

                if ($mentionedAgent !== null) {
                    $channel = $this->store->getChannel($channelId);
                    $channelName = $channel['name'] ?? 'unknown';
                    $agentForReply = $mentionedAgent;
                    \Swoole\Coroutine::create(function () use ($server, $channelId, $content, $channelName, $agentForReply) {
                        try {
                            $this->chatManager->sendRoomAgentReply($server, $channelId, $content, $channelName, $agentForReply);
                        } catch (Throwable $e) {
                            $this->logger->error("Channel agent reply failed: {$e->getMessage()}");
                        }
                    });
                }
                break;

            case 'channels.update':
                $channelId = $data['channel_id'] ?? '';
                if ($channelId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing channel_id']);

                    return;
                }
                $channel = $this->store->getChannel($channelId);
                if (!$channel) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Channel not found']);

                    return;
                }
                if (isset($data['name']) && trim($data['name']) !== '') {
                    $channel['name'] = trim($data['name']);
                }
                if (isset($data['description'])) {
                    $channel['description'] = trim($data['description']);
                }
                $this->store->saveChannel($channel);
                $channel['agents'] = $this->store->getRoomAgents($channelId);
                $this->pushJson($server, $fd, [
                    'type' => 'channels.updated',
                    'id' => $data['id'] ?? null,
                    'channel' => $channel,
                ]);
                break;

            case 'channels.delete':
                $channelId = $data['channel_id'] ?? '';
                if ($channelId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing channel_id']);

                    return;
                }
                $this->store->deleteChannel($channelId);
                $this->pushJson($server, $fd, [
                    'type' => 'channels.deleted',
                    'id' => $data['id'] ?? null,
                    'channel_id' => $channelId,
                ]);
                break;

            case 'scheduler.list':
                $jobs = $this->schedulerManager->listJobs();
                $this->pushJson($server, $fd, [
                    'type' => 'scheduler.list',
                    'id' => $data['id'] ?? null,
                    'jobs' => $jobs,
                ]);
                break;

            case 'scheduler.toggle':
                $jobId = $data['job_id'] ?? '';
                $enabled = (bool) ($data['enabled'] ?? false);
                if ($jobId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing job_id']);

                    return;
                }
                $success = $this->schedulerManager->toggleJob($jobId, $enabled);
                $this->pushJson($server, $fd, [
                    'type' => 'scheduler.toggled',
                    'id' => $data['id'] ?? null,
                    'job_id' => $jobId,
                    'enabled' => $enabled,
                    'success' => $success,
                ]);
                break;

            case 'scheduler.create':
                $newJob = $this->schedulerManager->createJob($data);
                $this->pushJson($server, $fd, [
                    'type' => 'scheduler.created',
                    'id' => $data['id'] ?? null,
                    'job_id' => $newJob,
                ]);
                break;

            case 'scheduler.delete':
                $jobId = $data['job_id'] ?? '';
                if ($jobId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing job_id']);

                    return;
                }
                $this->schedulerManager->deleteJob($jobId);
                $this->pushJson($server, $fd, [
                    'type' => 'scheduler.deleted',
                    'id' => $data['id'] ?? null,
                    'job_id' => $jobId,
                ]);
                break;

                // =====================================================================
                // System Docs
                // =====================================================================

            case 'docs.list':
                $docsDir = BASE_PATH . '/docs';
                $docFiles = [];
                if (is_dir($docsDir)) {
                    foreach (glob($docsDir . '/*.md') as $file) {
                        $filename = basename($file);
                        $slug = pathinfo($filename, PATHINFO_FILENAME);
                        $content = file_get_contents($file);
                        // Extract title from first # heading
                        $title = $slug;
                        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
                            $title = $m[1];
                        }
                        $docFiles[] = [
                            'slug' => $slug,
                            'filename' => $filename,
                            'title' => $title,
                            'size' => strlen($content),
                            'updated_at' => filemtime($file),
                        ];
                    }
                    usort($docFiles, fn ($a, $b) => strcmp($a['title'], $b['title']));
                }
                $this->pushJson($server, $fd, [
                    'type' => 'docs.list',
                    'id' => $data['id'] ?? null,
                    'docs' => $docFiles,
                ]);
                break;

            case 'docs.get':
                $slug = $data['slug'] ?? '';
                if ($slug === '' || preg_match('/[\/\\\\.]/', $slug)) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Invalid slug']);

                    return;
                }
                $filePath = BASE_PATH . '/docs/' . $slug . '.md';
                if (!is_file($filePath)) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Document not found']);

                    return;
                }
                $content = file_get_contents($filePath);
                $title = $slug;
                if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
                    $title = $m[1];
                }
                $this->pushJson($server, $fd, [
                    'type' => 'docs.detail',
                    'id' => $data['id'] ?? null,
                    'doc' => [
                        'slug' => $slug,
                        'title' => $title,
                        'content' => $content,
                        'updated_at' => filemtime($filePath),
                    ],
                ]);
                break;

                // =====================================================================
                // Agent CRUD
                // =====================================================================

            case 'agents.list':
                $agentProjectId = $data['project_id'] ?? null;
                $agents = $this->agentManager->listAgents($agentProjectId !== '' ? $agentProjectId : null);
                $this->pushJson($server, $fd, [
                    'type' => 'agents.list',
                    'id' => $data['id'] ?? null,
                    'agents' => $agents,
                ]);
                break;

            case 'agents.get':
                $agentId = $data['agent_id'] ?? '';
                if ($agentId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing agent_id']);

                    return;
                }
                $agent = $this->agentManager->getAgent($agentId);
                if (!$agent) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Agent not found']);

                    return;
                }
                $this->pushJson($server, $fd, [
                    'type' => 'agents.detail',
                    'id' => $data['id'] ?? null,
                    'agent' => $agent,
                ]);
                break;

            case 'agents.create':
                $slug = trim($data['slug'] ?? '');
                $name = trim($data['name'] ?? '');
                if ($slug === '' || $name === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing slug or name']);

                    return;
                }

                try {
                    $agentId = $this->agentManager->createAgent([
                        'slug' => $slug,
                        'name' => $name,
                        'description' => $data['description'] ?? '',
                        'system_prompt' => $data['system_prompt'] ?? '',
                        'model' => $data['model'] ?? '',
                        'tool_access' => $data['tool_access'] ?? [],
                        'project_id' => $data['project_id'] ?? null,
                        'memory_scope' => $data['memory_scope'] ?? '',
                        'is_default' => (bool) ($data['is_default'] ?? false),
                        'color' => $data['color'] ?? '#6366f1',
                        'icon' => $data['icon'] ?? 'bot',
                    ]);
                    $agent = $this->agentManager->getAgent($agentId);
                    $this->pushJson($server, $fd, [
                        'type' => 'agents.created',
                        'id' => $data['id'] ?? null,
                        'agent' => $agent,
                    ]);
                } catch (Throwable $e) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => $e->getMessage()]);
                }
                break;

            case 'agents.update':
                $agentId = $data['agent_id'] ?? '';
                if ($agentId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing agent_id']);

                    return;
                }

                try {
                    $updateFields = [];
                    foreach (['slug', 'name', 'description', 'system_prompt', 'model', 'tool_access', 'project_id', 'memory_scope', 'is_default', 'color', 'icon'] as $field) {
                        if (array_key_exists($field, $data)) {
                            $updateFields[$field] = $data[$field];
                        }
                    }
                    $this->agentManager->updateAgent($agentId, $updateFields);
                    $agent = $this->agentManager->getAgent($agentId);
                    $this->pushJson($server, $fd, [
                        'type' => 'agents.updated',
                        'id' => $data['id'] ?? null,
                        'agent' => $agent,
                    ]);
                } catch (Throwable $e) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => $e->getMessage()]);
                }
                break;

            case 'agents.delete':
                $agentId = $data['agent_id'] ?? '';
                if ($agentId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing agent_id']);

                    return;
                }

                try {
                    $this->agentManager->deleteAgent($agentId);
                    $this->pushJson($server, $fd, [
                        'type' => 'agents.deleted',
                        'id' => $data['id'] ?? null,
                        'agent_id' => $agentId,
                    ]);
                } catch (Throwable $e) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => $e->getMessage()]);
                }
                break;

            case 'agents.seed':
                $this->agentManager->seedDefaultAgents();
                $this->agentManager->backfillConversationAgents();
                $this->pushJson($server, $fd, [
                    'type' => 'agents.seeded',
                    'id' => $data['id'] ?? null,
                ]);
                break;

                // =====================================================================
                // Room agent management
                // =====================================================================

            case 'rooms.add_agent':
                $roomId = $data['room_id'] ?? $data['channel_id'] ?? '';
                $agentId = $data['agent_id'] ?? '';
                if ($roomId === '' || $agentId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing room_id or agent_id']);

                    return;
                }
                $this->agentManager->addAgentToRoom($roomId, $agentId, (bool) ($data['is_default'] ?? false));
                $this->pushJson($server, $fd, [
                    'type' => 'rooms.agent_added',
                    'id' => $data['id'] ?? null,
                    'room_id' => $roomId,
                    'agent_id' => $agentId,
                ]);
                break;

            case 'rooms.remove_agent':
                $roomId = $data['room_id'] ?? $data['channel_id'] ?? '';
                $agentId = $data['agent_id'] ?? '';
                if ($roomId === '' || $agentId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing room_id or agent_id']);

                    return;
                }
                $this->agentManager->removeAgentFromRoom($roomId, $agentId);
                $this->pushJson($server, $fd, [
                    'type' => 'rooms.agent_removed',
                    'id' => $data['id'] ?? null,
                    'room_id' => $roomId,
                    'agent_id' => $agentId,
                ]);
                break;

            case 'rooms.set_default':
                $roomId = $data['room_id'] ?? $data['channel_id'] ?? '';
                $agentId = $data['agent_id'] ?? '';
                if ($roomId === '' || $agentId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => 'Missing room_id or agent_id']);

                    return;
                }
                $this->agentManager->setRoomDefaultAgent($roomId, $agentId);
                $this->pushJson($server, $fd, [
                    'type' => 'rooms.default_set',
                    'id' => $data['id'] ?? null,
                    'room_id' => $roomId,
                    'agent_id' => $agentId,
                ]);
                break;

                // =====================================================================
                // Notebooks & Pages (Notes)
                // =====================================================================

            case 'notebooks.list':
                $notebooks = $this->noteManager->listNotebooks();
                // Attach page count to each notebook
                foreach ($notebooks as &$nb) {
                    $pages = $this->store->listNotebookPages($nb['id']);
                    $nb['page_count'] = count($pages);
                }
                unset($nb);
                $this->pushJson($server, $fd, [
                    'type' => 'notebooks.list',
                    'id' => $data['id'] ?? null,
                    'notebooks' => $notebooks,
                ]);
                break;

            case 'notebooks.create':
                $title = trim($data['title'] ?? '');
                if ($title === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing title']);

                    return;
                }
                $notebookId = $this->noteManager->createNotebook(
                    $title,
                    $data['description'] ?? '',
                    $data['color'] ?? 'slate',
                    $data['icon'] ?? 'notebook',
                );
                $notebook = $this->noteManager->getNotebook($notebookId);
                $notebook['page_count'] = 0;
                $this->pushJson($server, $fd, [
                    'type' => 'notebooks.created',
                    'id' => $data['id'] ?? null,
                    'notebook' => $notebook,
                ]);
                break;

            case 'notebooks.update':
                $notebookId = $data['notebook_id'] ?? '';
                if ($notebookId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing notebook_id']);

                    return;
                }
                $updateData = [];
                if (isset($data['title'])) {
                    $updateData['title'] = $data['title'];
                }
                if (isset($data['description'])) {
                    $updateData['description'] = $data['description'];
                }
                if (isset($data['color'])) {
                    $updateData['color'] = $data['color'];
                }
                if (isset($data['icon'])) {
                    $updateData['icon'] = $data['icon'];
                }
                if (!empty($updateData)) {
                    $this->noteManager->updateNotebook($notebookId, $updateData);
                }
                $notebook = $this->noteManager->getNotebook($notebookId);
                $this->pushJson($server, $fd, [
                    'type' => 'notebooks.updated',
                    'id' => $data['id'] ?? null,
                    'notebook' => $notebook,
                ]);
                break;

            case 'notebooks.delete':
                $notebookId = $data['notebook_id'] ?? '';
                if ($notebookId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing notebook_id']);

                    return;
                }
                $this->noteManager->deleteNotebook($notebookId);
                $this->pushJson($server, $fd, [
                    'type' => 'notebooks.deleted',
                    'id' => $data['id'] ?? null,
                    'notebook_id' => $notebookId,
                ]);
                break;

            case 'pages.list':
                $notebookId = $data['notebook_id'] ?? '';
                if ($notebookId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing notebook_id']);

                    return;
                }
                $pages = $this->noteManager->listPages($notebookId);
                $this->pushJson($server, $fd, [
                    'type' => 'pages.list',
                    'id' => $data['id'] ?? null,
                    'notebook_id' => $notebookId,
                    'pages' => $pages,
                ]);
                break;

            case 'pages.get':
                $pageId = $data['page_id'] ?? '';
                if ($pageId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing page_id']);

                    return;
                }
                $page = $this->noteManager->getPage($pageId);
                if (!$page) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Page not found']);

                    return;
                }
                $this->pushJson($server, $fd, [
                    'type' => 'pages.detail',
                    'id' => $data['id'] ?? null,
                    'page' => $page,
                ]);
                break;

            case 'pages.create':
                $notebookId = $data['notebook_id'] ?? '';
                if ($notebookId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing notebook_id']);

                    return;
                }
                $title = trim($data['title'] ?? '');
                if ($title === '') {
                    $title = 'Untitled';
                }
                $pageId = $this->noteManager->createPage(
                    $notebookId,
                    $title,
                    $data['content'] ?? '',
                );
                $page = $this->noteManager->getPage($pageId);
                $this->pushJson($server, $fd, [
                    'type' => 'pages.created',
                    'id' => $data['id'] ?? null,
                    'page' => $page,
                ]);
                break;

            case 'pages.update':
                $pageId = $data['page_id'] ?? '';
                if ($pageId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing page_id']);

                    return;
                }
                $updateData = [];
                if (isset($data['title'])) {
                    $updateData['title'] = $data['title'];
                }
                if (isset($data['content'])) {
                    $updateData['content'] = $data['content'];
                }
                if (isset($data['pinned'])) {
                    $updateData['pinned'] = $data['pinned'];
                }
                if (!empty($updateData)) {
                    $this->noteManager->updatePage($pageId, $updateData);
                }
                $page = $this->noteManager->getPage($pageId);
                $this->pushJson($server, $fd, [
                    'type' => 'pages.updated',
                    'id' => $data['id'] ?? null,
                    'page' => $page,
                ]);
                break;

            case 'pages.move':
                $pageId = $data['page_id'] ?? '';
                $targetNotebookId = $data['notebook_id'] ?? '';
                if ($pageId === '' || $targetNotebookId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing page_id or notebook_id']);

                    return;
                }
                $this->noteManager->movePage($pageId, $targetNotebookId);
                $page = $this->noteManager->getPage($pageId);
                $this->pushJson($server, $fd, [
                    'type' => 'pages.moved',
                    'id' => $data['id'] ?? null,
                    'page' => $page,
                ]);
                break;

            case 'pages.delete':
                $pageId = $data['page_id'] ?? '';
                if ($pageId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing page_id']);

                    return;
                }
                $page = $this->noteManager->getPage($pageId);
                if (!$page) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Page not found']);

                    return;
                }
                $notebookId = $page['notebook_id'] ?? '';
                $this->noteManager->deletePage($pageId);
                $this->pushJson($server, $fd, [
                    'type' => 'pages.deleted',
                    'id' => $data['id'] ?? null,
                    'page_id' => $pageId,
                    'notebook_id' => $notebookId,
                ]);
                break;

                // =====================================================================
                // Todo Sections & Items
                // =====================================================================

            case 'todos.list':
                $sections = $this->todoManager->listSections();
                // Attach items and counts to each section
                foreach ($sections as &$sec) {
                    $items = $this->todoManager->listItems($sec['id']);
                    $sec['items'] = $items;
                    $sec['counts'] = $this->todoManager->getSectionCounts($sec['id']);
                }
                unset($sec);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.list',
                    'id' => $data['id'] ?? null,
                    'sections' => $sections,
                ]);
                break;

            case 'todos.createSection':
                $title = trim($data['title'] ?? '');
                if ($title === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing title']);

                    return;
                }
                $sectionId = $this->todoManager->createSection($title, $data['color'] ?? 'slate');
                $section = $this->todoManager->getSection($sectionId);
                $section['items'] = [];
                $section['counts'] = ['total' => 0, 'done' => 0, 'remaining' => 0];
                $this->pushJson($server, $fd, [
                    'type' => 'todos.sectionCreated',
                    'id' => $data['id'] ?? null,
                    'section' => $section,
                ]);
                break;

            case 'todos.updateSection':
                $sectionId = $data['section_id'] ?? '';
                if ($sectionId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing section_id']);

                    return;
                }
                $updateData = [];
                if (isset($data['title'])) {
                    $updateData['title'] = $data['title'];
                }
                if (isset($data['color'])) {
                    $updateData['color'] = $data['color'];
                }
                if (isset($data['collapsed'])) {
                    $updateData['collapsed'] = $data['collapsed'];
                }
                if (!empty($updateData)) {
                    $this->todoManager->updateSection($sectionId, $updateData);
                }
                $section = $this->todoManager->getSection($sectionId);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.sectionUpdated',
                    'id' => $data['id'] ?? null,
                    'section' => $section,
                ]);
                break;

            case 'todos.deleteSection':
                $sectionId = $data['section_id'] ?? '';
                if ($sectionId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing section_id']);

                    return;
                }
                $this->todoManager->deleteSection($sectionId);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.sectionDeleted',
                    'id' => $data['id'] ?? null,
                    'section_id' => $sectionId,
                ]);
                break;

            case 'todos.reorderSections':
                $sectionIds = $data['section_ids'] ?? [];
                if (!is_array($sectionIds) || empty($sectionIds)) {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing section_ids']);

                    return;
                }
                $this->todoManager->reorderSections($sectionIds);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.sectionsReordered',
                    'id' => $data['id'] ?? null,
                ]);
                break;

            case 'todos.createItem':
                $sectionId = $data['section_id'] ?? '';
                if ($sectionId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing section_id']);

                    return;
                }
                $title = trim($data['title'] ?? '');
                if ($title === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing title']);

                    return;
                }
                $itemId = $this->todoManager->createItem(
                    $sectionId,
                    $title,
                    $data['priority'] ?? 'normal',
                    $data['note'] ?? '',
                    (int) ($data['due_date'] ?? 0),
                );
                $item = $this->todoManager->getItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.itemCreated',
                    'id' => $data['id'] ?? null,
                    'item' => $item,
                ]);
                break;

            case 'todos.updateItem':
                $itemId = $data['item_id'] ?? '';
                if ($itemId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id']);

                    return;
                }
                $updateData = [];
                if (isset($data['title'])) {
                    $updateData['title'] = $data['title'];
                }
                if (isset($data['note'])) {
                    $updateData['note'] = $data['note'];
                }
                if (isset($data['priority'])) {
                    $updateData['priority'] = $data['priority'];
                }
                if (isset($data['due_date'])) {
                    $updateData['due_date'] = (int) $data['due_date'];
                }
                if (!empty($updateData)) {
                    $this->todoManager->updateItem($itemId, $updateData);
                }
                $item = $this->todoManager->getItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.itemUpdated',
                    'id' => $data['id'] ?? null,
                    'item' => $item,
                ]);
                break;

            case 'todos.toggleItem':
                $itemId = $data['item_id'] ?? '';
                if ($itemId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id']);

                    return;
                }
                $newDone = $this->todoManager->toggleItem($itemId);
                $item = $this->todoManager->getItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.itemToggled',
                    'id' => $data['id'] ?? null,
                    'item' => $item,
                    'done' => $newDone,
                ]);
                break;

            case 'todos.deleteItem':
                $itemId = $data['item_id'] ?? '';
                if ($itemId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id']);

                    return;
                }
                $item = $this->todoManager->getItem($itemId);
                $sectionId = $item['section_id'] ?? '';
                $this->todoManager->deleteItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.itemDeleted',
                    'id' => $data['id'] ?? null,
                    'item_id' => $itemId,
                    'section_id' => $sectionId,
                ]);
                break;

            case 'todos.moveItem':
                $itemId = $data['item_id'] ?? '';
                $targetSectionId = $data['section_id'] ?? '';
                if ($itemId === '' || $targetSectionId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing item_id or section_id']);

                    return;
                }
                $this->todoManager->moveItem($itemId, $targetSectionId);
                $item = $this->todoManager->getItem($itemId);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.itemMoved',
                    'id' => $data['id'] ?? null,
                    'item' => $item,
                ]);
                break;

            case 'todos.clearCompleted':
                $sectionId = $data['section_id'] ?? '';
                if ($sectionId === '') {
                    $this->pushJson($server, $fd, ['type' => 'error', 'error' => 'Missing section_id']);

                    return;
                }
                $cleared = $this->todoManager->clearCompleted($sectionId);
                $this->pushJson($server, $fd, [
                    'type' => 'todos.completedCleared',
                    'id' => $data['id'] ?? null,
                    'section_id' => $sectionId,
                    'cleared' => $cleared,
                ]);
                break;

            default:
                $this->pushJson($server, $fd, ['type' => 'error', 'id' => $data['id'] ?? null, 'error' => "Unknown message type: {$type}"]);
        }
    }

    private function startPingTimer(Server $server, int $fd): void
    {
        \Swoole\Coroutine::create(function () use ($server, $fd) {
            $missedPongs = 0;
            $maxMissed = 3;

            while (true) {
                \Swoole\Coroutine::sleep($this->pingInterval);
                if (!$server->isEstablished($fd)) {
                    break;
                }
                $conn = $this->cache->getWsConnection($fd);
                if (!$conn) {
                    break;
                }

                // Check if client responded to the last ping
                $lastPong = (int) ($conn['last_pong'] ?? 0);
                $lastPing = (int) ($conn['last_ping'] ?? 0);

                if ($lastPing > 0 && $lastPong < $lastPing) {
                    $missedPongs++;
                    if ($missedPongs >= $maxMissed) {
                        $this->logger->info("WebSocket: closing stale connection fd={$fd} ({$missedPongs} missed pongs)");
                        $server->disconnect($fd, 1001, 'Heartbeat timeout');
                        $this->cache->removeWsConnection($fd);
                        break;
                    }
                } else {
                    $missedPongs = 0;
                }

                $this->cache->updateWsConnectionPing($fd);
                $this->pushJson($server, $fd, ['type' => 'ping', 'timestamp' => time()]);
            }
        });
    }

    private function getServer(): Server
    {
        if ($this->serverInstance === null) {
            $this->serverInstance = \Hyperf\Context\ApplicationContext::getContainer()->get(\Swoole\Server::class);
        }

        return $this->serverInstance;
    }

    private function pushJson(Server $server, int $fd, array $data): void
    {
        try {
            if ($server->isEstablished($fd)) {
                $server->push($fd, json_encode($data));
            }
        } catch (Throwable $e) {
            $this->logger->debug("WebSocket push failed for fd={$fd}: {$e->getMessage()}");
        }
    }
}
