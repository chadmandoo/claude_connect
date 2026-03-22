# Architecture: Claude Connect

## Overview

Claude Connect is a web-first project-based agent system that orchestrates Claude CLI tasks as a state machine. It provides:

- **Async task execution** — run Claude CLI prompts as background tasks with streaming progress
- **Session continuity** — resume Claude conversations across multiple tasks via `--resume`
- **WebSocket real-time chat** — interactive browser-based interface with live streaming
- **Smart routing** — Haiku-based message classification routes to PM or Project agents
- **Project workspaces** — persistent projects with epics, work items, and scoped memory
- **MCP proxy** — connect to and relay tool calls to external MCP servers
- **Dual-layer storage** — Redis persistence + Swoole Table in-memory cache

**Tech stack:** PHP 8.3, Swoole 6.0, Hyperf 3.1, Redis 7

---

## Runtime

### Swoole Coroutine Model

The server runs as a Swoole process-mode HTTP server on port 9501 with coroutine-based async I/O.

**Entry point:** `bin/hyperf.php`

```
Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_PROC);
```

- `SWOOLE_HOOK_ALL` hooks sockets, file I/O, sleep, and DNS for coroutine scheduling
- `~SWOOLE_HOOK_PROC` excludes proc_* functions — Claude CLI processes use `proc_open` which must run synchronously within their coroutine (Swoole schedules the coroutine itself)
- Worker count defaults to 4 (`WORKER_NUM` env var)
- Max coroutines per worker: 100,000

### Server Configuration

| Setting | Value |
|---------|-------|
| Mode | `SWOOLE_PROCESS` |
| Host | `0.0.0.0` |
| Port | `9501` (configurable via `SERVER_PORT`) |
| Workers | `4` (configurable via `WORKER_NUM`) |
| Max coroutines | `100,000` |
| Buffer sizes | `2 MB` (socket + output) |
| TCP_NODELAY | Enabled |

---

## Project Structure

```
claude_connect/
├── app/
│   ├── Agent/
│   │   ├── PromptComposer.php       # Builds system prompts per agent type
│   │   └── Router.php               # Haiku-based message classifier
│   ├── Claude/
│   │   ├── OutputParser.php          # Parses Claude CLI JSON output
│   │   ├── ParsedOutput.php          # Value object for parsed results
│   │   ├── ProcessManager.php        # Executes Claude CLI via proc_open in coroutines
│   │   └── SessionManager.php        # Manages persistent Claude sessions
│   ├── Cleanup/
│   │   ├── CleanupAgent.php          # Automated triage, consolidation, and pruning
│   │   └── CleanupConfig.php         # Cleanup configuration value object
│   ├── Command/                      # CLI commands (memory, skills, etc.)
│   ├── Controller/
│   │   ├── HealthController.php      # GET /health endpoint
│   │   └── WebController.php         # Web frontend + auth
│   ├── Conversation/
│   │   ├── ConversationManager.php   # Multi-turn conversation objects
│   │   └── ConversationType.php      # Enum: brainstorm, planning, task, discussion, check_in
│   ├── Epic/
│   │   ├── EpicManager.php           # Epic CRUD + backlog management
│   │   └── EpicState.php             # Enum: open, in_progress, done, cancelled
│   ├── Item/
│   │   ├── ItemManager.php           # Work item CRUD + state transitions
│   │   ├── ItemState.php             # Enum: open, in_progress, blocked, done, cancelled
│   │   └── ItemPriority.php          # Enum: low, normal, high, urgent
│   ├── Mcp/
│   │   ├── Registry.php              # Tool provider class registry
│   │   ├── ServerFactory.php         # Creates MCP Server instance
│   │   └── Tools/                    # MCP tool providers
│   ├── Memory/
│   │   └── MemoryManager.php         # Structured memories with scoped context
│   ├── Pipeline/
│   │   ├── PostTaskPipeline.php      # Sequential stage runner
│   │   ├── PipelineContext.php       # Immutable context bag
│   │   └── Stages/                   # Extract memory, conversations, project detection
│   ├── Project/
│   │   ├── ProjectManager.php        # Project/workspace CRUD
│   │   ├── ProjectOrchestrator.php   # Autonomous multi-step project execution
│   │   └── ProjectState.php          # Enum: planning, active, paused, stalled, completed, cancelled, workspace
│   ├── Prompts/
│   │   └── PromptLoader.php          # Loads prompt files
│   ├── Skills/
│   │   ├── BuiltinSkills.php         # Built-in MCP servers (filesystem, fetch, browser)
│   │   ├── McpConfigGenerator.php    # Generates /tmp/cc-mcp-{taskId}.json
│   │   └── SkillRegistry.php         # Manages skill registration
│   ├── StateMachine/
│   │   ├── TaskManager.php           # Task CRUD + state transitions
│   │   └── TaskState.php             # State enum with transition rules
│   ├── Storage/
│   │   ├── RedisStore.php            # Redis persistence layer
│   │   └── SwooleTableCache.php      # In-memory Swoole tables
│   ├── Web/
│   │   ├── ChatManager.php           # WebSocket chat flow
│   │   ├── WebAuthManager.php        # Password auth + tokens
│   │   └── WebSocketHandler.php      # WebSocket message dispatch
│   └── Workflow/
│       └── TemplateResolver.php      # Auto-detect workflow template
├── config/
│   ├── autoload/
│   │   ├── dependencies.php          # DI bindings
│   │   ├── mcp.php                   # Claude CLI + workflow config
│   │   ├── redis.php                 # Redis connection pool
│   │   └── server.php                # Swoole server settings
│   ├── routes.php                    # HTTP route definitions
│   └── container.php                 # DI container bootstrap
├── prompts/                          # System prompts (helper, pm, project_agent, extraction/)
├── public/                           # Web frontend (HTML, JS, CSS)
├── tests/                            # PHPUnit test suite
├── docker-compose.yml                # Redis container
└── claude-connect.service            # systemd unit file
```

---

## State Machine

### TaskState Enum

```
PENDING ──→ RUNNING ──→ COMPLETED
                    └──→ FAILED ──→ PENDING (retry)
```

| State | Terminal | Allowed Transitions |
|-------|----------|-------------------|
| `PENDING` | No | → `RUNNING` |
| `RUNNING` | No | → `COMPLETED`, `FAILED` |
| `COMPLETED` | Yes | (none) |
| `FAILED` | Yes | → `PENDING` (retry) |

---

## Web Chat Flow

```
Browser
    │
    │  WebSocket: chat.send
    ▼
WebSocketHandler
    │
    ▼
ChatManager.sendChat()
    │
    ├── Router.classify() → {project_id, agent, type, confidence}
    ├── ConversationManager.createConversation()
    ├── TaskManager.createTask()
    ├── ProcessManager.executeTaskWithCallbacks()
    │       │
    │       ├── PromptComposer → PM / Project / Generic prompt
    │       ├── MemoryManager → Scoped memory context
    │       ├── proc_open(claude CLI)
    │       ├── readPipesConcurrently() → stream_select
    │       └── OutputParser.parse()
    │
    ├── WebSocket: chat.progress (streaming stderr)
    ├── WebSocket: chat.result (completed)
    └── extractMemoryAsync() (type-aware extraction)
```

### Agent Routing

The Router uses Haiku to classify each message:
- **PM agent**: cross-project questions, brainstorming, planning
- **Project agent**: focused work within a specific project's context

### Workflow Templates

| Template | Max Turns | Budget | Use Case |
|----------|-----------|--------|----------|
| `quick` | 5 | $0.50 | Simple questions, lookups |
| `standard` | 35 | $5.00 | Normal tasks |
| `deep` | 75 | $10.00 | Build, implement, refactor |
| `browse` | 10 | $1.00 | Screenshot, navigate |

---

## Storage

### Redis (RedisStore)

All keys use prefix `cc:`.

| Key Pattern | Type | Description |
|-------------|------|-------------|
| `cc:tasks:{id}` | Hash | Task data |
| `cc:tasks:{id}:history` | List | State transitions |
| `cc:task_index` | Sorted Set | Task IDs by creation time |
| `cc:sessions:{id}` | Hash | Session data |
| `cc:conversations:{id}` | Hash | Conversation data |
| `cc:conversations:{id}:turns` | List | Conversation turns |
| `cc:projects:{id}` | Hash | Project/workspace data |
| `cc:epics:{id}` | Hash | Epic data |
| `cc:items:{id}` | Hash | Work item data |
| `cc:memories:{userId}` | Sorted Set | Structured memories |
| `cc:memories:{userId}:{projectId}` | Sorted Set | Project-scoped memories |
| `cc:user:tasks:{userId}` | Sorted Set | Per-user task index |

**Connection:** `127.0.0.1:6380` (Docker-mapped from container port 6379).

### SwooleTableCache

Fixed-size shared-memory tables for active tasks, sessions, and WebSocket connections.

---

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SERVER_PORT` | `9501` | HTTP server port |
| `WORKER_NUM` | `4` | Swoole worker count |
| `REDIS_HOST` | `127.0.0.1` | Redis host |
| `REDIS_PORT` | `6380` | Redis port |
| `CLAUDE_CLI_PATH` | `/home/cpeppers/.local/bin/claude` | Path to Claude CLI binary |
| `CLAUDE_MAX_TURNS` | `25` | Default max agentic turns |
| `CLAUDE_MAX_BUDGET_USD` | `5.00` | Default max budget per task |
| `CLAUDE_DEFAULT_MODEL` | (empty) | Default model override |
| `WEB_AUTH_PASSWORD` | (empty) | Web frontend password |
| `WEB_USER_ID` | `web_user` | Web frontend user ID |

---

## Deployment

### Docker Compose

Redis 7 Alpine container on port `6380:6379` with AOF persistence.

### systemd Service

`claude-connect.service` runs as user `cpeppers`, auto-restarts on failure (5 second delay). Depends on `docker.service`.
