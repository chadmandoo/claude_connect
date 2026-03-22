<?php

declare(strict_types=1);

namespace App\Chat;

use App\StateMachine\TaskManager;
use App\Claude\ProcessManager;
use App\Memory\MemoryManager;
use App\Embedding\EmbeddingService;
use App\Project\ProjectManager;
use App\Item\ItemManager;
use App\Agent\AgentManager;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Contract\ConfigInterface;
use Psr\Log\LoggerInterface;

class ChatToolHandler
{
    #[Inject]
    private TaskManager $taskManager;

    #[Inject]
    private ProcessManager $processManager;

    #[Inject]
    private MemoryManager $memoryManager;

    #[Inject]
    private EmbeddingService $embeddingService;

    #[Inject]
    private ProjectManager $projectManager;

    #[Inject]
    private ItemManager $itemManager;

    #[Inject]
    private AgentManager $agentManager;

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    private string $userId = '';
    private string $conversationId = '';
    private string $agentId = '';

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    public function setConversationId(string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function setAgentId(string $agentId): void
    {
        $this->agentId = $agentId;
    }

    /**
     * Execute a tool by name and return the result.
     *
     * @return array JSON-serializable result
     */
    public function execute(string $toolName, array $input): array
    {
        return match ($toolName) {
            'create_task' => $this->createTask($input),
            'check_task_status' => $this->checkTaskStatus($input),
            'get_task_output' => $this->getTaskOutput($input),
            'cancel_task' => $this->cancelTask($input),
            'list_tasks' => $this->listTasks($input),
            'list_projects' => $this->listProjects($input),
            'create_project' => $this->createProject($input),
            'search_memory' => $this->searchMemory($input),
            'store_memory' => $this->storeMemory($input),
            'list_items' => $this->listItems($input),
            'create_item' => $this->createItem($input),
            'update_item' => $this->updateItem($input),
            'handoff_agent' => $this->handoffAgent($input),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    private function createTask(array $input): array
    {
        $prompt = $input['prompt'] ?? '';
        if ($prompt === '') {
            return ['error' => 'prompt is required'];
        }

        $projectId = $input['project_id'] ?? 'general';
        $priority = $input['priority'] ?? 'normal';
        $maxTurns = $input['max_turns'] ?? (int) $this->config->get('mcp.claude.max_turns', 25);
        $maxBudget = $input['max_budget_usd'] ?? (float) $this->config->get('mcp.claude.max_budget_usd', 5.00);

        $options = [
            'web_user_id' => $this->userId,
            'dispatch_mode' => 'supervisor',
            'agent_type' => ($projectId !== 'general') ? 'project' : 'pm',
            'project_id' => $projectId,
            'conversation_id' => $this->conversationId,
            'priority' => $priority,
            'max_turns' => $maxTurns,
            'max_budget_usd' => $maxBudget,
            'workflow_template' => 'standard',
        ];

        $taskId = $this->taskManager->createTask($prompt, null, $options);

        $this->logger->info("ChatToolHandler: created task {$taskId} for project {$projectId}");

        return [
            'task_id' => $taskId,
            'status' => 'pending',
            'message' => "Task created and queued for background execution.",
        ];
    }

    private function checkTaskStatus(array $input): array
    {
        $taskId = $input['task_id'] ?? '';
        if ($taskId === '') {
            return ['error' => 'task_id is required'];
        }

        $task = $this->taskManager->getTask($taskId);
        if ($task === null) {
            return ['error' => "Task {$taskId} not found"];
        }

        $result = [
            'task_id' => $taskId,
            'state' => $task['state'] ?? 'unknown',
            'created_at' => $task['created_at'] ?? '',
        ];

        if (!empty($task['cost_usd'])) {
            $result['cost_usd'] = (float) $task['cost_usd'];
        }
        if (!empty($task['error'])) {
            $result['error'] = $task['error'];
        }
        if (!empty($task['result'])) {
            $result['output_preview'] = mb_substr($task['result'], 0, 500);
        }

        return $result;
    }

    private function getTaskOutput(array $input): array
    {
        $taskId = $input['task_id'] ?? '';
        if ($taskId === '') {
            return ['error' => 'task_id is required'];
        }

        $task = $this->taskManager->getTask($taskId);
        if ($task === null) {
            return ['error' => "Task {$taskId} not found"];
        }

        return [
            'task_id' => $taskId,
            'state' => $task['state'] ?? 'unknown',
            'result' => $task['result'] ?? '',
            'cost_usd' => (float) ($task['cost_usd'] ?? 0),
        ];
    }

    private function cancelTask(array $input): array
    {
        $taskId = $input['task_id'] ?? '';
        if ($taskId === '') {
            return ['error' => 'task_id is required'];
        }

        $task = $this->taskManager->getTask($taskId);
        if ($task === null) {
            return ['error' => "Task {$taskId} not found"];
        }

        $state = $task['state'] ?? '';

        if ($state === 'running') {
            $pid = (int) ($task['pid'] ?? 0);
            if ($pid > 0) {
                posix_kill($pid, SIGTERM);
            }
        }

        if (in_array($state, ['pending', 'running'], true)) {
            $this->taskManager->setTaskError($taskId, 'Cancelled by user');
            $this->taskManager->transition($taskId, \App\StateMachine\TaskState::FAILED);
            return ['task_id' => $taskId, 'status' => 'cancelled'];
        }

        return ['task_id' => $taskId, 'status' => $state, 'message' => 'Task already in terminal state'];
    }

    private function listTasks(array $input): array
    {
        $state = $input['state'] ?? null;
        $limit = $input['limit'] ?? 10;

        $tasks = $this->taskManager->listTasks($state, (int) $limit);

        $items = [];
        foreach ($tasks as $task) {
            $items[] = [
                'task_id' => $task['id'] ?? '',
                'state' => $task['state'] ?? '',
                'prompt_preview' => mb_substr($task['prompt'] ?? '', 0, 100),
                'created_at' => $task['created_at'] ?? '',
                'cost_usd' => (float) ($task['cost_usd'] ?? 0),
            ];
        }

        return ['tasks' => $items, 'count' => count($items)];
    }

    private function listProjects(array $input): array
    {
        $workspaces = $this->projectManager->listWorkspaces();

        $items = [];
        foreach ($workspaces as $ws) {
            $items[] = [
                'project_id' => $ws['id'] ?? '',
                'name' => $ws['name'] ?? '',
                'description' => $ws['description'] ?? '',
                'cwd' => $ws['cwd'] ?? '',
            ];
        }

        return ['projects' => $items, 'count' => count($items)];
    }

    private function createProject(array $input): array
    {
        $name = $input['name'] ?? '';
        if ($name === '') {
            return ['error' => 'name is required'];
        }

        $description = $input['description'] ?? '';
        $cwd = $input['cwd'] ?? '';

        $projectId = $this->projectManager->createWorkspace($name, $description, $this->userId, $cwd ?: null);

        return [
            'project_id' => $projectId,
            'name' => $name,
            'message' => "Project workspace '{$name}' created.",
        ];
    }

    private function searchMemory(array $input): array
    {
        $query = $input['query'] ?? '';
        if ($query === '') {
            return ['error' => 'query is required'];
        }

        $projectId = $input['project_id'] ?? null;

        // Try semantic search first
        if ($this->embeddingService->isAvailable()) {
            $results = $this->embeddingService->semanticSearch(
                $query,
                $this->userId,
                $projectId,
                10,
                'all',
            );

            $items = [];
            foreach ($results as $r) {
                $items[] = [
                    'memory_id' => $r['memory_id'] ?? '',
                    'content' => $r['content'] ?? '',
                    'category' => $r['category'] ?? '',
                    'score' => $r['score'] ?? 0,
                ];
            }

            return ['results' => $items, 'count' => count($items), 'method' => 'semantic'];
        }

        // Fallback to keyword
        $memories = $this->memoryManager->getStructuredMemories($this->userId, 100);
        $queryLower = mb_strtolower($query);
        $matching = array_filter($memories, function ($m) use ($queryLower) {
            return str_contains(mb_strtolower($m['content'] ?? ''), $queryLower);
        });

        $items = [];
        foreach (array_slice(array_values($matching), 0, 10) as $m) {
            $items[] = [
                'memory_id' => $m['id'] ?? '',
                'content' => $m['content'] ?? '',
                'category' => $m['category'] ?? '',
            ];
        }

        return ['results' => $items, 'count' => count($items), 'method' => 'keyword'];
    }

    private function storeMemory(array $input): array
    {
        $content = $input['content'] ?? '';
        if ($content === '') {
            return ['error' => 'content is required'];
        }

        $category = $input['category'] ?? 'fact';
        $importance = $input['importance'] ?? 'normal';
        $projectId = $input['project_id'] ?? null;

        if ($projectId !== null && $projectId !== '' && $projectId !== 'general') {
            $memId = $this->memoryManager->storeProjectMemory(
                $this->userId, $projectId, $category, $content, $importance, 'chat_api'
            );
        } else {
            $memId = $this->memoryManager->storeMemory(
                $this->userId, $category, $content, $importance, 'chat_api'
            );
        }

        return ['memory_id' => $memId, 'message' => 'Memory stored.'];
    }

    private function listItems(array $input): array
    {
        $projectId = $input['project_id'] ?? '';
        if ($projectId === '') {
            return ['error' => 'project_id is required'];
        }

        $state = $input['state'] ?? null;
        $items = $this->itemManager->listItemsByProject($projectId, $state);

        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'item_id' => $item['id'] ?? '',
                'title' => $item['title'] ?? '',
                'state' => $item['state'] ?? '',
                'priority' => $item['priority'] ?? 'normal',
            ];
        }

        return ['items' => $result, 'count' => count($result)];
    }

    private function createItem(array $input): array
    {
        $projectId = $input['project_id'] ?? '';
        $title = $input['title'] ?? '';
        if ($projectId === '' || $title === '') {
            return ['error' => 'project_id and title are required'];
        }

        $description = $input['description'] ?? '';
        $priority = $input['priority'] ?? 'normal';
        $epicId = $input['epic_id'] ?? null;

        $itemId = $this->itemManager->createItem(
            $projectId, $title, $epicId, $description, $priority
        );

        return ['item_id' => $itemId, 'message' => "Work item '{$title}' created."];
    }

    private function updateItem(array $input): array
    {
        $itemId = $input['item_id'] ?? '';
        if ($itemId === '') {
            return ['error' => 'item_id is required'];
        }

        $item = $this->itemManager->getItem($itemId);
        if ($item === null) {
            return ['error' => "Item {$itemId} not found"];
        }

        // Handle state transition
        if (!empty($input['state'])) {
            $newState = \App\Item\ItemState::tryFrom($input['state']);
            if ($newState !== null) {
                $this->itemManager->transition($itemId, $newState);
            }
        }

        // Handle other updates
        $updates = [];
        if (isset($input['title'])) {
            $updates['title'] = $input['title'];
        }
        if (isset($input['description'])) {
            $updates['description'] = $input['description'];
        }
        if (isset($input['priority'])) {
            $updates['priority'] = $input['priority'];
        }

        if (!empty($updates)) {
            $this->itemManager->updateItem($itemId, $updates);
        }

        return ['item_id' => $itemId, 'message' => 'Item updated.'];
    }

    private function handoffAgent(array $input): array
    {
        $slug = $input['agent_slug'] ?? '';
        if ($slug === '') {
            return ['error' => 'agent_slug is required'];
        }

        $agent = $this->agentManager->getAgentBySlug($slug);
        if ($agent === null) {
            return ['error' => "Agent '{$slug}' not found"];
        }

        return [
            'agent_id' => $agent['id'],
            'agent_slug' => $agent['slug'],
            'agent_name' => $agent['name'],
            'message' => "Suggested handoff to {$agent['name']}. The user can switch to this agent to continue the conversation.",
        ];
    }
}
