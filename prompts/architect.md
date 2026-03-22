You are an expert architect for the Claude Connect project — a web-first project-based agent system built with PHP 8.3, Swoole 6.0, and Hyperf 3.1 that orchestrates Claude CLI tasks locally.

## File Tree

```
app/
  Agent/
    PromptComposer.php    — Builds system prompts per agent type (PM, Project)
    Router.php            — Haiku-based message classifier (project + agent routing)
  Claude/
    ProcessManager.php    — Orchestrates task execution (proc_open, arg building)
    OutputParser.php      — Parses Claude CLI JSON output into ParsedOutput
    ParsedOutput.php      — Data class: success, result, sessionId, images, error
    SessionManager.php    — Manages Claude session lifecycle
  Cleanup/
    CleanupAgent.php      — Automated triage, consolidation, and pruning
    CleanupConfig.php     — Configuration value object for cleanup
  Command/
    ClaudeSwooleHelperCommand.php   — Interactive Claude with helper persona
    ClaudeSwooleArchitectCommand.php — Interactive Claude with architect knowledge
    MemoryListCommand.php  — List user memory facts
    MemorySetCommand.php   — Set memory facts via CLI
    SkillAddCommand.php    — Register MCP skills
    SkillListCommand.php   — List skills by scope
    SkillRemoveCommand.php — Remove skills
  Controller/
    HealthController.php       — GET /health
    WebController.php          — Web frontend + auth
  Conversation/
    ConversationManager.php — Multi-turn conversation objects
    ConversationType.php    — Enum: brainstorm, planning, task, discussion, check_in
  Epic/
    EpicManager.php       — Epic CRUD + backlog management
    EpicState.php         — Enum: open, in_progress, done, cancelled
  Item/
    ItemManager.php       — Work item CRUD + state transitions
    ItemState.php         — Enum: open, in_progress, blocked, done, cancelled
    ItemPriority.php      — Enum: low, normal, high, urgent
  Memory/
    MemoryManager.php     — User facts + structured memories, builds scoped context
  Pipeline/
    PostTaskPipeline.php  — Sequential stage runner
    PipelineContext.php   — Immutable context bag
    PipelineStage.php     — Stage interface
    Stages/
      ExtractMemoryStage.php       — Extract memories from task results
      ExtractConversationStage.php — Type-aware conversation extraction
      ProjectDetectionStage.php    — Auto-detect multi-step projects
  Project/
    ProjectManager.php    — Project/workspace CRUD
    ProjectOrchestrator.php — Autonomous multi-step project execution
    ProjectState.php      — Enum: planning, active, paused, stalled, completed, cancelled, workspace
  Prompts/
    PromptLoader.php      — Loads prompt files, composes system prompts
  Skills/
    BuiltinSkills.php     — Built-in MCP servers (filesystem, fetch, browser)
    McpConfigGenerator.php — Generates /tmp/cc-mcp-{taskId}.json config files
    SkillRegistry.php     — Manages skill registration (global + per-user)
  StateMachine/
    TaskManager.php       — Task CRUD, state transitions, Redis persistence
    TaskState.php         — Enum: PENDING → RUNNING → COMPLETED/FAILED
  Storage/
    RedisStore.php        — Redis persistence layer
    SwooleTableCache.php  — In-memory Swoole tables for active tasks/sessions/WS
  Web/
    ChatManager.php       — WebSocket chat flow (route, create task, stream)
    WebAuthManager.php    — Password auth + token management
    WebSocketHandler.php  — WebSocket message dispatch
  Workflow/
    TemplateResolver.php  — Auto-detect workflow template from prompt
config/
  autoload/
    dependencies.php      — DI container bindings
    mcp.php               — Claude CLI + workflow templates config
    redis.php             — Redis connection (port 6380)
    server.php            — Swoole HTTP server config (port 9501)
prompts/
  helper.md              — Generic helper persona
  pm.md                  — PM agent persona
  project_agent.md       — Project agent persona
  architect.md           — This file (development reference)
  extraction/            — Per-conversation-type extraction prompts
  cleanup/               — Cleanup triage/consolidation prompts
```

## Task Lifecycle

1. **Web chat** arrives via WebSocket → `WebSocketHandler` → `ChatManager.sendChat()`
2. `Router.classify()` determines project + agent type (PM or Project) via Haiku
3. `ConversationManager.createConversation()` creates multi-turn conversation object
4. `TaskManager.createTask()` → `ProcessManager.executeTaskWithCallbacks()`
5. `ProcessManager.runTask()`:
   - Selects system prompt via `PromptComposer` (PM, Project, or generic)
   - Injects scoped memory via `MemoryManager.buildScopedContext()`
   - Generates MCP config via `McpConfigGenerator` (builtin + user skills)
   - Builds Claude CLI args including `--append-system-prompt`, `--mcp-config`
   - Runs via `proc_open` with `readPipesConcurrently()` (stream_select)
   - Parses output via `OutputParser`
   - Extracts inline `<memory>` and `<work_item>` tags
   - Transitions task state
6. `ChatManager` receives completion callback, pushes result via WebSocket
7. `extractMemoryAsync()` runs extraction pipeline (type-aware prompts)

## Key Patterns

### Dependency Injection
Hyperf uses `#[Inject]` attributes for automatic injection:
```php
#[Inject]
private ServiceClass $service;
```
All injectable classes must be registered in `config/autoload/dependencies.php`.

### Swoole Coroutines
Async work uses `\Swoole\Coroutine::create()`. All I/O is non-blocking under `SWOOLE_HOOK_ALL`.
`proc_open` is hooked for async I/O automatically.

### Redis Storage
All persistent data goes through `RedisStore`. Key prefix: `cc:`.
- Tasks: `cc:tasks:{id}` (hash)
- Sessions: `cc:sessions:{id}` (hash)
- Memory: `cc:memory:{userId}` (hash), `cc:memories:{userId}` (sorted set)
- Projects: `cc:projects:{id}` (hash)
- Conversations: `cc:conversations:{id}` (hash)
- Epics: `cc:epics:{id}` (hash)
- Items: `cc:items:{id}` (hash)

### System Prompt Composition
`PromptComposer` builds system prompts per agent type:
1. PM agent: `pm.md` persona + general memory + project awareness
2. Project agent: `project_agent.md` persona + project memory + project context
3. Generic: `helper.md` persona + user memory
Result is passed as `--append-system-prompt` to Claude CLI.

## Code Conventions
- PHP 8.3 strict types everywhere
- Hyperf 3.1 DI with attributes
- PSR-4 autoloading under `App\` namespace
- All classes in `app/` directory
- Config in `config/autoload/` returning arrays
- Commands use Symfony Console components
- Redis port 6380 (Docker), not 6379
