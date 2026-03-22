# Storage, Infrastructure, and Configuration

This document covers the data storage layer, server infrastructure, Docker services, dependency injection, startup sequence, scheduled tasks, environment variables, and CLI commands for Claude Connect.

---

## Table of Contents

1. [PostgreSQL Schema](#postgresql-schema)
2. [Redis Usage](#redis-usage)
3. [Swoole Table In-Memory Cache](#swoole-table-in-memory-cache)
4. [Storage Layer Architecture](#storage-layer-architecture)
5. [Docker Services](#docker-services)
6. [Database Migration System](#database-migration-system)
7. [Swoole Server Configuration](#swoole-server-configuration)
8. [Database Connection Layer](#database-connection-layer)
9. [DI Container and Dependency Injection](#di-container-and-dependency-injection)
10. [Startup Sequence](#startup-sequence)
11. [Listeners and Background Services](#listeners-and-background-services)
12. [Scheduled Tasks](#scheduled-tasks)
13. [HTTP Routes](#http-routes)
14. [Environment Variables](#environment-variables)
15. [CLI Commands](#cli-commands)

---

## PostgreSQL Schema

PostgreSQL is the primary persistent store. All durable data lives here. The schema is defined across three migration files executed in order.

### Migration 001: Initial Schema

#### `conversations`
Multi-turn conversation containers.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | UUID | PK | |
| user_id | VARCHAR(255) | `''` | |
| type | VARCHAR(32) | `'task'` | |
| state | VARCHAR(32) | `'active'` | |
| project_id | VARCHAR(255) | `'general'` | Not a FK -- `'general'` is a virtual project |
| source | VARCHAR(32) | `'web'` | |
| summary | TEXT | `''` | |
| key_takeaways | TEXT | `'[]'` | JSON array stored as text |
| total_cost_usd | NUMERIC(12,6) | 0 | |
| turn_count | INTEGER | 0 | |
| created_at | INTEGER | 0 | Unix timestamp |
| updated_at | INTEGER | 0 | Unix timestamp |

Indexes: `project_id`, `created_at`, `state`

Added by migration 002: `agent_id UUID REFERENCES agents(id) ON DELETE SET NULL`, `title VARCHAR(500)`

#### `conversation_turns`
Individual messages within a conversation.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | BIGSERIAL | PK | Auto-increment |
| conversation_id | UUID | NOT NULL | FK to `conversations(id)` CASCADE |
| role | VARCHAR(16) | NOT NULL | `'user'` or `'assistant'` |
| content | TEXT | `''` | |
| task_id | VARCHAR(255) | `''` | |
| cost_usd | NUMERIC(12,6) | 0 | |
| created_at | INTEGER | 0 | Unix timestamp |

Indexes: `conversation_id`

#### `projects`
Project workspaces for organizing work.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | UUID | PK | |
| name | VARCHAR(255) | `''` | |
| description | TEXT | `''` | |
| goal | TEXT | `''` | |
| plan | TEXT | `'[]'` | JSON array stored as text |
| state | VARCHAR(32) | `'planning'` | Also used as `'workspace'` for persistent workspaces |
| current_step | INTEGER | 0 | |
| total_steps | INTEGER | 0 | |
| completed_steps | INTEGER | 0 | |
| total_cost_usd | NUMERIC(12,6) | 0 | |
| max_iterations | INTEGER | 20 | |
| max_budget_usd | NUMERIC(12,6) | 10.00 | |
| checkpoint_interval | INTEGER | 5 | |
| current_task_id | VARCHAR(255) | `''` | |
| retry_count | INTEGER | 0 | |
| paused_reason | TEXT | `''` | |
| error | TEXT | `''` | |
| waiting_for_reply | VARCHAR(8) | `'0'` | |
| cwd | VARCHAR(1024) | `''` | Working directory path |
| user_id | VARCHAR(255) | `''` | |
| created_at | INTEGER | 0 | |
| updated_at | INTEGER | 0 | |
| completed_at | INTEGER | 0 | |

Indexes: `state`, `created_at`

Added by migration 002: `default_agent_id UUID REFERENCES agents(id) ON DELETE SET NULL`

#### `project_names`
Case-insensitive name lookup for projects.

| Column | Type | Notes |
|--------|------|-------|
| name_lower | VARCHAR(255) | PK, lowercase |
| project_id | UUID | FK to `projects(id)` CASCADE |

#### `project_history`
State transition log for projects.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL | PK |
| project_id | UUID | FK to `projects(id)` CASCADE |
| from_state | VARCHAR(32) | Nullable |
| to_state | VARCHAR(32) | NOT NULL |
| reason | TEXT | Nullable |
| created_at | INTEGER | |

Indexes: `project_id`

#### `project_steps`
Stores step execution data for projects (JSONB).

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL | PK |
| project_id | UUID | FK to `projects(id)` CASCADE |
| step_data | JSONB | `'{}'` |
| created_at | INTEGER | |

Indexes: `project_id`

#### `tasks`
Individual Claude CLI task executions.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | UUID | PK | |
| prompt | TEXT | `''` | |
| session_id | VARCHAR(255) | `''` | |
| claude_session_id | VARCHAR(255) | `''` | |
| parent_task_id | VARCHAR(255) | `''` | |
| conversation_id | UUID | NULL | FK to `conversations(id)` SET NULL |
| project_id | VARCHAR(255) | `'general'` | VARCHAR, not FK (virtual project) |
| source | VARCHAR(32) | `'web'` | |
| state | VARCHAR(32) | `'pending'` | `pending`, `running`, `completed`, `failed` |
| result | TEXT | `''` | |
| error | TEXT | `''` | |
| pid | INTEGER | 0 | OS process ID |
| cost_usd | NUMERIC(12,6) | 0 | |
| images | TEXT | `''` | |
| progress | TEXT | `''` | |
| options | TEXT | `'{}'` | JSON options blob |
| user_id | VARCHAR(255) | `''` | |
| created_at | INTEGER | 0 | |
| updated_at | INTEGER | 0 | |
| started_at | INTEGER | 0 | |
| completed_at | INTEGER | 0 | |

Indexes: `state`, `created_at`, `conversation_id`, `user_id`, `project_id`

Added by migration 002: `notified_at INTEGER NOT NULL DEFAULT 0` (used for atomic notification dedup via `markNotified`)

#### `task_history`
State transition log for tasks.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL | PK |
| task_id | UUID | FK to `tasks(id)` CASCADE |
| from_state | VARCHAR(32) | Nullable |
| to_state | VARCHAR(32) | NOT NULL |
| extra | JSONB | `'{}'` |
| created_at | INTEGER | |

Indexes: `task_id`

#### `epics`
Grouping containers for work items within a project.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | UUID | PK | |
| project_id | UUID | NOT NULL | FK to `projects(id)` CASCADE |
| title | VARCHAR(500) | `''` | |
| description | TEXT | `''` | |
| state | VARCHAR(32) | `'open'` | |
| is_backlog | BOOLEAN | false | Partial unique index ensures one per project |
| sort_order | INTEGER | 0 | |
| created_at | INTEGER | 0 | |
| updated_at | INTEGER | 0 | |

Indexes: `project_id`, unique partial index on `(project_id) WHERE is_backlog = true`

#### `items`
Individual work items (tasks/stories/bugs).

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | UUID | PK | |
| epic_id | UUID | NULL | FK to `epics(id)` SET NULL |
| project_id | UUID | NOT NULL | FK to `projects(id)` CASCADE |
| title | VARCHAR(500) | `''` | |
| description | TEXT | `''` | |
| state | VARCHAR(32) | `'open'` | |
| priority | VARCHAR(16) | `'normal'` | |
| sort_order | INTEGER | 0 | |
| conversation_id | VARCHAR(255) | `''` | |
| assigned_to | VARCHAR(255) | `''` | |
| created_at | INTEGER | 0 | |
| updated_at | INTEGER | 0 | |
| completed_at | INTEGER | 0 | |

Indexes: `epic_id`, `project_id`, `state`

#### `item_notes`
Activity log / notes on items.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL | PK |
| item_id | UUID | FK to `items(id)` CASCADE |
| content | TEXT | |
| author | VARCHAR(255) | |
| created_at | INTEGER | |

Indexes: `item_id`

#### `item_conversations`
Many-to-many link between items and conversations.

| Column | Type | Notes |
|--------|------|-------|
| item_id | UUID | FK to `items(id)` CASCADE |
| conversation_id | UUID | FK to `conversations(id)` CASCADE |
| linked_at | INTEGER | |

PK: `(item_id, conversation_id)`

#### `memories`
Structured memory entries (the main memory system).

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | VARCHAR(255) | PK | |
| user_id | VARCHAR(255) | NOT NULL | |
| category | VARCHAR(64) | `'fact'` | |
| content | TEXT | `''` | |
| importance | VARCHAR(16) | `'normal'` | |
| source | VARCHAR(64) | `'inline'` | |
| project_id | VARCHAR(255) | NULL | NULL = general memory |
| created_at | INTEGER | 0 | |

Indexes: `user_id`, `(user_id, project_id)`, `created_at`

Added by migration 002: `agent_scope VARCHAR(255) NOT NULL DEFAULT ''`

Added by migration 003:
- `type VARCHAR(16) NOT NULL DEFAULT 'project'` -- `'core'` (always injected) or `'project'` (ranked, subject to staleness)
- `last_surfaced_at INTEGER NOT NULL DEFAULT 0` -- tracks when last included in a prompt
- `updated_at INTEGER NOT NULL DEFAULT 0`
- Indexes: `type`, `agent_scope`, `last_surfaced_at`, `(type, project_id)`

Migration 003 also backfills: high-importance general memories become `'core'` type, and `updated_at` is set from `created_at`.

#### `memory_facts`
Legacy key-value memory store.

| Column | Type | Notes |
|--------|------|-------|
| user_id | VARCHAR(255) | Part of PK |
| key | VARCHAR(255) | Part of PK |
| value | TEXT | |

PK: `(user_id, key)`

#### `memory_log`
Conversation summary log per user. Capped at 100 entries per user by `PostgresStore`.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL | PK |
| user_id | VARCHAR(255) | |
| summary | TEXT | |
| created_at | INTEGER | |

Indexes: `user_id`

#### `sessions`
Generic session storage (JSONB data blob).

| Column | Type | Notes |
|--------|------|-------|
| id | VARCHAR(255) | PK |
| data | JSONB | `'{}'` |
| created_at | INTEGER | |
| updated_at | INTEGER | |

#### `skills`
Registered MCP server skills.

| Column | Type | Notes |
|--------|------|-------|
| scope | VARCHAR(255) | Part of PK (`'global'`, `'builtin'`, or user ID) |
| name | VARCHAR(255) | Part of PK |
| config | JSONB | `'{}'` |

PK: `(scope, name)`

#### `channels`
Chat channels/rooms.

| Column | Type | Notes |
|--------|------|-------|
| id | VARCHAR(255) | PK |
| name | VARCHAR(255) | |
| description | TEXT | |
| member_count | INTEGER | |
| created_at | INTEGER | |

#### `channel_messages`
Messages within channels. Capped at 500 per channel by `PostgresStore`.

| Column | Type | Notes |
|--------|------|-------|
| id | VARCHAR(255) | PK |
| channel_id | VARCHAR(255) | FK to `channels(id)` CASCADE |
| author | VARCHAR(255) | |
| content | TEXT | |
| created_at | INTEGER | |

Indexes: `(channel_id, created_at)`

Added by migration 002: `agent_id UUID REFERENCES agents(id) ON DELETE SET NULL`

#### `scheduled_jobs`
Scheduler job definitions.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | VARCHAR(255) | PK | |
| name | VARCHAR(255) | `''` | |
| description | TEXT | `''` | |
| schedule_type | VARCHAR(32) | `'interval'` | `'interval'` or `'daily'` |
| schedule_seconds | INTEGER | 3600 | For interval type |
| schedule_hour | INTEGER | 0 | For daily type |
| schedule_minute | INTEGER | 0 | For daily type |
| enabled | BOOLEAN | true | |
| handler | VARCHAR(255) | `''` | Handler identifier string |
| last_run | INTEGER | 0 | |
| next_run | INTEGER | 0 | |
| last_result | TEXT | `''` | |
| last_duration | INTEGER | 0 | Seconds |
| run_count | INTEGER | 0 | |
| created_at | INTEGER | 0 | |

#### `nightly_run_history`
Audit trail for nightly consolidation runs. Capped at 30 entries by `PostgresStore`.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGSERIAL | PK |
| stats | JSONB | `'{}'` |
| created_at | INTEGER | |

Indexes: `created_at`

### Migration 002: Agents and Rooms

#### `agents`
Stored agent definitions with editable system prompts.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | UUID | `gen_random_uuid()` | PK |
| slug | VARCHAR(100) | NOT NULL | UNIQUE |
| name | VARCHAR(255) | NOT NULL | |
| description | TEXT | `''` | |
| system_prompt | TEXT | `''` | |
| model | VARCHAR(100) | `''` | |
| tool_access | JSONB | `'[]'` | |
| project_id | UUID | NULL | FK to `projects(id)` SET NULL |
| memory_scope | VARCHAR(255) | `''` | |
| is_default | BOOLEAN | false | |
| is_system | BOOLEAN | false | |
| color | VARCHAR(7) | `'#6366f1'` | Hex color |
| icon | VARCHAR(50) | `'bot'` | |
| created_at | INTEGER | 0 | |
| updated_at | INTEGER | 0 | |

Indexes: `slug`, `project_id`

#### `room_agents`
Junction table linking channels to agents.

| Column | Type | Notes |
|--------|------|-------|
| room_id | VARCHAR(255) | FK to `channels(id)` CASCADE |
| agent_id | UUID | FK to `agents(id)` CASCADE |
| is_active_default | BOOLEAN | |
| added_at | INTEGER | |

PK: `(room_id, agent_id)`

### Foreign Key Cascade Summary

| Parent | Child | ON DELETE |
|--------|-------|-----------|
| conversations | conversation_turns | CASCADE |
| conversations | item_conversations | CASCADE |
| projects | project_history | CASCADE |
| projects | project_steps | CASCADE |
| projects | epics | CASCADE |
| projects | items | CASCADE |
| projects | project_names | CASCADE |
| epics | items | SET NULL |
| items | item_notes | CASCADE |
| items | item_conversations | CASCADE |
| channels | channel_messages | CASCADE |
| channels | room_agents | CASCADE |
| agents | room_agents | CASCADE |
| agents | conversations.agent_id | SET NULL |
| agents | channel_messages.agent_id | SET NULL |
| agents | projects.default_agent_id | SET NULL |
| conversations | tasks.conversation_id | SET NULL |

---

## Redis Usage

Redis is used exclusively for **ephemeral and transient data**. All persistent data has been migrated to PostgreSQL. Redis runs on port 6380 (mapped from container port 6379) to avoid conflicts with other services.

The `RedisStore` class uses the key prefix `cc:`.

### What remains in Redis

| Purpose | Key Pattern | Type | TTL |
|---------|-------------|------|-----|
| **Web auth tokens** | `cc:web:token:{token}` | String (`'1'`) | 86400s (24h) |
| **Distributed locks** | `cc:{key}` | String (timestamp) | Configurable per-call |
| **Chat API history** | `cc:chat_history:{conversationId}` | List of JSON messages | None (trimmed to N entries) |
| **Active project state** | `cc:project:active` | String (project ID) | None |
| **Task completion pub/sub** | `cc:task_completions` channel | Pub/Sub | N/A |
| **Vector embeddings** | RediSearch index | HNSW vectors via redis-stack | None |

### What moved to PostgreSQL

Everything that was previously stored as Redis hashes/sets for tasks, sessions, projects, conversations, memories, skills, channels, scheduled jobs, and nightly run history now lives in PostgreSQL tables. Redis retains only ephemeral session/token data, message history for the Anthropic API chat mode, distributed locks, pub/sub for cross-worker notifications, and the RediSearch vector index.

### Redis connection pool config

```
Host: REDIS_HOST (default 127.0.0.1)
Port: REDIS_PORT (default 6380)
DB:   REDIS_DB (default 0)
Pool: 1-10 connections, 10s connect timeout, 3s wait timeout
```

### Redis Stack

The Docker image is `redis/redis-stack-server:latest`, which includes RediSearch for vector similarity search. The `VectorStore` class creates and manages HNSW indexes for embedding-based memory retrieval. The index is initialized idempotently on startup by `StartupValidationListener`.

---

## Swoole Table In-Memory Cache

`SwooleTableCache` provides cross-worker shared memory using Swoole Tables. These tables are created in the constructor (before server forks) and are shared across all worker processes.

### Tables

| Table | Max Rows | Purpose |
|-------|----------|---------|
| `activeTasks` | 1024 | Track running tasks (task_id, state, pid, started_at) |
| `activeSessions` | 256 | Track active sessions (session_id, task_id, last_activity) |
| `wsConnections` | 64 | Track WebSocket connections (fd, user_id, connected_at, last_ping, last_pong) |
| `activeConversations` | 64 | Track active conversations (conversation_id, user_id, project_id, type, last_activity) |

These are **volatile** -- they reset when the server restarts. They serve as a fast lookup cache for active state that does not need to survive restarts.

---

## Storage Layer Architecture

The system uses a three-tier storage architecture:

```
PostgresStore          RedisStore              SwooleTableCache
(persistent data)      (ephemeral data)        (volatile hot cache)
  |                      |                       |
  |-- Tasks              |-- Auth tokens          |-- Active tasks
  |-- Conversations      |-- Chat API history     |-- Active sessions
  |-- Projects           |-- Distributed locks    |-- WebSocket connections
  |-- Memories           |-- Pub/Sub              |-- Active conversations
  |-- Agents             |-- Active project
  |-- Epics/Items        |-- Vector index
  |-- Channels
  |-- Scheduled Jobs
  |-- Nightly History
  |-- Skills
  |-- Sessions
```

`PostgresStore` normalizes data for the rest of the application. It converts PostgreSQL booleans to `'0'`/`'1'` strings and NULLs to empty strings via `toArray()` for backward compatibility with managers that were originally written against Redis hashes. Nullable UUID foreign keys are converted from empty strings to NULL on write via `nullableUuid()`.

---

## Docker Services

Defined in `docker-compose.yml`:

### Redis

```yaml
Image:     redis/redis-stack-server:latest
Container: claude-connect-redis
Port:      6380:6379
Volume:    redis_data:/data
Args:      --appendonly yes
```

Redis Stack includes RediSearch and RedisJSON modules. Append-only file (AOF) persistence is enabled.

### PostgreSQL

```yaml
Image:     postgres:16-alpine
Container: claude-connect-postgres
Port:      5433:5432
Volume:    postgres_data:/var/lib/postgresql/data
Database:  claude_connect
User:      claude_connect
Password:  claude_connect
```

Migrations `001_initial.sql` and `002_agents_and_rooms.sql` are mounted into `/docker-entrypoint-initdb.d/` for automatic execution on first container creation. Migration `003_memory_redesign.sql` is not auto-mounted (must be applied manually or it was added after initial deployment).

Health check: `pg_isready -U claude_connect` every 5s, 5 retries.

### Port mapping rationale

Both Redis and PostgreSQL use non-standard host ports (6380, 5433) to avoid conflicts with other services (e.g., Laravel Forge, DDEV) that may use the standard ports (6379, 5432).

---

## Database Migration System

Migrations are plain SQL files in `/migrations/`:

| File | Description |
|------|-------------|
| `001_initial.sql` | Full schema: conversations, tasks, projects, epics, items, memories, sessions, skills, channels, scheduled jobs, nightly history |
| `002_agents_and_rooms.sql` | Agents table, room_agents junction, adds agent_id/title columns to existing tables, adds notified_at to tasks |
| `003_memory_redesign.sql` | Adds type/last_surfaced_at/updated_at to memories, creates new indexes, backfills data |

There is no formal migration runner. Migrations 001 and 002 are auto-applied via Docker's `initdb.d` mechanism on first database creation. Migration 003 and subsequent migrations use `DO $$ BEGIN ... EXCEPTION WHEN duplicate_column THEN NULL; END $$;` and `IF NOT EXISTS` patterns to be safely re-runnable.

---

## Swoole Server Configuration

Defined in `config/autoload/server.php`:

```
Mode:           SWOOLE_PROCESS
Server Type:    SERVER_WEBSOCKET (handles both HTTP and WebSocket)
Host:           0.0.0.0
Port:           SERVER_PORT env (default 9501)
Socket:         SWOOLE_SOCK_TCP
```

### Swoole settings

| Setting | Value | Notes |
|---------|-------|-------|
| enable_coroutine | true | |
| worker_num | WORKER_NUM env (default 4) | |
| max_coroutine | 100,000 | |
| max_request | 100,000 | Worker recycling threshold |
| open_tcp_nodelay | true | Disable Nagle's algorithm |
| socket_buffer_size | 10 MB | |
| buffer_output_size | 10 MB | |
| pid_file | runtime/hyperf.pid | |

### Coroutine hooks

In `bin/hyperf.php`:
```php
Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_PROC);
```

All I/O is hooked for coroutine-based async except `SWOOLE_HOOK_PROC`. The proc hook exclusion is notable -- `proc_open` (used for Claude CLI execution) still runs through Swoole's hook infrastructure via `SWOOLE_HOOK_ALL`, but explicit proc hooks are disabled. This is configured at the PHP runtime level before the Hyperf container boots.

### WebSocket callbacks

| Event | Handler |
|-------|---------|
| ON_REQUEST | `Hyperf\HttpServer\Server::onRequest` |
| ON_OPEN | `WebSocketHandler::onOpen` |
| ON_MESSAGE | `WebSocketHandler::onMessage` |
| ON_CLOSE | `WebSocketHandler::onClose` |

### Worker callbacks

| Event | Handler |
|-------|---------|
| ON_WORKER_START | `WorkerStartCallback::onWorkerStart` |
| ON_PIPE_MESSAGE | `PipeMessageCallback::onPipeMessage` |
| ON_WORKER_EXIT | `WorkerExitCallback::onWorkerExit` |

---

## Database Connection Layer

### Custom Postgres connector

`App\Database\PostgresConnector` extends Hyperf's connector to build a PDO DSN for PostgreSQL and configure encoding, timezone, and schema search path on connection.

### Custom Postgres connection

`App\Database\PostgresConnection` extends Hyperf's `Connection` class and provides a custom `PostgresQueryGrammar` for proper PostgreSQL query generation.

### Connection registration

In `config/autoload/dependencies.php`, the PostgresStore factory registers the connection resolver before first use:

```php
Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
    return new PostgresConnection($connection, $database, $prefix, $config);
});
```

The connector is registered as `'db.connector.pgsql'`.

### Database pool config

From `config/autoload/databases.php`:

```
Driver:   pgsql
Host:     DB_HOST (default 127.0.0.1)
Port:     DB_PORT (default 5433)
Database: DB_DATABASE (default claude_connect)
Username: DB_USERNAME (default claude_connect)
Password: DB_PASSWORD (default claude_connect)
Charset:  utf8
Schema:   public
Pool:     1-10 connections, 10s connect timeout, 3s wait timeout, 60s max idle
```

---

## DI Container and Dependency Injection

Defined in `config/autoload/dependencies.php`. Hyperf uses a PSR-11 container with annotation-based auto-wiring. The annotation scanner scans all classes under `app/`.

### Registration patterns

**Direct class mapping** (auto-wired via constructor injection):
```php
RedisStore::class => RedisStore::class,
SwooleTableCache::class => SwooleTableCache::class,
TaskManager::class => TaskManager::class,
// ... many more
```

**Factory closures** (for classes needing explicit configuration):

| Class | Factory reason |
|-------|---------------|
| `PostgresStore` | Registers the pgsql connection resolver before instantiation |
| `VoyageClient` | Requires API key, model, dimensions from config |
| `VectorStore` | Requires Redis instance and dimensions from config |
| `EmbeddingService` | Composed from VoyageClient + VectorStore |
| `AnthropicClient` | Requires API key, model, max tokens, temperature from config |
| `ChatConversationStore` | Requires compaction threshold from config |
| `PostTaskPipeline` | Manually registers pipeline stages in order |

### PostTaskPipeline stages

The pipeline is assembled explicitly with five stages:

1. `ExtractMemoryStage` -- extracts memories from task results
2. `ExtractConversationStage` -- records conversation data, links items
3. `ProjectDetectionStage` -- detects/assigns project context
4. `EmbedConversationStage` -- generates vector embeddings for conversations
5. `EmbedTaskResultStage` -- generates vector embeddings for task results

### Annotation-based injection

Classes use Hyperf's `#[Inject]` attribute for property injection:
```php
#[Inject]
private Redis $redis;
```

And `#[Listener]` / `#[Command]` attributes for auto-discovery of listeners and commands.

---

## Startup Sequence

When `php bin/hyperf.php start` is executed:

### 1. Bootstrap phase (bin/hyperf.php)

1. Set error reporting to E_ALL
2. Define `BASE_PATH`
3. Load Composer autoloader
4. Enable Swoole coroutine hooks: `SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_PROC`
5. Initialize Hyperf's DI class loader (annotation scanning)
6. Load the PSR-11 container from `config/container.php`
7. Get and run the Hyperf Application

### 2. Server initialization

1. Swoole creates the master process
2. `SwooleTableCache` is instantiated (creates shared memory tables before fork)
3. Swoole forks worker processes (default 4)

### 3. Worker start (AfterWorkerStart event)

All listeners respond to `AfterWorkerStart`. Most run only on worker 0 to avoid duplication.

**On all workers:**
- Standard Hyperf framework callbacks fire

**On worker 0 only:**

1. **StartupValidationListener** -- runs validation checks:
   - Verifies Claude CLI binary exists and is executable
   - Pings Redis to confirm connectivity
   - Warns if `WEB_AUTH_PASSWORD` is not set
   - Initializes RediSearch vector index (idempotent via `VectorStore::ensureIndex()`)
   - Seeds default agents (idempotent via `AgentManager::seedDefaultAgents()`)

2. **ProjectOrchestratorListener** -- ensures the "General" workspace project exists, then starts the project orchestrator loop

3. **SchedulerListener** -- starts the `SchedulerRunner` which:
   - Ensures the system channel exists
   - Registers default scheduled jobs (nightly, cleanup, supervisor_health, memory_sync)
   - Begins ticking every 15 seconds to check for due jobs

4. **NightlyConsolidationListener** -- starts the nightly consolidation agent loop

5. **CleanupAgentListener** -- starts the cleanup agent loop

6. **ItemAgentListener** -- starts the item agent loop

7. **AgentSupervisorListener** -- starts the agent supervisor loop

8. **TaskCompletionListener** -- opens a dedicated Redis connection and subscribes to `cc:task_completions` pub/sub channel for real-time task completion notifications, forwarding them to WebSocket clients

Each background service runs in its own Swoole coroutine via `\Swoole\Coroutine::create()`.

---

## Listeners and Background Services

All listeners are in `app/Listener/` and use the `#[Listener]` annotation.

| Listener | Worker | Purpose |
|----------|--------|---------|
| `StartupValidationListener` | 0 | Pre-flight checks on boot |
| `ProjectOrchestratorListener` | 0 | Ensures General project, runs orchestrator loop |
| `SchedulerListener` | 0 | Runs the job scheduler (15s tick) |
| `NightlyConsolidationListener` | 0 | Memory consolidation agent |
| `CleanupAgentListener` | 0 | Task/conversation cleanup agent |
| `ItemAgentListener` | 0 | Work item processing agent |
| `AgentSupervisorListener` | 0 | Monitors agent health |
| `TaskCompletionListener` | 0 | Redis pub/sub for task completions, pushes to WebSocket |

All background services run exclusively on worker 0 to prevent duplicate processing across workers.

---

## Scheduled Tasks

The scheduler is managed by `SchedulerManager` (stores jobs in PostgreSQL) and `SchedulerRunner` (executes due jobs).

### Default scheduled jobs

| Job ID | Name | Schedule | Default | Handler |
|--------|------|----------|---------|---------|
| `nightly_consolidation` | Nightly Consolidation | Daily at 02:00 | Enabled | `nightly` -- backfill embeddings, deduplicate memories, validate against codebase |
| `cleanup` | Cleanup & Pruning | Every 6 hours (21600s) | Enabled | `cleanup` -- reap stale tasks, classify old items, prune ephemeral data |
| `supervisor_health` | Supervisor Health Check | Every 60 seconds | Enabled | `supervisor_health` -- detect stalled tasks, force-fail stuck processes, kill zombie PIDs |
| `memory_sync` | Memory Sync | Every 1 hour (3600s) | Disabled | `memory_sync` -- placeholder, not yet implemented |

### Supervisor health check behavior

The `supervisor_health` handler performs:
- Scans all running tasks; kills any running longer than `SUPERVISOR_STALL_TIMEOUT` (default 1800s / 30 min)
- Sends SIGTERM to the process if it has a PID and is alive
- Force-transitions stalled tasks to FAILED state
- Scans pending tasks; force-fails any pending longer than 10 minutes
- Posts status updates to the system channel

### Schedule types

- **interval**: Runs every N seconds after last execution. First run 30 seconds after registration.
- **daily**: Runs once per day at the specified hour:minute. If the time has passed today, schedules for tomorrow.

---

## HTTP Routes

Defined in `config/routes.php`:

| Method | Path | Handler | Purpose |
|--------|------|---------|---------|
| GET | `/health` | `HealthController::index` | Health check endpoint |
| GET | `/` | `WebController::index` | Serve web frontend |
| GET | `/assets/{file:.+}` | `WebController::asset` | Serve static assets |
| POST | `/api/auth` | `WebController::authenticate` | Password authentication |
| GET | `/conversations[/{path:.*}]` | `WebController::spa` | SPA catch-all |
| GET | `/channels[/{path:.*}]` | `WebController::spa` | SPA catch-all |
| GET | `/projects[/{path:.*}]` | `WebController::spa` | SPA catch-all |
| GET | `/tasks` | `WebController::spa` | SPA catch-all |
| GET | `/memory[/{path:.*}]` | `WebController::spa` | SPA catch-all |
| GET | `/skills` | `WebController::spa` | SPA catch-all |
| GET | `/agents[/{path:.*}]` | `WebController::spa` | SPA catch-all |

WebSocket connections are handled at `ws://localhost:9501/` via the `WebSocketHandler` callbacks configured in the server config.

---

## Environment Variables

### Server

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `claude-connect` | Application name |
| `APP_ENV` | `production` | Environment |
| `SERVER_PORT` | `9501` | HTTP/WebSocket listen port |
| `WORKER_NUM` | `4` | Swoole worker process count |

### Redis

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_HOST` | `127.0.0.1` | Redis host |
| `REDIS_PORT` | `6380` | Redis port (non-standard) |
| `REDIS_AUTH` | `null` | Redis password |
| `REDIS_DB` | `0` | Redis database number |

### PostgreSQL

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `127.0.0.1` | PostgreSQL host |
| `DB_PORT` | `5433` | PostgreSQL port (non-standard) |
| `DB_DATABASE` | `claude_connect` | Database name |
| `DB_USERNAME` | `claude_connect` | Database user |
| `DB_PASSWORD` | `claude_connect` | Database password |

### Claude CLI

| Variable | Default | Description |
|----------|---------|-------------|
| `CLAUDE_CLI_PATH` | `/home/cpeppers/.local/bin/claude` | Path to Claude CLI binary |
| `CLAUDE_MAX_TURNS` | `25` | Max turns per task |
| `CLAUDE_MAX_BUDGET_USD` | `5.00` | Max cost per task |
| `CLAUDE_DEFAULT_MODEL` | `''` (empty) | Model override |
| `CLAUDE_PROCESS_TIMEOUT` | `0` | Process timeout in seconds (0 = no timeout) |
| `CLAUDE_ALLOWED_TOOLS` | `''` (empty) | Comma-separated list of allowed tools |

### Web frontend

| Variable | Default | Description |
|----------|---------|-------------|
| `WEB_AUTH_PASSWORD` | `''` | Password for web auth (empty = no auth) |
| `WEB_USER_ID` | `web_user` | Default user ID for web sessions |

### Embeddings (Voyage AI)

| Variable | Default | Description |
|----------|---------|-------------|
| `VOYAGE_API_KEY` | `''` | Voyage AI API key |
| `VOYAGE_MODEL` | `voyage-3.5-lite` | Embedding model |
| `VOYAGE_DIMENSIONS` | `1024` | Vector dimensions |
| `VOYAGE_BATCH_SIZE` | `64` | Batch size for API calls |

### Chat API (Anthropic direct)

| Variable | Default | Description |
|----------|---------|-------------|
| `CHAT_API_ENABLED` | `false` | Enable Anthropic API chat mode |
| `ANTHROPIC_API_KEY` | `''` | Anthropic API key |
| `CHAT_MODEL` | `claude-sonnet-4-20250514` | Chat model |
| `CHAT_MAX_TOKENS` | `4096` | Max tokens per response |
| `CHAT_MAX_TOOL_ROUNDS` | `10` | Max tool-use rounds |
| `CHAT_COMPACTION_THRESHOLD` | `30` | Messages before compaction |
| `CHAT_TEMPERATURE` | `0.7` | Sampling temperature |

### Agent routing

| Variable | Default | Description |
|----------|---------|-------------|
| `AGENT_ROUTING_MODEL` | `claude-haiku-4-5-20251001` | Model for routing decisions |
| `AGENT_ROUTING_TIMEOUT` | `5` | Routing timeout in seconds |

### Project orchestration

| Variable | Default | Description |
|----------|---------|-------------|
| `PROJECT_MAX_ITERATIONS` | `20` | Max steps per project |
| `PROJECT_MAX_BUDGET_USD` | `10.00` | Max budget per project |
| `PROJECT_CHECKPOINT_INTERVAL` | `5` | Steps between checkpoints |
| `PROJECT_STEP_BUDGET_USD` | `2.00` | Budget per step |
| `PROJECT_ORCHESTRATOR_INTERVAL` | `5` | Orchestrator tick interval (seconds) |
| `PROJECT_AUTO_DETECT` | `true` | Auto-detect project context |

### Nightly consolidation

| Variable | Default | Description |
|----------|---------|-------------|
| `NIGHTLY_ENABLED` | `true` | Enable nightly job |
| `NIGHTLY_RUN_HOUR` | `2` | Hour to run (24h format) |
| `NIGHTLY_RUN_MINUTE` | `0` | Minute to run |
| `NIGHTLY_MAX_BUDGET_USD` | `1.00` | Max budget per run |
| `NIGHTLY_HAIKU_CALL_BUDGET_USD` | `0.05` | Budget per Haiku call |
| `NIGHTLY_BATCH_SIZE` | `20` | Batch size |
| `NIGHTLY_SUMMARIZATION_THRESHOLD` | `50` | Memory count threshold for summarization |
| `NIGHTLY_SIMILARITY_THRESHOLD` | `0.85` | Dedup similarity threshold |

### Cleanup agent

| Variable | Default | Description |
|----------|---------|-------------|
| `CLEANUP_ENABLED` | `true` | Enable cleanup job |
| `CLEANUP_INTERVAL` | `21600` | Interval in seconds (6 hours) |
| `CLEANUP_RETENTION_DAYS_TASKS` | `7` | Days to retain tasks |
| `CLEANUP_RETENTION_DAYS_CONVERSATIONS` | `14` | Days to retain conversations |
| `CLEANUP_BATCH_SIZE` | `15` | Batch size |
| `CLEANUP_MAX_BUDGET_USD` | `0.50` | Max budget per run |
| `CLEANUP_HAIKU_CALL_BUDGET_USD` | `0.05` | Budget per Haiku call |
| `CLEANUP_MAX_ITEMS_PER_RUN` | `200` | Max items to process |
| `CLEANUP_STALE_TASK_TIMEOUT` | `5400` | Stale task timeout (90 minutes) |

### Item agent

| Variable | Default | Description |
|----------|---------|-------------|
| `ITEM_AGENT_ENABLED` | `false` | Enable item agent |
| `ITEM_AGENT_POLL_INTERVAL` | `10` | Poll interval in seconds |
| `ITEM_AGENT_MAX_BUDGET` | `2.00` | Max budget per item |
| `ITEM_AGENT_AUTO_ASSIGN_URGENT` | `false` | Auto-assign urgent items |
| `ITEM_AGENT_PROJECTS` | `''` | Comma-separated project IDs to process |

### Agent supervisor

| Variable | Default | Description |
|----------|---------|-------------|
| `SUPERVISOR_ENABLED` | `false` | Enable supervisor |
| `SUPERVISOR_TICK_INTERVAL` | `30` | Tick interval in seconds |
| `SUPERVISOR_MAX_PARALLEL` | `2` | Max parallel agents |
| `SUPERVISOR_STALL_TIMEOUT` | `1800` | Stall timeout (30 minutes) |
| `SUPERVISOR_MAX_RETRIES` | `1` | Max retries for failed tasks |

---

## CLI Commands

All commands are run via `php bin/hyperf.php <command>`.

### Server

| Command | Description |
|---------|-------------|
| `start` | Start the Swoole HTTP/WebSocket server |

### Project management

| Command | Arguments/Options | Description |
|---------|-------------------|-------------|
| `project:create <name>` | `--cwd`, `--description` | Create a new project workspace |
| `project:list` | | List all project workspaces |

### Memory management

| Command | Arguments/Options | Description |
|---------|-------------------|-------------|
| `memory:list <user_id>` | | List memory facts and recent log for a user |
| `memory:set <user_id> <key> <value>` | | Set a key-value memory fact |
| `memory:backfill` | `--user-id`, `--batch-size`, `--dry-run`, `--include-conversations` | Backfill vector embeddings for memories missing from the vector store |

### Skills (MCP servers)

| Command | Arguments/Options | Description |
|---------|-------------------|-------------|
| `skill:list` | `--scope` (builtin/global/user ID) | List registered MCP server skills |
| `skill:add <name>` | `--command`, `--args`, `--scope` | Register an MCP server skill |
| `skill:remove <name>` | `--scope` | Remove a registered skill |

### Maintenance

| Command | Arguments/Options | Description |
|---------|-------------------|-------------|
| `nightly:run` | `--dry-run`, `--skip-validation`, `--skip-dedup`, `--skip-summarization` | Run nightly memory consolidation manually |
| `cleanup:run` | `--dry-run`, `--days` | Run cleanup agent manually |

### Interactive Claude sessions

| Command | Description |
|---------|-------------|
| `claude-swoole-helper` | Launch interactive Claude session with helper persona |
| `claude-swoole-architect` | Launch interactive Claude session with architect knowledge |

### Logging

Logs go to stdout via Monolog with format: `[datetime] channel.LEVEL: message context`
