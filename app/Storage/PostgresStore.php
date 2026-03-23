<?php

declare(strict_types=1);

namespace App\Storage;

use Hyperf\DbConnection\Db;
use Throwable;

/**
 * PostgreSQL data access layer providing CRUD operations for all persistent entities.
 *
 * Covers tasks, projects, conversations, memories, epics, items, agents, skills,
 * notebooks, pages, todos, schedules, and related metadata using Hyperf's DB facade.
 */
class PostgresStore
{
    // =========================================================================
    // Task operations
    // =========================================================================

    public function createTask(string $taskId, array $data): void
    {
        $data['conversation_id'] = $this->nullableUuid($data['conversation_id'] ?? '');
        Db::table('tasks')->insert($data);
    }

    public function getTask(string $taskId): ?array
    {
        $row = Db::table('tasks')->where('id', $taskId)->first();

        return $this->toArray($row);
    }

    public function updateTask(string $taskId, array $data): void
    {
        if (isset($data['conversation_id'])) {
            $data['conversation_id'] = $this->nullableUuid($data['conversation_id']);
        }
        Db::table('tasks')->where('id', $taskId)->update($data);
    }

    public function deleteTask(string $taskId): void
    {
        Db::table('tasks')->where('id', $taskId)->delete();
    }

    public function getOldTaskIds(int $olderThan, int $limit = 200): array
    {
        return Db::table('tasks')
            ->where('created_at', '<=', $olderThan)
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function listTasks(?string $state = null, int $limit = 50): array
    {
        $query = Db::table('tasks')->orderByDesc('created_at')->limit($limit);
        if ($state !== null) {
            $query->where('state', $state);
        }

        return $this->toArrayList($query->get());
    }

    // --- Task history ---

    public function addTaskHistory(string $taskId, array $entry): void
    {
        Db::table('task_history')->insert([
            'task_id' => $taskId,
            'from_state' => $entry['from'] ?? null,
            'to_state' => $entry['to'],
            'extra' => json_encode($entry['extra'] ?? []),
            'created_at' => $entry['timestamp'] ?? time(),
        ]);
    }

    public function getTaskHistory(string $taskId): array
    {
        $rows = Db::table('task_history')
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->get();

        return array_map(fn ($row) => [
            'from' => $row->from_state,
            'to' => $row->to_state,
            'timestamp' => $row->created_at,
            'extra' => json_decode($row->extra ?? '{}', true),
        ], $rows->all());
    }

    // --- Per-user task tracking ---

    public function addUserTask(string $userId, string $taskId): void
    {
        Db::table('tasks')->where('id', $taskId)->update(['user_id' => $userId]);
    }

    public function getUserTasks(string $userId, int $limit = 20): array
    {
        return $this->toArrayList(
            Db::table('tasks')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(),
        );
    }

    public function removeUserTask(string $userId, string $taskId): void
    {
        Db::table('tasks')
            ->where('id', $taskId)
            ->where('user_id', $userId)
            ->update(['user_id' => '']);
    }

    // =========================================================================
    // Session operations
    // =========================================================================

    public function createSession(string $sessionId, array $data): void
    {
        Db::table('sessions')->insert([
            'id' => $sessionId,
            'data' => json_encode($data),
            'created_at' => (int) ($data['created_at'] ?? time()),
            'updated_at' => time(),
        ]);
    }

    public function getSession(string $sessionId): ?array
    {
        $row = Db::table('sessions')->where('id', $sessionId)->first();
        if (!$row) {
            return null;
        }

        return json_decode($row->data, true) ?: [];
    }

    public function updateSession(string $sessionId, array $data): void
    {
        $existing = $this->getSession($sessionId);
        if (!$existing) {
            return;
        }
        $merged = array_merge($existing, $data);
        Db::table('sessions')->where('id', $sessionId)->update([
            'data' => json_encode($merged),
            'updated_at' => time(),
        ]);
    }

    public function deleteSession(string $sessionId): void
    {
        Db::table('sessions')->where('id', $sessionId)->delete();
    }

    public function listSessions(): array
    {
        $rows = Db::table('sessions')->get();

        return array_map(function ($row) {
            return json_decode($row->data, true) ?: [];
        }, $rows->all());
    }

    // =========================================================================
    // Memory operations
    // =========================================================================

    public function setMemoryFact(string $userId, string $key, string $value): void
    {
        Db::table('memory_facts')->upsert(
            ['user_id' => $userId, 'key' => $key, 'value' => $value],
            ['user_id', 'key'],
            ['value'],
        );
    }

    public function getMemoryFact(string $userId, string $key): ?string
    {
        $row = Db::table('memory_facts')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->first();

        return $row ? $row->value : null;
    }

    public function getAllMemory(string $userId): array
    {
        $rows = Db::table('memory_facts')->where('user_id', $userId)->get();
        $result = [];
        foreach ($rows as $row) {
            $result[$row->key] = $row->value;
        }

        return $result;
    }

    public function deleteMemoryFact(string $userId, string $key): void
    {
        Db::table('memory_facts')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->delete();
    }

    public function addMemoryLog(string $userId, string $summary): void
    {
        Db::table('memory_log')->insert([
            'user_id' => $userId,
            'summary' => $summary,
            'created_at' => time(),
        ]);

        // Cap at 100 entries per user
        $count = Db::table('memory_log')->where('user_id', $userId)->count();
        if ($count > 100) {
            $cutoff = Db::table('memory_log')
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->skip(100)
                ->first();
            if ($cutoff) {
                Db::table('memory_log')
                    ->where('user_id', $userId)
                    ->where('id', '<=', $cutoff->id)
                    ->delete();
            }
        }
    }

    public function getMemoryLog(string $userId, int $limit = 20): array
    {
        return Db::table('memory_log')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('summary')
            ->all();
    }

    // --- Structured memory entries ---

    /**
     * Get a single memory entry by ID (any scope).
     */
    public function getMemoryEntryById(string $userId, string $entryId): ?array
    {
        $row = Db::table('memories')
            ->where('user_id', $userId)
            ->where('id', $entryId)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->mapMemoryRow($row);
    }

    public function addMemoryEntry(string $userId, array $entry): void
    {
        Db::table('memories')->insert([
            'id' => $entry['id'],
            'user_id' => $userId,
            'category' => $entry['category'] ?? 'fact',
            'content' => $entry['content'] ?? '',
            'importance' => $entry['importance'] ?? 'normal',
            'source' => $entry['source'] ?? 'inline',
            'type' => $entry['type'] ?? 'project',
            'agent_scope' => $entry['agent_scope'] ?? '*',
            'project_id' => null,
            'created_at' => $entry['created_at'] ?? time(),
            'updated_at' => $entry['created_at'] ?? time(),
        ]);
    }

    public function getMemoryEntries(string $userId, int $limit = 100): array
    {
        $rows = Db::table('memories')
            ->where('user_id', $userId)
            ->whereNull('project_id')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return array_map(fn ($row) => $this->mapMemoryRow($row), $rows->all());
    }

    public function searchMemoryEntries(string $userId, string $keyword): array
    {
        $keyword = mb_strtolower($keyword);
        $rows = Db::table('memories')
            ->where('user_id', $userId)
            ->whereNull('project_id')
            ->whereRaw('LOWER(content) LIKE ?', ["%{$keyword}%"])
            ->orderByDesc('created_at')
            ->get();

        return array_map(fn ($row) => $this->mapMemoryRow($row), $rows->all());
    }

    public function deleteMemoryEntry(string $userId, string $entryId): void
    {
        Db::table('memories')
            ->where('user_id', $userId)
            ->where('id', $entryId)
            ->whereNull('project_id')
            ->delete();
    }

    /**
     * Delete any memory entry by ID regardless of project scope.
     */
    public function deleteAnyMemoryEntry(string $userId, string $entryId): void
    {
        Db::table('memories')
            ->where('user_id', $userId)
            ->where('id', $entryId)
            ->delete();
    }

    public function getMemoryEntryCount(string $userId): int
    {
        return (int) Db::table('memories')
            ->where('user_id', $userId)
            ->whereNull('project_id')
            ->count();
    }

    /**
     * Get ALL memory entries for a user (both general and project-scoped).
     */
    public function getAllMemoryEntries(string $userId, int $limit = 200): array
    {
        $rows = Db::table('memories')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return array_map(fn ($row) => $this->mapMemoryRow($row), $rows->all());
    }

    public function getAllMemoryEntryCount(string $userId): int
    {
        return (int) Db::table('memories')
            ->where('user_id', $userId)
            ->count();
    }

    public function updateMemoryEntry(string $userId, ?string $projectId, string $entryId, array $updates): void
    {
        $query = Db::table('memories')
            ->where('user_id', $userId)
            ->where('id', $entryId);

        // Only scope by project if not changing project_id (id is unique, so this is safe)
        if (!array_key_exists('project_id', $updates)) {
            if ($projectId !== null) {
                $query->where('project_id', $projectId);
            } else {
                $query->whereNull('project_id');
            }
        }

        $allowed = ['category', 'content', 'importance', 'source', 'type', 'agent_scope', 'project_id', 'updated_at'];
        $update = array_intersect_key($updates, array_flip($allowed));
        if (!empty($update)) {
            $update['updated_at'] = time();
            $query->update($update);
        }
    }

    // --- Project-scoped memory ---

    public function addProjectMemoryEntry(string $userId, string $projectId, array $entry): void
    {
        Db::table('memories')->insert([
            'id' => $entry['id'],
            'user_id' => $userId,
            'category' => $entry['category'] ?? 'fact',
            'content' => $entry['content'] ?? '',
            'importance' => $entry['importance'] ?? 'normal',
            'source' => $entry['source'] ?? 'inline',
            'type' => $entry['type'] ?? 'project',
            'agent_scope' => $entry['agent_scope'] ?? '*',
            'project_id' => $projectId,
            'created_at' => $entry['created_at'] ?? time(),
            'updated_at' => $entry['created_at'] ?? time(),
        ]);
    }

    public function getProjectMemoryEntries(string $userId, string $projectId, int $limit = 100): array
    {
        $rows = Db::table('memories')
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return array_map(fn ($row) => $this->mapMemoryRow($row), $rows->all());
    }

    public function deleteProjectMemoryEntry(string $userId, string $projectId, string $entryId): void
    {
        Db::table('memories')
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('id', $entryId)
            ->delete();
    }

    public function getProjectMemoryEntryCount(string $userId, string $projectId): int
    {
        return (int) Db::table('memories')
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->count();
    }

    // --- Agent-scoped and typed memory queries ---

    /**
     * Get memories filtered by type for a user.
     */
    public function getMemoriesByType(string $userId, string $type, ?string $projectId = null, int $limit = 200): array
    {
        $query = Db::table('memories')
            ->where('user_id', $userId)
            ->where('type', $type);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return array_map(
            fn ($row) => $this->mapMemoryRow($row),
            $query->orderByDesc('created_at')->limit($limit)->get()->all(),
        );
    }

    /**
     * Get memories visible to a specific agent (scope = '*', '', exact match, or contains agentId).
     */
    public function getMemoriesForAgent(string $userId, string $agentId, ?string $projectId = null, ?string $type = null, int $limit = 200): array
    {
        $query = Db::table('memories')
            ->where('user_id', $userId)
            ->where(function ($q) use ($agentId) {
                $q->where('agent_scope', '')
                  ->orWhere('agent_scope', '*')
                  ->orWhere('agent_scope', $agentId)
                  ->orWhereRaw('agent_scope LIKE ?', ["%{$agentId}%"]);
            });

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }
        if ($type !== null) {
            $query->where('type', $type);
        }

        return array_map(
            fn ($row) => $this->mapMemoryRow($row),
            $query->orderByDesc('created_at')->limit($limit)->get()->all(),
        );
    }

    /**
     * Get core memories visible to a specific agent.
     */
    public function getCoreMemoriesForAgent(string $userId, string $agentId, ?string $projectId = null): array
    {
        return $this->getMemoriesForAgent($userId, $agentId, $projectId, 'core');
    }

    /**
     * Get project memories not surfaced in $thresholdSeconds, excluding core memories.
     */
    public function getStaleProjectMemories(string $userId, int $thresholdSeconds, int $limit = 100): array
    {
        $cutoff = time() - $thresholdSeconds;

        $rows = Db::table('memories')
            ->where('user_id', $userId)
            ->where('type', 'project')
            ->where('last_surfaced_at', '>', 0)
            ->where('last_surfaced_at', '<', $cutoff)
            ->orderBy('last_surfaced_at')
            ->limit($limit)
            ->get();

        return array_map(fn ($row) => $this->mapMemoryRow($row), $rows->all());
    }

    /**
     * Bulk update last_surfaced_at for given memory IDs.
     */
    public function touchMemoriesSurfaced(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        Db::table('memories')
            ->whereIn('id', $ids)
            ->update(['last_surfaced_at' => time()]);
    }

    // =========================================================================
    // Skill operations
    // =========================================================================

    public function setSkill(string $scope, string $name, array $config): void
    {
        Db::table('skills')->upsert(
            ['scope' => $scope, 'name' => $name, 'config' => json_encode($config)],
            ['scope', 'name'],
            ['config'],
        );
    }

    public function getSkill(string $scope, string $name): ?array
    {
        $row = Db::table('skills')
            ->where('scope', $scope)
            ->where('name', $name)
            ->first();
        if (!$row) {
            return null;
        }

        return json_decode($row->config, true);
    }

    public function getAllSkills(string $scope): array
    {
        $rows = Db::table('skills')->where('scope', $scope)->get();
        $skills = [];
        foreach ($rows as $row) {
            $skills[$row->name] = json_decode($row->config, true);
        }

        return $skills;
    }

    public function deleteSkill(string $scope, string $name): void
    {
        Db::table('skills')
            ->where('scope', $scope)
            ->where('name', $name)
            ->delete();
    }

    // =========================================================================
    // Project operations
    // =========================================================================

    public function createProject(string $projectId, array $data): void
    {
        Db::table('projects')->insert($data);
    }

    public function getProject(string $projectId): ?array
    {
        // 'general' is a virtual project ID, not a real UUID
        if ($projectId === '' || $projectId === 'general') {
            return null;
        }
        $row = Db::table('projects')->where('id', $projectId)->first();

        return $this->toArray($row);
    }

    public function updateProject(string $projectId, array $data): void
    {
        Db::table('projects')->where('id', $projectId)->update($data);
    }

    public function deleteProject(string $projectId): void
    {
        // FK cascades handle steps, history, epics, items, etc.
        Db::table('project_names')->where('project_id', $projectId)->delete();
        Db::table('projects')->where('id', $projectId)->delete();
    }

    public function addProjectStep(string $projectId, array $stepResult): void
    {
        Db::table('project_steps')->insert([
            'project_id' => $projectId,
            'step_data' => json_encode($stepResult),
            'created_at' => time(),
        ]);
    }

    public function getProjectSteps(string $projectId): array
    {
        $rows = Db::table('project_steps')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->get();

        return array_map(
            fn ($row) => json_decode($row->step_data, true) ?: [],
            $rows->all(),
        );
    }

    public function addProjectHistory(string $projectId, array $entry): void
    {
        Db::table('project_history')->insert([
            'project_id' => $projectId,
            'from_state' => $entry['from'] ?? null,
            'to_state' => $entry['to'],
            'reason' => $entry['reason'] ?? null,
            'created_at' => $entry['timestamp'] ?? time(),
        ]);
    }

    public function getProjectHistory(string $projectId): array
    {
        $rows = Db::table('project_history')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->get();

        return array_map(fn ($row) => array_filter([
            'from' => $row->from_state,
            'to' => $row->to_state,
            'timestamp' => $row->created_at,
            'reason' => $row->reason,
        ], fn ($v) => $v !== null), $rows->all());
    }

    public function listProjects(?string $state = null, int $limit = 20): array
    {
        $query = Db::table('projects')->orderByDesc('created_at')->limit($limit);
        if ($state !== null) {
            $query->where('state', $state);
        }

        return $this->toArrayList($query->get());
    }

    public function setProjectName(string $name, string $projectId): void
    {
        Db::table('project_names')->upsert(
            ['name_lower' => mb_strtolower($name), 'project_id' => $projectId],
            ['name_lower'],
            ['project_id'],
        );
    }

    public function getProjectByName(string $name): ?string
    {
        $row = Db::table('project_names')
            ->where('name_lower', mb_strtolower($name))
            ->first();

        return $row ? (string) $row->project_id : null;
    }

    public function removeProjectName(string $name): void
    {
        Db::table('project_names')->where('name_lower', mb_strtolower($name))->delete();
    }

    public function listWorkspaceProjects(): array
    {
        return $this->toArrayList(
            Db::table('projects')
                ->where('state', 'workspace')
                ->orderByDesc('created_at')
                ->get(),
        );
    }

    // =========================================================================
    // Conversation operations
    // =========================================================================

    public function createConversation(string $id, array $data): void
    {
        if (isset($data['agent_id'])) {
            $data['agent_id'] = $this->nullableUuid($data['agent_id'] ?? '');
        }
        Db::table('conversations')->insert($data);
    }

    public function getConversation(string $id): ?array
    {
        $row = Db::table('conversations')
            ->leftJoin('agents', 'conversations.agent_id', '=', 'agents.id')
            ->where('conversations.id', $id)
            ->select([
                'conversations.*',
                'agents.name as agent_name',
                'agents.slug as agent_slug',
                'agents.color as agent_color',
                'agents.icon as agent_icon',
            ])
            ->first();

        return $this->toArray($row);
    }

    public function updateConversation(string $id, array $data): void
    {
        Db::table('conversations')->where('id', $id)->update($data);
    }

    public function addConversationTurn(string $id, array $turn): void
    {
        Db::table('conversation_turns')->insert([
            'conversation_id' => $id,
            'role' => $turn['role'],
            'content' => $turn['content'] ?? '',
            'task_id' => $turn['task_id'] ?? '',
            'cost_usd' => $turn['cost_usd'] ?? 0,
            'created_at' => $turn['timestamp'] ?? time(),
        ]);
    }

    public function getConversationTurns(string $id): array
    {
        $rows = Db::table('conversation_turns')
            ->where('conversation_id', $id)
            ->orderBy('id')
            ->get();

        return array_map(fn ($row) => [
            'role' => $row->role,
            'content' => $row->content,
            'task_id' => $row->task_id,
            'cost_usd' => $row->cost_usd,
            'timestamp' => $row->created_at,
        ], $rows->all());
    }

    public function listConversations(?string $projectId = null, int $limit = 30): array
    {
        $query = Db::table('conversations')
            ->leftJoin('agents', 'conversations.agent_id', '=', 'agents.id')
            ->select([
                'conversations.*',
                'agents.name as agent_name',
                'agents.slug as agent_slug',
                'agents.color as agent_color',
                'agents.icon as agent_icon',
            ])
            ->orderByDesc('conversations.updated_at')
            ->limit($limit);
        if ($projectId !== null && $projectId !== '') {
            $query->where('conversations.project_id', $projectId);
        }
        $conversations = $this->toArrayList($query->get());

        // Attach first user message as preview for each conversation
        foreach ($conversations as &$conv) {
            $firstTurn = Db::table('conversation_turns')
                ->where('conversation_id', $conv['id'])
                ->where('role', 'user')
                ->orderBy('id')
                ->first();
            $conv['first_message'] = $firstTurn
                ? mb_substr($firstTurn->content, 0, 120)
                : '';
        }

        return $conversations;
    }

    public function getOldConversationIds(int $olderThan, int $limit = 200): array
    {
        return Db::table('conversations')
            ->where('created_at', '<=', $olderThan)
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function deleteConversation(string $id): void
    {
        // FK cascades handle turns
        Db::table('conversations')->where('id', $id)->delete();
    }

    public function addConversationToProject(string $projectId, string $conversationId): void
    {
        // No-op: conversation.project_id is set at creation time.
        // Listing by project queries the project_id column directly.
    }

    // =========================================================================
    // Epic operations
    // =========================================================================

    public function createEpic(string $epicId, array $data): void
    {
        // Convert is_backlog from string '0'/'1' to boolean for Postgres
        if (isset($data['is_backlog'])) {
            $data['is_backlog'] = $data['is_backlog'] === '1' || $data['is_backlog'] === true;
        }
        Db::table('epics')->insert($data);
    }

    public function getEpic(string $epicId): ?array
    {
        $row = Db::table('epics')->where('id', $epicId)->first();

        return $this->toArray($row);
    }

    public function updateEpic(string $epicId, array $data): void
    {
        if (isset($data['is_backlog'])) {
            $data['is_backlog'] = $data['is_backlog'] === '1' || $data['is_backlog'] === true;
        }
        Db::table('epics')->where('id', $epicId)->update($data);
    }

    public function deleteEpic(string $epicId): void
    {
        // FK cascades handle items (ON DELETE SET NULL)
        Db::table('epics')->where('id', $epicId)->delete();
    }

    public function addEpicToProject(string $projectId, string $epicId, float $sortOrder): void
    {
        // No-op: epic.project_id and sort_order are set at creation time.
        // Re-ordering calls updateEpic directly.
    }

    public function listProjectEpics(string $projectId): array
    {
        return $this->toArrayList(
            Db::table('epics')
                ->where('project_id', $projectId)
                ->orderBy('sort_order')
                ->get(),
        );
    }

    public function setProjectBacklogEpic(string $projectId, string $epicId): bool
    {
        // The unique partial index idx_epics_backlog_unique prevents duplicates.
        // If the epic was already created with is_backlog=true, this is a no-op.
        // Return false if a backlog epic already exists for this project.
        $existing = Db::table('epics')
            ->where('project_id', $projectId)
            ->where('is_backlog', true)
            ->first();

        if ($existing && (string) $existing->id !== $epicId) {
            return false;
        }

        return true;
    }

    public function getProjectBacklogEpic(string $projectId): ?string
    {
        $row = Db::table('epics')
            ->where('project_id', $projectId)
            ->where('is_backlog', true)
            ->first();

        return $row ? (string) $row->id : null;
    }

    // =========================================================================
    // Item operations
    // =========================================================================

    public function createItem(string $itemId, array $data): void
    {
        Db::table('items')->insert($data);
    }

    public function getItem(string $itemId): ?array
    {
        $row = Db::table('items')->where('id', $itemId)->first();
        $data = $this->toArray($row);
        if ($data !== null) {
            $data['epic_id'] ??= '';
        }

        return $data;
    }

    public function updateItem(string $itemId, array $data): void
    {
        Db::table('items')->where('id', $itemId)->update($data);
    }

    public function deleteItem(string $itemId): void
    {
        // FK cascades handle notes, conversation links
        Db::table('items')->where('id', $itemId)->delete();
    }

    public function addItemToEpic(string $epicId, string $itemId, float $sortOrder): void
    {
        Db::table('items')->where('id', $itemId)->update([
            'epic_id' => $epicId,
            'sort_order' => (int) $sortOrder,
        ]);
    }

    public function removeItemFromEpic(string $epicId, string $itemId): void
    {
        // In practice this is always followed by addItemToEpic.
        // Clear the association for safety.
        Db::table('items')
            ->where('id', $itemId)
            ->where('epic_id', $epicId)
            ->update(['epic_id' => null]);
    }

    public function listEpicItems(string $epicId): array
    {
        return $this->toArrayList(
            Db::table('items')
                ->where('epic_id', $epicId)
                ->orderBy('sort_order')
                ->get(),
        );
    }

    public function addItemToProject(string $projectId, string $itemId): void
    {
        // No-op: item.project_id is set at creation time.
    }

    public function listProjectItems(string $projectId, ?string $state = null): array
    {
        $query = Db::table('items')
            ->where('project_id', $projectId)
            ->orderByDesc('created_at');
        if ($state !== null) {
            $query->where('state', $state);
        }

        return $this->toArrayList($query->get());
    }

    public function getEpicItemCount(string $epicId): int
    {
        return (int) Db::table('items')->where('epic_id', $epicId)->count();
    }

    public function getAllItemIds(int $limit = 1000): array
    {
        return Db::table('items')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    // --- Item <-> Conversation links ---

    public function linkItemToConversation(string $itemId, string $conversationId): void
    {
        Db::table('item_conversations')->insertOrIgnore([
            'item_id' => $itemId,
            'conversation_id' => $conversationId,
            'linked_at' => time(),
        ]);
    }

    public function getItemConversations(string $itemId): array
    {
        return Db::table('item_conversations')
            ->where('item_id', $itemId)
            ->pluck('conversation_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function getConversationItems(string $conversationId): array
    {
        return Db::table('item_conversations')
            ->where('conversation_id', $conversationId)
            ->pluck('item_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    // --- Item notes ---

    public function addItemNote(string $itemId, string $content, string $author): void
    {
        Db::table('item_notes')->insert([
            'item_id' => $itemId,
            'content' => $content,
            'author' => $author,
            'created_at' => time(),
        ]);
    }

    public function getItemNotes(string $itemId, int $limit = 20): array
    {
        $rows = Db::table('item_notes')
            ->where('item_id', $itemId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return array_map(fn ($row) => [
            'content' => $row->content,
            'author' => $row->author,
            'timestamp' => $row->created_at,
        ], $rows->all());
    }

    public function deleteItemNotes(string $itemId): void
    {
        Db::table('item_notes')->where('item_id', $itemId)->delete();
    }

    // =========================================================================
    // Nightly run history
    // =========================================================================

    public function addNightlyRunResult(array $stats): void
    {
        $stats['timestamp'] = time();
        Db::table('nightly_run_history')->insert([
            'stats' => json_encode($stats),
            'created_at' => time(),
        ]);

        // Retain only last 30 runs
        $count = Db::table('nightly_run_history')->count();
        if ($count > 30) {
            $cutoff = Db::table('nightly_run_history')
                ->orderByDesc('id')
                ->skip(30)
                ->first();
            if ($cutoff) {
                Db::table('nightly_run_history')->where('id', '<=', $cutoff->id)->delete();
            }
        }
    }

    public function getNightlyRunHistory(int $limit = 30): array
    {
        $rows = Db::table('nightly_run_history')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return array_map(
            fn ($row) => json_decode($row->stats, true) ?: [],
            $rows->all(),
        );
    }

    // =========================================================================
    // Scheduled job operations
    // =========================================================================

    public function saveScheduledJob(array $job): void
    {
        $id = $job['id'];

        // Convert enabled to boolean for Postgres
        if (isset($job['enabled'])) {
            $job['enabled'] = filter_var($job['enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        $existing = Db::table('scheduled_jobs')->where('id', $id)->first();
        if ($existing) {
            Db::table('scheduled_jobs')->where('id', $id)->update($job);
        } else {
            Db::table('scheduled_jobs')->insert($job);
        }
    }

    public function getScheduledJob(string $id): ?array
    {
        $row = Db::table('scheduled_jobs')->where('id', $id)->first();

        return $this->toArray($row);
    }

    public function getScheduledJobs(): array
    {
        $rows = Db::table('scheduled_jobs')->orderBy('name')->get();

        return $this->toArrayList($rows);
    }

    public function deleteScheduledJob(string $id): void
    {
        Db::table('scheduled_jobs')->where('id', $id)->delete();
    }

    // =========================================================================
    // Channel operations
    // =========================================================================

    public function saveChannel(array $channel): void
    {
        $id = $channel['id'];
        $existing = Db::table('channels')->where('id', $id)->first();
        if ($existing) {
            Db::table('channels')->where('id', $id)->update($channel);
        } else {
            Db::table('channels')->insert($channel);
        }
    }

    public function getChannel(string $channelId): ?array
    {
        $row = Db::table('channels')->where('id', $channelId)->first();
        if (!$row) {
            return null;
        }
        $data = (array) $row;
        $data['member_count'] = (int) ($data['member_count'] ?? 0);
        $data['created_at'] = (int) ($data['created_at'] ?? 0);

        return $data;
    }

    public function getChannels(): array
    {
        $rows = Db::table('channels')->orderByDesc('created_at')->limit(100)->get();

        return array_map(function ($row) {
            $data = (array) $row;
            $data['member_count'] = (int) ($data['member_count'] ?? 0);
            $data['created_at'] = (int) ($data['created_at'] ?? 0);

            return $data;
        }, $rows->all());
    }

    public function deleteChannel(string $channelId): void
    {
        // FK cascades handle messages
        Db::table('channels')->where('id', $channelId)->delete();
    }

    public function saveChannelMessage(string $channelId, array $message): void
    {
        $data = [
            'id' => $message['id'],
            'channel_id' => $channelId,
            'author' => $message['author'] ?? '',
            'content' => $message['content'] ?? '',
            'created_at' => $message['created_at'] ?? time(),
        ];
        if (!empty($message['agent_id'])) {
            $data['agent_id'] = $message['agent_id'];
        }
        Db::table('channel_messages')->insert($data);

        // Keep last 500 messages per channel
        $count = Db::table('channel_messages')->where('channel_id', $channelId)->count();
        if ($count > 500) {
            $cutoff = Db::table('channel_messages')
                ->where('channel_id', $channelId)
                ->orderByDesc('created_at')
                ->skip(500)
                ->first();
            if ($cutoff) {
                Db::table('channel_messages')
                    ->where('channel_id', $channelId)
                    ->where('created_at', '<=', $cutoff->created_at)
                    ->where('id', '!=', $cutoff->id)
                    ->delete();
            }
        }
    }

    public function getChannelMessages(string $channelId, int $limit = 100): array
    {
        $rows = Db::table('channel_messages')
            ->where('channel_id', $channelId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return array_map(function ($row) {
            $msg = [
                'id' => $row->id,
                'channel_id' => $row->channel_id,
                'author' => $row->author,
                'content' => $row->content,
                'created_at' => (int) $row->created_at,
            ];
            if (!empty($row->agent_id)) {
                $msg['agent_id'] = $row->agent_id;
            }

            return $msg;
        }, $rows->all());
    }

    // =========================================================================
    // Atomic notification flag
    // =========================================================================

    /**
     * Atomically mark a task as notified. Returns true if this call was the
     * one that set the flag (i.e. first caller wins). Returns false if
     * already notified.
     */
    public function markNotified(string $taskId): bool
    {
        $affected = Db::update(
            'UPDATE tasks SET notified_at = ? WHERE id = ? AND notified_at = 0',
            [time(), $taskId],
        );

        return $affected > 0;
    }

    // =========================================================================
    // Agent operations
    // =========================================================================

    public function createAgent(string $id, array $data): void
    {
        Db::table('agents')->insert($data);
    }

    public function getAgent(string $id): ?array
    {
        $row = Db::table('agents')->where('id', $id)->first();

        return $this->toArray($row);
    }

    public function getAgentBySlug(string $slug): ?array
    {
        $row = Db::table('agents')->where('slug', $slug)->first();

        return $this->toArray($row);
    }

    public function getDefaultAgent(): ?array
    {
        $row = Db::table('agents')->where('is_default', true)->first();

        return $this->toArray($row);
    }

    public function listAgents(?string $projectId = null): array
    {
        $query = Db::table('agents')->orderBy('is_default', 'desc')->orderBy('name');
        if ($projectId !== null && $projectId !== '') {
            $query->where(function ($q) use ($projectId) {
                $q->whereNull('project_id')
                  ->orWhere('project_id', $projectId);
            });
        }

        return $this->toArrayList($query->get());
    }

    public function updateAgent(string $id, array $data): void
    {
        if (isset($data['project_id']) && $data['project_id'] === '') {
            $data['project_id'] = null;
        }
        Db::table('agents')->where('id', $id)->update($data);
    }

    public function deleteAgent(string $id): void
    {
        Db::table('agents')->where('id', $id)->delete();
    }

    public function clearDefaultAgents(): void
    {
        Db::table('agents')->where('is_default', true)->update(['is_default' => false]);
    }

    public function backfillConversationAgentId(string $agentId): void
    {
        Db::table('conversations')
            ->whereNull('agent_id')
            ->update(['agent_id' => $agentId]);
    }

    // --- Room agents ---

    public function addRoomAgent(string $roomId, string $agentId, bool $isDefault = false): void
    {
        Db::table('room_agents')->insertOrIgnore([
            'room_id' => $roomId,
            'agent_id' => $agentId,
            'is_active_default' => $isDefault,
            'added_at' => time(),
        ]);
    }

    public function removeRoomAgent(string $roomId, string $agentId): void
    {
        Db::table('room_agents')
            ->where('room_id', $roomId)
            ->where('agent_id', $agentId)
            ->delete();
    }

    public function getRoomAgents(string $roomId): array
    {
        $rows = Db::table('room_agents')
            ->join('agents', 'room_agents.agent_id', '=', 'agents.id')
            ->where('room_agents.room_id', $roomId)
            ->select('agents.*', 'room_agents.is_active_default', 'room_agents.added_at')
            ->orderBy('room_agents.added_at')
            ->get();

        return $this->toArrayList($rows);
    }

    public function setRoomDefaultAgent(string $roomId, string $agentId): void
    {
        // Clear existing defaults for this room
        Db::table('room_agents')
            ->where('room_id', $roomId)
            ->update(['is_active_default' => false]);

        // Set the new default
        Db::table('room_agents')
            ->where('room_id', $roomId)
            ->where('agent_id', $agentId)
            ->update(['is_active_default' => true]);
    }

    public function getRoomDefaultAgent(string $roomId): ?array
    {
        $row = Db::table('room_agents')
            ->join('agents', 'room_agents.agent_id', '=', 'agents.id')
            ->where('room_agents.room_id', $roomId)
            ->where('room_agents.is_active_default', true)
            ->select('agents.*')
            ->first();

        return $this->toArray($row);
    }

    // =========================================================================
    // Health check
    // =========================================================================

    public function ping(): bool
    {
        try {
            Db::select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    // =========================================================================
    // Notebook operations
    // =========================================================================

    public function createNotebook(string $notebookId, array $data): void
    {
        Db::table('notebooks')->insert($data);
    }

    public function getNotebook(string $notebookId): ?array
    {
        $row = Db::table('notebooks')->where('id', $notebookId)->first();

        return $this->toArray($row);
    }

    public function updateNotebook(string $notebookId, array $data): void
    {
        Db::table('notebooks')->where('id', $notebookId)->update($data);
    }

    public function deleteNotebook(string $notebookId): void
    {
        // FK cascades handle pages (ON DELETE CASCADE)
        Db::table('notebooks')->where('id', $notebookId)->delete();
    }

    public function listNotebooks(): array
    {
        return $this->toArrayList(
            Db::table('notebooks')
                ->orderBy('sort_order')
                ->get(),
        );
    }

    // =========================================================================
    // Page operations
    // =========================================================================

    public function createPage(string $pageId, array $data): void
    {
        Db::table('pages')->insert($data);
    }

    public function getPage(string $pageId): ?array
    {
        $row = Db::table('pages')->where('id', $pageId)->first();
        $data = $this->toArray($row);
        if ($data !== null) {
            $data['notebook_id'] ??= '';
        }

        return $data;
    }

    public function updatePage(string $pageId, array $data): void
    {
        Db::table('pages')->where('id', $pageId)->update($data);
    }

    public function deletePage(string $pageId): void
    {
        Db::table('pages')->where('id', $pageId)->delete();
    }

    public function listNotebookPages(string $notebookId): array
    {
        return $this->toArrayList(
            Db::table('pages')
                ->where('notebook_id', $notebookId)
                ->orderByRaw('pinned DESC, sort_order ASC')
                ->get(),
        );
    }

    // =========================================================================
    // Todo section operations
    // =========================================================================

    public function createTodoSection(string $sectionId, array $data): void
    {
        Db::table('todo_sections')->insert($data);
    }

    public function getTodoSection(string $sectionId): ?array
    {
        $row = Db::table('todo_sections')->where('id', $sectionId)->first();

        return $this->toArray($row);
    }

    public function updateTodoSection(string $sectionId, array $data): void
    {
        if (isset($data['collapsed'])) {
            $data['collapsed'] = filter_var($data['collapsed'], FILTER_VALIDATE_BOOLEAN);
        }
        Db::table('todo_sections')->where('id', $sectionId)->update($data);
    }

    public function deleteTodoSection(string $sectionId): void
    {
        Db::table('todo_sections')->where('id', $sectionId)->delete();
    }

    public function listTodoSections(): array
    {
        return $this->toArrayList(
            Db::table('todo_sections')
                ->orderBy('sort_order')
                ->get(),
        );
    }

    // =========================================================================
    // Todo item operations
    // =========================================================================

    public function createTodoItem(string $itemId, array $data): void
    {
        Db::table('todo_items')->insert($data);
    }

    public function getTodoItem(string $itemId): ?array
    {
        $row = Db::table('todo_items')->where('id', $itemId)->first();

        return $this->toArray($row);
    }

    public function updateTodoItem(string $itemId, array $data): void
    {
        if (isset($data['done'])) {
            $data['done'] = filter_var($data['done'], FILTER_VALIDATE_BOOLEAN);
        }
        Db::table('todo_items')->where('id', $itemId)->update($data);
    }

    public function deleteTodoItem(string $itemId): void
    {
        Db::table('todo_items')->where('id', $itemId)->delete();
    }

    public function listTodoItems(string $sectionId): array
    {
        return $this->toArrayList(
            Db::table('todo_items')
                ->where('section_id', $sectionId)
                ->orderBy('sort_order')
                ->get(),
        );
    }
    // --- Helpers ---

    /**
     * Convert stdClass row to array, normalizing booleans to '0'/'1' strings
     * and nulls to empty strings for compatibility with existing managers.
     */
    private function toArray(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $data = (array) $row;
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = $value ? '1' : '0';
            } elseif ($value === null) {
                $data[$key] = '';
            }
        }

        return $data;
    }

    private function toArrayList(iterable $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->toArray($row);
        }

        return $result;
    }

    /**
     * Convert '' to null for nullable UUID FK columns on write.
     */
    private function nullableUuid(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }

    /**
     * Map a database row to a memory array with all fields.
     */
    private function mapMemoryRow(object $row): array
    {
        return [
            'id' => $row->id,
            'category' => $row->category,
            'content' => $row->content,
            'importance' => $row->importance,
            'source' => $row->source ?? '',
            'type' => $row->type ?? 'project',
            'agent_scope' => $row->agent_scope ?? '*',
            'project_id' => $row->project_id,
            'last_surfaced_at' => (int) ($row->last_surfaced_at ?? 0),
            'updated_at' => (int) ($row->updated_at ?? 0),
            'created_at' => (int) ($row->created_at ?? 0),
        ];
    }
}
