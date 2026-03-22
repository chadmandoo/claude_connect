# State Machine and Task Management

This document covers the task lifecycle, Claude CLI process management, output parsing, session management, agent supervision, and related configuration for the Claude Connect system.

---

## Table of Contents

1. [Task Lifecycle](#task-lifecycle)
2. [Task Data Model](#task-data-model)
3. [Task Creation and Execution](#task-creation-and-execution)
4. [Claude CLI Process Management](#claude-cli-process-management)
5. [Output Parsing and Structured Results](#output-parsing-and-structured-results)
6. [Session Management](#session-management)
7. [AgentSupervisor](#agentsupervisor)
8. [Error Handling and Retry Logic](#error-handling-and-retry-logic)
9. [Post-Task Pipeline](#post-task-pipeline)
10. [Configuration Reference](#configuration-reference)

---

## Task Lifecycle

### States

Defined as a PHP 8.1 backed enum in `TaskState.php`:

| State | Value | Terminal? | Description |
|-------|-------|-----------|-------------|
| `PENDING` | `pending` | No | Task created, awaiting execution |
| `RUNNING` | `running` | No | Claude CLI process is active |
| `COMPLETED` | `completed` | Yes | Task finished successfully |
| `FAILED` | `failed` | Yes | Task finished with an error |

### Transition Rules

```
PENDING  --> RUNNING
RUNNING  --> COMPLETED
RUNNING  --> FAILED
FAILED   --> PENDING    (retry path)
```

Transitions are enforced at `TaskState.php:17-25`. The `canTransitionTo()` method (`TaskState.php:27`) validates every transition request. `COMPLETED` is a true terminal state with no outgoing transitions. `FAILED` can transition back to `PENDING` to support retries.

### Transition Enforcement

All state changes go through `TaskManager::transition()` (`TaskManager.php:56-97`), which:

1. Loads the current task from PostgresStore
2. Validates the transition via `canTransitionTo()`; throws `RuntimeException` on invalid transitions
3. Merges any extra data (e.g., `result`, `error`) into the update
4. Sets `started_at` on the first transition to `RUNNING`
5. Sets `completed_at` on any terminal state transition
6. Persists the update to PostgresStore and SwooleTableCache
7. Records a history entry with `from`, `to`, `timestamp`, and `extra` fields
8. Removes the task from the active-task cache on terminal states

---

## Task Data Model

A task record contains the following fields, created at `TaskManager.php:24-42`:

| Field | Type | Description |
|-------|------|-------------|
| `id` | string (UUID v4) | Unique task identifier |
| `prompt` | string | The user's input prompt |
| `session_id` | string | Claude session ID for `--resume` (empty for new sessions) |
| `claude_session_id` | string | Claude CLI session ID returned in output |
| `parent_task_id` | string | ID of the parent task in continuation chains |
| `conversation_id` | string | Conversation this task belongs to |
| `project_id` | string | Project scope (default: `general`) |
| `source` | string | Origin of the task (`web`, `routing`, `nightly`, etc.) |
| `state` | string | Current state (`pending`, `running`, `completed`, `failed`) |
| `result` | string | Claude's output text (after tag extraction) |
| `error` | string | Error message if failed |
| `pid` | int | OS process ID of the Claude CLI process |
| `cost_usd` | string | Total cost in USD (6 decimal places) |
| `images` | string (JSON) | Base64-encoded images from output |
| `progress` | string (JSON) | Task progress metadata |
| `options` | string (JSON) | Serialized options bag (see below) |
| `created_at` | int | Unix timestamp of creation |
| `updated_at` | int | Unix timestamp of last update |
| `started_at` | int | Unix timestamp when moved to RUNNING |
| `completed_at` | int | Unix timestamp when reached terminal state |

### Options Bag

The `options` JSON field carries configuration and context through the task lifecycle:

- `conversation_id` -- Conversation context
- `project_id` -- Project scope
- `source` -- Task origin
- `web_user_id` -- Authenticated user ID
- `agent_id`, `agent_type` -- Agent routing metadata
- `max_turns`, `max_budget_usd` -- Per-task overrides
- `model` -- Model override
- `cwd` -- Working directory for the Claude CLI process
- `mcp_config` -- Path to generated MCP server config
- `append_system_prompt` -- Agent-composed system prompt
- `images` -- Uploaded images (base64, stripped before execution)
- `dispatch_mode` -- `supervisor` for externally-monitored tasks
- `workflow_template` -- Template name for pipeline stage selection

### Storage Layers

Tasks are persisted in **PostgresStore** (durable) and cached in **SwooleTableCache** (in-memory, cross-worker shared). The Swoole Table for active tasks (`SwooleTableCache.php:11-23`) has capacity for 1024 concurrent entries and tracks `task_id`, `state`, `pid`, and `started_at`. Terminal tasks are removed from the cache (`TaskManager.php:94-96`).

The `getTask()` method (`TaskManager.php:99-110`) checks the cache first to determine if a task is active, then always reads the full record from PostgresStore.

---

## Task Creation and Execution

### Creation Flow

`TaskManager::createTask()` (`TaskManager.php:20-54`):

1. Generates a UUID v4 task ID
2. Builds the task record with state `PENDING`
3. Persists to PostgresStore and adds to active-task cache
4. Records the initial `null -> PENDING` transition in history
5. Returns the task ID

### Execution Entry Points

`ProcessManager` offers four execution paths:

| Method | Description | Reference |
|--------|-------------|-----------|
| `executeTask()` | Fire-and-forget in a Swoole coroutine | `ProcessManager.php:71-86` |
| `executeTaskWithCallbacks()` | Coroutine execution with stderr streaming and completion callbacks | `ProcessManager.php:92-115` |
| `continueTask()` | Resume a previous Claude session (creates a new linked task) | `ProcessManager.php:121-140` |
| `continueTaskWithCallbacks()` | Resume with streaming callbacks | `ProcessManager.php:145-163` |

All four wrap the internal `runTask()` method in a `Swoole\Coroutine::create()` call, ensuring non-blocking execution.

### Continuation (Multi-turn)

`continueTask()` and `continueTaskWithCallbacks()` (`ProcessManager.php:121-163`):

1. Load the parent task and extract its `claude_session_id`
2. Create a new task, passing the Claude session ID as `session_id` so `buildCommand()` adds `--resume`
3. Set `parent_task_id` to link the chain
4. Execute the new task

This creates a linked chain of tasks sharing a single Claude CLI conversation.

---

## Claude CLI Process Management

### The `runTask()` Method

`ProcessManager::runTask()` (`ProcessManager.php:165-443`) is the core execution engine. It proceeds through these stages:

#### 1. State Transition to RUNNING (`ProcessManager.php:172`)

Transitions the task from `PENDING` to `RUNNING`.

#### 2. Image Handling (`ProcessManager.php:178-202`)

Uploaded images (base64 in `options.images`) are decoded and written to `/tmp/cc_upload_{taskId}_{index}.{ext}`. File paths are prepended to the prompt. The base64 data is stripped from options to save storage.

#### 3. System Prompt Composition (`ProcessManager.php:205-232`)

The prompt is enriched with agent context in priority order:

1. **Specific agent**: If `agent_id` is set, `AgentPromptBuilder::buildForTask()` generates a scoped system prompt
2. **Default agent**: Falls back to the default agent via `AgentManager::getDefaultAgent()`
3. **Generic prompt**: Uses `PromptLoader::buildGenericPrompt()` with memory context

The composed system prompt is stored in `options.append_system_prompt`.

#### 4. MCP Server Configuration (`ProcessManager.php:236-259`)

For authenticated users, generates an MCP config file containing:
- User's registered skills from `SkillRegistry`
- The `cc-system` MCP server (provides task context, user ID, project ID)

The config file path is stored in `options.mcp_config`.

#### 5. Command Construction (`ProcessManager.php:584-631`)

`buildCommand()` assembles the Claude CLI arguments:

```
claude -p <prompt> --output-format json --dangerously-skip-permissions
       --max-turns <N> [--max-budget-usd <N>] [--model <model>]
       [--resume <session_id>] [--mcp-config <path>]
       [--append-system-prompt <prompt>]
```

Key flags:
- `-p` passes the prompt non-interactively
- `--output-format json` ensures structured output for parsing
- `--dangerously-skip-permissions` grants all tool access without approval prompts
- `--resume` continues a previous Claude session (set when `session_id` is non-empty)

#### 6. Environment Setup (`ProcessManager.php:637-670`)

`buildEnvironment()` constructs the process environment:
- Inherits `$_ENV` and `$_SERVER` variables
- **Critically unsets `CLAUDECODE` and `CLAUDE_CODE_ENTRY_POINT`** to prevent nested session detection
- Ensures `~/.local/bin` is on `PATH`
- Passes `SSH_AUTH_SOCK` for git operations

#### 7. Process Execution (`ProcessManager.php:276-307`)

The system uses `Swoole\Coroutine\System::exec()` for coroutine-friendly process execution:

```php
$fullCommand = $envPrefix . $shellCommand . ' 2>' . escapeshellarg($stderrFile);
$result = \Swoole\Coroutine\System::exec($fullCommand, true);
```

- Environment variables are exported as shell assignment prefixes (`KEY='value' KEY2='value2' claude ...`)
- Stderr is redirected to a temp file (`/tmp/cc_stderr_{taskId}.txt`)
- If `cwd` is set, the command is prefixed with `cd <cwd> &&`
- The exec call blocks the coroutine (not the worker) until the process completes

After execution, stdout, exit code, and stderr are collected and the stderr temp file is cleaned up.

### Concurrent Pipe Reading (Legacy `readPipesConcurrently`)

`ProcessManager.php:455-531` contains a `readPipesConcurrently()` method originally designed for `proc_open`-based execution. It uses `stream_select()` to read from both stdout and stderr pipes simultaneously, preventing the classic pipe deadlock that occurs when one pipe's OS buffer (~64KB) fills while the parent blocks reading the other.

The algorithm:
1. Sets both pipes to non-blocking mode
2. Loops with `stream_select()` (5-second poll intervals)
3. Reads up to 64KB from whichever pipe has data
4. Fires an `onStderrChunk` callback for real-time progress
5. Closes each pipe on EOF
6. Exits when both pipes are closed and the process has stopped
7. Enforces timeout by killing the process if the deadline is exceeded

### Process Termination (`ProcessManager.php:536-560`)

`killProcess()` implements graceful shutdown:

1. Sends `SIGTERM` for graceful shutdown
2. Polls every 100ms for up to 5 seconds
3. Sends `SIGKILL` if the process is still alive

#### 8. Timeout Detection (`ProcessManager.php:322-332`)

If `process_timeout > 0` and the elapsed wall-clock time exceeds it, the task is marked as failed with a timeout error.

#### 9. Cleanup (`ProcessManager.php:317-319`)

After execution:
- MCP config file is cleaned up via `McpConfigGenerator::cleanup()`
- Uploaded image temp files are deleted

---

## Output Parsing and Structured Results

### OutputParser (`OutputParser.php`)

Parses the JSON output from `claude --output-format json`. The `parse()` method (`OutputParser.php:13-70`) handles several cases:

| Condition | Result |
|-----------|--------|
| Empty output | Failure: "Empty output from Claude CLI" |
| Invalid JSON, exit code 0 | Success with raw text as result |
| Invalid JSON, exit code != 0 | Failure with raw text as error |
| JSON with `error` field | Failure with the error value |
| JSON with `subtype: error_max_turns` | Failure with turn count, permission denials, and cost |
| JSON with result/content, exit code != 0, empty result | Failure |
| JSON with result/content | Success with extracted text and images |

### Result Extraction (`OutputParser.php:72-99`)

`extractResult()` tries these fields in order:
1. `data.result` (string)
2. `data.content` (string)
3. `data.message` (string)
4. `data.content` (array of blocks) -- concatenates all `text` fields
5. Falls back to `json_encode($data)` for unknown structures

### Image Extraction (`OutputParser.php:188-207`)

`extractImages()` scans `data.content` blocks for `type: image` with base64 source data, returning an array of `{data, media_type}` objects.

### ParsedOutput Value Object (`ParsedOutput.php`)

Immutable data class with:

| Property | Type | Description |
|----------|------|-------------|
| `success` | bool | Whether the task succeeded |
| `result` | string | Extracted text result |
| `sessionId` | ?string | Claude session ID for continuation |
| `error` | ?string | Error message |
| `costUsd` | float | Total cost from `total_cost_usd` or `cost_usd` |
| `inputTokens` | int | Input token count |
| `outputTokens` | int | Output token count |
| `images` | array | Extracted base64 images |
| `raw` | array | Original decoded JSON |

Constructed via `fromSuccess()` or `fromFailure()` named constructors.

### Inline Tag Extraction

After parsing, the result text is scanned for two types of inline tags:

#### Memory Tags (`OutputParser.php:107-127`)

Pattern: `<memory category="..." importance="...">content</memory>`

Extracted memories are stored via `MemoryManager::storeMemory()` and the tags are stripped from the result text shown to the user. See `ProcessManager.php:347-359`.

#### Work Item Tags (`OutputParser.php:138-182`)

Pattern: `<work_item title="..." priority="..." epic="...">description</work_item>`

Only active when `project_id != 'general'`. Creates work items via `ItemManager::createItem()`, optionally finding or creating the named epic. Tags are stripped from the result. See `ProcessManager.php:363-387`.

---

## Session Management

### SessionManager (`SessionManager.php`)

Manages session lifecycle independent of tasks. Sessions track a sequence of related tasks.

| Method | Description | Reference |
|--------|-------------|-----------|
| `createSession()` | Creates a UUID-based session in state `active` | `SessionManager.php:20-37` |
| `getSession()` | Retrieves session data from PostgresStore | `SessionManager.php:39-42` |
| `updateSession()` | Updates session fields and refreshes cache activity | `SessionManager.php:44-49` |
| `closeSession()` | Sets state to `closed`, removes from cache | `SessionManager.php:51-58` |
| `archiveSession()` | Sets state to `archived`, removes from cache | `SessionManager.php:65-72` |
| `listSessions()` | Lists all sessions from store | `SessionManager.php:60-63` |

### Session Data Model

| Field | Type | Description |
|-------|------|-------------|
| `id` | string (UUID) | Session identifier |
| `claude_session_id` | string | Linked Claude CLI session |
| `state` | string | `active`, `closed`, or `archived` |
| `created_at` | int | Unix timestamp |
| `updated_at` | int | Unix timestamp |
| `last_task_id` | string | Most recent task in this session |

### Claude Session Continuity

The Claude CLI session ID (returned in JSON output as `session_id` or `sessionId`) is stored on the task via `TaskManager::setClaudeSessionId()` (`TaskManager.php:143-146`). When a conversation is continued:

1. `ProcessManager::continueTask()` reads `claude_session_id` from the parent task
2. Passes it as the new task's `session_id`
3. `buildCommand()` adds `--resume <session_id>` to the CLI invocation
4. Claude CLI resumes the existing conversation context

---

## AgentSupervisor

### Overview

`AgentSupervisor` (`AgentSupervisor.php`) is a long-running monitoring loop that runs inside a Swoole coroutine. It does **not** execute tasks directly -- execution is delegated to the external `bin/task-worker.php` process. The supervisor monitors running tasks for completion, failure, and stalls, and handles notifications and post-processing.

### Lifecycle

- **Start**: `start()` (`AgentSupervisor.php:46-67`) enters the tick loop if `mcp.supervisor.enabled` is true
- **Stop**: `stop()` (`AgentSupervisor.php:69-72`) sets `$running = false` to exit the loop
- **Tick interval**: Configurable via `SUPERVISOR_TICK_INTERVAL` (default 30 seconds)

### Tick Cycle (`AgentSupervisor.php:74-80`)

Each tick performs two operations:

1. **`checkRunningTasks()`** -- Inspects tracked tasks for state changes and stalls
2. **`monitorExternalTasks()`** -- Discovers tasks managed by the external worker

### Running Task Checks (`AgentSupervisor.php:85-147`)

For each tracked task:

| Detected State | Action |
|----------------|--------|
| Task deleted | Remove from tracking |
| `completed` | Run `handleTaskCompletion()`, remove from tracking |
| `failed` (retries < max) | Call `resetTaskForRetry()`, remove from tracking (re-picked up next tick) |
| `failed` (retries exhausted) | Notify via WebSocket, remove from tracking |
| `running` past stall timeout | Check if process is alive via `posix_kill($pid, 0)` |
| Stalled, process dead | Mark failed ("Process died unexpectedly"), notify |
| Stalled, process alive | Send `SIGTERM`, mark failed ("Stall timeout exceeded"), notify |

### External Task Monitoring (`AgentSupervisor.php:156-190`)

Discovers tasks with `dispatch_mode: supervisor` in the `running` state and begins tracking them. Also checks recently completed/failed tasks against the tracking list to catch transitions that happened between ticks.

### Task Completion Handling (`AgentSupervisor.php:195-224`)

`handleTaskCompletion()`:

1. Appends the result to ephemeral chat history via `ChatConversationStore`
2. Sends two WebSocket notifications:
   - `task.state_changed` -- for UI badge/toast updates
   - `chat.result` -- renders the result in the conversation view
3. Runs the post-task pipeline in a separate coroutine

---

## Error Handling and Retry Logic

### Process-Level Error Handling

`ProcessManager::executeTask()` and `executeTaskWithCallbacks()` wrap `runTask()` in a try/catch (`ProcessManager.php:73-85`). If any exception escapes:

1. The error message is logged
2. `setTaskError()` records the error on the task
3. The task is transitioned to `FAILED`
4. The `onComplete` callback (if present) fires with the failed task
5. Inner exceptions during error handling are silently caught (best-effort)

### Session Recovery (`ProcessManager.php:402-433`)

When a `--resume` task fails because the Claude session no longer exists (`"No conversation found with session ID"`):

1. The conversation history is reconstructed from `ConversationManager::getConversationTurns()`
2. Turns are formatted as `[Role]: content` blocks
3. The task is reset via `resetTaskForRetry()` which:
   - Clears `session_id` (so `--resume` is not added on retry)
   - Replaces the prompt with the full conversation context
   - Resets state to `PENDING`
4. `runTask()` is called recursively for immediate retry

### Supervisor-Level Retry (`AgentSupervisor.php:107-119`)

The AgentSupervisor implements a configurable retry mechanism:

- `max_retries` (default: 1) controls how many times a failed task is retried
- On failure with retries remaining, `resetTaskForRetry()` moves the task back to `PENDING`
- The task is picked up on the next supervisor tick
- When retries are exhausted, a failure notification is sent via WebSocket

### Stall Detection (`AgentSupervisor.php:122-145`)

Tasks in `RUNNING` state are monitored for stalls:

- **Stall timeout**: `SUPERVISOR_STALL_TIMEOUT` (default: 1800 seconds / 30 minutes)
- **Process alive check**: `posix_kill($pid, 0)` verifies the process exists
- **Dead process**: Immediately marked failed
- **Alive but stalled**: `SIGTERM` sent, then marked failed

### Timeout Handling

Two levels of timeout:

1. **Process timeout** (`CLAUDE_PROCESS_TIMEOUT`): Checked after `Swoole\Coroutine\System::exec()` returns. If wall-clock time exceeds the timeout, the task is failed with "Task timed out after N seconds" (`ProcessManager.php:322-332`).
2. **Stall timeout** (`SUPERVISOR_STALL_TIMEOUT`): Checked by the AgentSupervisor tick loop. Kills processes that exceed the stall duration.

---

## Post-Task Pipeline

When the AgentSupervisor detects a completed task, it runs the `PostTaskPipeline` in a separate coroutine (`AgentSupervisor.php:229-249`).

### Pipeline Architecture

`PostTaskPipeline` (`PostTaskPipeline.php`) executes registered `PipelineStage` implementations in order:

1. Each stage's `shouldRun()` method is checked
2. `execute()` is called with a shared `PipelineContext`
3. Failures are logged but never abort the pipeline

### Pipeline Context

`PipelineContext` carries:
- `task` -- the completed task record
- `userId` -- the authenticated user
- `templateConfig` -- workflow template settings (name, max_turns, max_budget_usd)
- `conversationId` -- the conversation ID
- `conversationType` -- e.g., `task`
- `bag` -- mutable key-value store for inter-stage communication

### Workflow Templates

Templates define which pipeline stages run and their resource limits:

| Template | Max Turns | Budget | Pipeline Stages |
|----------|-----------|--------|-----------------|
| `quick` | 5 | $0.50 | post_result, extract_memory |
| `standard` | 35 | $5.00 | post_result, upload_images, extract_memory, extract_conversation, project_detection, embed_conversation, embed_task_result |
| `deep` | 75 | $10.00 | Same as standard |
| `browse` | 10 | $1.00 | post_result, upload_images, extract_memory |

---

## Configuration Reference

All configuration is in `config/autoload/mcp.php`, driven by environment variables.

### Claude CLI (`mcp.claude`)

| Key | Env Var | Default | Description |
|-----|---------|---------|-------------|
| `cli_path` | `CLAUDE_CLI_PATH` | `/home/cpeppers/.local/bin/claude` | Path to the Claude CLI binary |
| `max_turns` | `CLAUDE_MAX_TURNS` | `25` | Default max conversation turns |
| `max_budget_usd` | `CLAUDE_MAX_BUDGET_USD` | `5.00` | Default per-task spending cap |
| `default_model` | `CLAUDE_DEFAULT_MODEL` | `""` (use CLI default) | Model override |
| `process_timeout` | `CLAUDE_PROCESS_TIMEOUT` | `0` (no timeout) | Wall-clock timeout in seconds |
| `allowed_tools` | `CLAUDE_ALLOWED_TOOLS` | `""` | Comma-separated tool names (unused when `--dangerously-skip-permissions` is set) |

### Supervisor (`mcp.supervisor`)

| Key | Env Var | Default | Description |
|-----|---------|---------|-------------|
| `enabled` | `SUPERVISOR_ENABLED` | `false` | Enable the AgentSupervisor loop |
| `tick_interval` | `SUPERVISOR_TICK_INTERVAL` | `30` | Seconds between supervision ticks |
| `max_parallel_agents` | `SUPERVISOR_MAX_PARALLEL` | `2` | Max concurrent supervised tasks |
| `stall_timeout` | `SUPERVISOR_STALL_TIMEOUT` | `1800` | Seconds before a running task is considered stalled |
| `max_retries` | `SUPERVISOR_MAX_RETRIES` | `1` | Retry attempts for failed tasks |

### Workflow Templates (`mcp.workflow`)

| Key | Default | Description |
|-----|---------|-------------|
| `default_template` | `standard` | Template used when none is specified |
| `auto_detect` | `true` | Automatically select template based on prompt keywords |

### Cleanup (`mcp.cleanup`)

| Key | Env Var | Default | Description |
|-----|---------|---------|-------------|
| `stale_task_timeout` | `CLEANUP_STALE_TASK_TIMEOUT` | `5400` (90 min) | Timeout for orphaned running tasks |

---

## WebSocket Notifications

`TaskNotifier` (`app/Web/TaskNotifier.php`) broadcasts real-time updates to connected clients:

| Message Type | When Sent | Content |
|--------------|-----------|---------|
| `task.state_changed` | On completion or failure | Task ID, state, conversation ID, prompt preview, cost, result/error preview |
| `chat.result` | On completion (if conversation exists) | Full result text, images, cost, duration, Claude session ID |
| `task.progress` | During execution (via stderr callback) | Elapsed time, stderr line count |

Notifications are filtered:
- Internal tasks (`source: routing, extraction, cleanup, nightly, item_agent, manager`) only post to the system channel, not to user WebSockets
- Duplicate notifications are prevented via an atomic `markNotified()` flag in PostgresStore
- Messages can be targeted to a specific user via `web_user_id`
