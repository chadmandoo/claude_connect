# Claude Connect - Technical Reference

## Core Architecture

Claude Connect runs as a Swoole process-mode HTTP/WebSocket server. Each request is handled within a coroutine, allowing high concurrency without threads.

```
Browser (WebSocket)
    │
    ▼
Swoole WebSocket Server (port 9501)
    │
    ├── HTTP Routes → Controllers
    │   ├── GET /health → HealthController
    │   ├── GET / → WebController (frontend)
    │   └── POST /api/auth → WebController (JWT)
    │
    └── WebSocket → WebSocketHandler → ChatManager
        │
        ├── Router (Haiku classification)
        │   └── Determines: project, agent type, conversation type
        │
        ├── PromptComposer (dynamic prompt building)
        │   └── Injects: agent persona, memory context, project awareness
        │
        ├── ProcessManager (Claude CLI execution)
        │   └── proc_open → stream_select → OutputParser
        │
        └── PostTaskPipeline (after completion)
            ├── ExtractMemoryStage
            ├── ExtractConversationStage
            ├── ProjectDetectionStage
            ├── EmbedConversationStage
            └── EmbedTaskResultStage
```

## Chat Flow (Detailed)

1. User sends message via WebSocket (`chat.send`)
2. `ChatManager::sendChat()` invoked
3. `Router::classify()` uses Haiku to determine:
   - `project_id` (or `general`)
   - `agent` type (pm, project, generic)
   - `type` (brainstorm, planning, task, discussion, check_in)
   - `confidence` (0.0-1.0)
4. `ConversationManager::createConversation()` if new
5. `PromptComposer` builds system prompt with:
   - Agent-specific persona prompt (from `prompts/` directory)
   - `MemoryManager::buildSystemPromptContext()` — core + relevant memories
   - Project metadata and awareness blocks
6. `ProcessManager::executeTaskWithCallbacks()`:
   - Builds CLI command with `--max-turns`, `--budget-usd`, `--system-prompt`
   - Spawns subprocess via `proc_open`
   - `readPipesConcurrently()` with `stream_select()` for stdout/stderr
   - Emits `chat.progress` WebSocket messages during execution
   - `OutputParser::parse()` extracts JSON result
7. WebSocket `chat.result` sent to browser
8. `PostTaskPipeline::run()` executes all stages

## Storage Schema (Redis)

All keys use the `cc:` prefix.

### Tasks
| Key | Type | Content |
|-----|------|---------|
| `cc:tasks:{id}` | Hash | Task data (prompt, state, result, cost, etc.) |
| `cc:tasks:{id}:history` | List | State transition audit trail |
| `cc:task_index` | Sorted Set | Task IDs by creation timestamp |
| `cc:user:tasks:{userId}` | Sorted Set | Per-user task index |

### Conversations
| Key | Type | Content |
|-----|------|---------|
| `cc:conversations:{id}` | Hash | Conversation metadata |
| `cc:conversations:{id}:turns` | List | Turn history (role, content, cost, task_id) |

### Projects & Items
| Key | Type | Content |
|-----|------|---------|
| `cc:projects:{id}` | Hash | Project/workspace data |
| `cc:projects:{id}:history` | List | State transitions |
| `cc:epics:{id}` | Hash | Epic data |
| `cc:items:{id}` | Hash | Work item data |

### Memory
| Key | Type | Content |
|-----|------|---------|
| `cc:memories:{userId}` | Sorted Set | General memories |
| `cc:memories:{userId}:{projectId}` | Sorted Set | Project-scoped memories |
| `cc:memories:{userId}:log` | List | Memory change log |

### Vectors
| Key | Type | Content |
|-----|------|---------|
| `cc:vectors:{namespace}:{itemId}` | String | Voyage embeddings |
| `cc:vector_index:{namespace}` | Set | Namespace tracking |

## Memory System

### Storage Tiers
- **General memories** — user-wide, apply to all contexts
- **Project-scoped memories** — isolated per project workspace

### Memory Properties
- `category`: preference, project, fact, context
- `importance`: low, normal, high
- Content text + optional vector embedding

### Relevance Ranking (Hybrid)
When retrieving relevant memories:
1. **Vector search** (70% weight) — cosine similarity via Voyage embeddings
2. **Keyword matching** (30% weight) — with recency bias
3. Fallback to keyword-only if embeddings unavailable

### Nightly Consolidation (5 phases)
Runs at configurable hour (default 2 AM) on worker 0:
1. **Backfill** — embed memories missing vectors
2. **Validation** — Haiku validates accuracy per project
3. **Deduplication** — merge similar memories (similarity > 0.85)
4. **Summarization** — cluster and summarize large categories (15+ items)
5. **Orphan Cleanup** — remove vectors for deleted memories

## Agent System

### Agent Types
| Agent | Prompt File | Purpose |
|-------|-------------|---------|
| PM | `prompts/pm.md` | Project management, planning, coordination |
| Project | `prompts/project_agent.md` | Project-specific execution |
| Item | `prompts/item_agent.md` | Individual work item processing |
| Architect | `prompts/architect.md` | Architecture planning |
| Chat PM | `prompts/chat_pm.md` | Conversational PM (API mode) |
| Helper | `prompts/helper.md` | General assistance |

### AgentSupervisor
- Polls every 30s for pending tasks
- Max parallel agents: configurable (default 2)
- Stall detection: 1800s timeout, kills stuck processes
- Retry logic with max retries
- Priority ordering: urgent > high > normal > low

### ItemAgent
- Polls every 10s for IN_PROGRESS items assigned to `agent`
- Auto-assigns urgent items if configured
- Creates Claude CLI tasks per work item
- Transitions: IN_PROGRESS → REVIEW (success) or BLOCKED (failure)
- 10-minute timeout per item

## MCP System Server

`bin/cc-system-mcp.php` is a standalone JSON-RPC 2.0 server (stdio) that Claude agents use to interact with Claude Connect. No Swoole/Hyperf dependency — pure PHP with direct Redis.

### Available Tools (10)
| Tool | Purpose |
|------|---------|
| `cc_check_task_status` | Check task state, result, timing |
| `cc_get_task_output` | Get full task result |
| `cc_list_tasks` | List recent tasks (with state filter) |
| `cc_create_task` | Spawn sub-task for supervisor |
| `cc_search_memory` | Search general + project-scoped memories |
| `cc_store_memory` | Persist memory with category/importance |
| `cc_list_items` | List work items by project/state |
| `cc_update_item` | Transition item state |
| `cc_list_projects` | List all projects |
| `cc_report_progress` | Update task progress indicator |

### Environment Variables
| Variable | Required | Default |
|----------|----------|---------|
| `CC_USER_ID` | Yes | — |
| `CC_TASK_ID` | No | — |
| `CC_PROJECT_ID` | No | `general` |
| `CC_REDIS_HOST` | No | `127.0.0.1` |
| `CC_REDIS_PORT` | No | `6380` |

## Dependency Injection

The DI container (`config/autoload/dependencies.php`) registers 26+ services with factory functions. Key bindings:

### Storage Layer
- `RedisStore` — primary persistence (all Redis operations)
- `SwooleTableCache` — in-memory shared tables for active sessions

### Core Services
- `TaskManager` — task CRUD + state transitions
- `ProcessManager` — Claude CLI execution
- `SessionManager` — persistent Claude sessions
- `OutputParser` — CLI JSON output parsing

### Agent Services
- `Router` — Haiku-based message classification
- `PromptComposer` — dynamic prompt building
- `AgentSupervisor` — background task orchestration
- `ItemAgent` — work item automation
- `TemplateResolver` — workflow template matching

### Memory & Embedding
- `MemoryManager` — memory CRUD + relevance ranking
- `VoyageClient` — Voyage AI API wrapper
- `VectorStore` — Redis vector storage
- `EmbeddingService` — high-level embedding operations

### Pipeline
- `PostTaskPipeline` — 5 registered stages
- Each stage implements `PipelineStage` interface

### Web
- `WebSocketHandler` — WebSocket message dispatch
- `ChatManager` — chat flow coordination
- `WebAuthManager` — password/token auth
- `TaskNotifier` — real-time task updates

## Swoole Configuration

```php
'settings' => [
    'worker_num'            => env('WORKER_NUM', 4),
    'max_coroutine'         => 100000,
    'max_request'           => 100000,
    'socket_buffer_size'    => 10 * 1024 * 1024,  // 10 MB
    'buffer_output_size'    => 10 * 1024 * 1024,  // 10 MB
    'open_tcp_nodelay'      => true,
    'pid_file'              => BASE_PATH . '/runtime/hyperf.pid',
]
```

Coroutine hooks: `SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_PROC` (all except process functions, since Claude CLI uses `proc_open`).

## Frontend

Terminal-themed single-page application served from `public/`:
- `index.html` — login overlay, tabbed interface (Chat, Conversations, Projects, Memory)
- `assets/app.js` (~2,400 lines) — WebSocket client, UI state management
- `assets/style.css` (~2,300 lines) — dark terminal theme, responsive layout
- Syntax highlighting via highlight.js CDN

## Testing

```bash
vendor/bin/phpunit
```

Test suite covers:
- `StateMachine/` — TaskState transitions, TaskManager
- `Claude/` — ProcessManager, SessionManager, OutputParser, ParsedOutput
- `Storage/` — SwooleTableCache, RedisStore
- `Controller/` — HealthController

Config: `phpunit.xml` — strict mode (warnings/risky tests fail), commands excluded from coverage.

## Backup

`scripts/backup-redis.sh` performs daily Redis backups:
1. Triggers `BGSAVE` in Redis container
2. Copies RDB + AOF files from container
3. Creates dated `tar.gz` archive
4. Retains last 7 days, prunes older backups
5. Default location: `/srv/backups/redis/`
