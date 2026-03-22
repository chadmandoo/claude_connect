# Agent System and Routing

This document covers how Claude Connect's agent system works: the built-in agents, how they are created and managed, how prompts are composed, how messages are routed to agents, the skills/MCP system, workflow templates, and room-agent associations.

---

## Table of Contents

1. [System Agents](#system-agents)
2. [Agent Creation, Management, and Seeding](#agent-creation-management-and-seeding)
3. [Agent Prompt Building](#agent-prompt-building)
4. [Message Routing and Agent Resolution](#message-routing-and-agent-resolution)
5. [Skills and MCP System](#skills-and-mcp-system)
6. [Workflow Templates](#workflow-templates)
7. [Room-Agent Associations](#room-agent-associations)
8. [Custom Agent Creation](#custom-agent-creation)
9. [Agent Supervisor](#agent-supervisor)
10. [Agent Handoff](#agent-handoff)

---

## System Agents

Claude Connect seeds 5 system agents on first boot. Each has a unique slug, a prompt loaded from `prompts/*.md`, a color, and an icon. System agents cannot be deleted and their slugs cannot be changed.

### PM (`pm`) -- Default Agent

- **Prompt file:** `prompts/chat_pm.md`
- **Color:** `#6366f1` (indigo) | **Icon:** `briefcase`
- **Role:** Conversational Project Manager. The PM is the default agent for all new conversations. It responds quickly and conversationally, helping the user plan, brainstorm, and delegate.
- **Behavior:**
  - Answers questions, brainstorming, planning, and status checks directly in conversation.
  - Delegates code work, file editing, debugging, and research to background agents using the `create_task` tool.
  - Manages work items with `create_item`, `update_item`, `list_items`.
  - Uses `search_memory` and `store_memory` for persistent context.
  - Writes detailed, specific prompts when creating tasks, including what to do, which project/files, expected outcome, and constraints.

### General (`general`)

- **Prompt file:** `prompts/general.md`
- **Color:** `#3b82f6` (blue) | **Icon:** `bot`
- **Role:** General-purpose assistant. Handles any request that does not need a specialized agent.
- **Behavior:**
  - Answers questions on any topic directly.
  - Helps with writing, analysis, research, and problem-solving.
  - Delegates code work and multi-step technical work to background tasks via `create_task`.
  - Concise and direct -- leads with the answer, not the reasoning.

### Project Agent (`project`)

- **Prompt file:** `prompts/project_agent.md`
- **Color:** `#10b981` (emerald) | **Icon:** `code`
- **Role:** Focused project specialist. Executes tasks within a specific project's context.
- **Behavior:**
  - Has deep domain knowledge about its assigned project via project-scoped memory.
  - Reads project context before starting work and follows existing patterns.
  - Reports changes and outcomes clearly; flags issues and decisions needing attention.
  - Creates work items for follow-up work, bugs, and remaining tasks using `<work_item>` tags in output (tags are stripped from visible response).
  - Work items can include `title`, `priority` (low/normal/high/urgent), and `epic` (creates epic if it does not exist).

### Architect (`architect`)

- **Prompt file:** `prompts/helper.md`
- **Color:** `#f59e0b` (amber) | **Icon:** `wrench`
- **Role:** Full CLI access agent with browser automation, fetch, and filesystem tools. Designed for bug fixes, feature additions, code review, and general personal assistant work.
- **Behavior:**
  - Has full Playwright browser automation (navigate, screenshot, click, type, scroll, etc.).
  - Has fetch (retrieve URL content as text/markdown) and filesystem MCP tools.
  - Stores and retrieves persistent long-term memory using `<memory>` blocks with categories (preference, project, fact, context) and importance levels (high, normal, low).
  - Creates work items using `<work_item>` tags.
  - Executes tasks rather than listing steps. Direct and action-oriented.
  - Can handle multi-step project work when given "Project Goal" and "Full Plan" context.

### Claude Swoole (`claude-swoole`)

- **Prompt file:** `prompts/claude_swoole.md`
- **Color:** `#ec4899` (pink) | **Icon:** `wrench`
- **Role:** Dedicated development agent for the Claude Connect codebase itself. PHP 8.3 + Swoole 6.0 + Hyperf 3.1 specialist.
- **Behavior:**
  - Knows the full project stack: Swoole coroutine HTTP server, Hyperf 3.1, PostgreSQL, Redis, Claude CLI, React + TypeScript frontend.
  - Knows key architecture files: `WebSocketHandler.php`, `ChatManager.php`, `AgentManager.php`, `ProcessManager.php`, etc.
  - Knows code conventions: strict types, `#[Inject]` DI, coroutine-based async, Redis on port 6380.
  - Fixes bugs, adds features, refactors code, reviews code, helps with migrations, and builds frontend components.
  - Direct and technical; shows code snippets and diffs; flags risks and breaking changes.

---

## Agent Creation, Management, and Seeding

**Key file:** `app/Agent/AgentManager.php`

### Database Schema

Agents are stored in PostgreSQL (`agents` table, migration `002_agents_and_rooms.sql`):

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Primary key, auto-generated |
| `slug` | VARCHAR(100) | Unique identifier for `@mentions` and lookups |
| `name` | VARCHAR(255) | Display name |
| `description` | TEXT | Short description of the agent's role |
| `system_prompt` | TEXT | The full system prompt content |
| `model` | VARCHAR(100) | Override model for this agent (empty = use default) |
| `tool_access` | JSONB | Tool access configuration (array) |
| `project_id` | UUID (nullable) | Scoped to a specific project |
| `memory_scope` | VARCHAR(255) | Memory scope identifier |
| `is_default` | BOOLEAN | Whether this is the default agent for new conversations |
| `is_system` | BOOLEAN | System agents cannot be deleted or have slugs changed |
| `color` | VARCHAR(7) | Hex color code for UI |
| `icon` | VARCHAR(50) | Icon identifier for UI |
| `created_at` | INTEGER | Unix timestamp |
| `updated_at` | INTEGER | Unix timestamp |

### Seeding (`seedDefaultAgents`)

Seeding is idempotent. For each of the 5 system agents, it:

1. Checks if an agent with that slug already exists. If so, skips.
2. Loads the prompt content from `prompts/{prompt_file}.md` using `PromptLoader`.
3. Creates the agent with `createAgent()`, setting `is_system = true`.

Seeding can be triggered via the WebSocket message `agents.seed`, which also calls `backfillConversationAgents()` to assign the PM agent to any conversations that have no `agent_id`.

### Default Agent Resolution

`getDefaultAgent()` follows this fallback chain:

1. Query for any agent where `is_default = true`.
2. Fall back to the agent with slug `pm`.
3. As a last resort, call `seedDefaultAgents()` and try again.

Only one agent can be the default at a time. Setting a new default clears `is_default` on all other agents first.

### Agent CRUD via WebSocket

All agent management happens over WebSocket messages:

| Message Type | Action |
|-------------|--------|
| `agents.list` | List all agents (optional `project_id` filter) |
| `agents.get` | Get a single agent by `agent_id` |
| `agents.create` | Create a custom agent (slug, name, description, system_prompt, model, tool_access, project_id, memory_scope, is_default, color, icon) |
| `agents.update` | Update agent fields (system agents cannot have slug changed) |
| `agents.delete` | Delete a non-system agent |
| `agents.seed` | Re-seed system agents and backfill conversations |

---

## Agent Prompt Building

**Key file:** `app/Agent/AgentPromptBuilder.php`

The `AgentPromptBuilder.build()` method composes the full system prompt that Claude receives. It assembles multiple sections in order:

### 1. Base Agent Prompt

The agent's `system_prompt` field (loaded from the prompt `.md` file during seeding, or custom-written for user-created agents).

### 2. Current Date

```
## Current Date
2026-03-16 14:30 CDT
```

### 3. Active Projects

Lists all project workspaces with their name, ID, description, and working directory:

```
## Active Projects
- **My Project** (id: `abc-123`) -- Web application [cwd: `/srv/myproject`]
```

### 4. Recent Background Tasks

Shows the last 5 tasks that were dispatched via the supervisor (background execution mode). Each entry includes state, ID, prompt snippet, and cost:

```
## Recent Background Tasks
- [completed] `task-id`: Fix the login bug ($0.42)
```

### 5. User Memory Context

Memory is scoped by user, project, and agent:

- If a `projectId` is provided (and is not "general"), calls `memoryManager->buildScopedContext()` -- this returns memories scoped to that specific project and agent.
- Otherwise, calls `memoryManager->buildSystemPromptContext()` -- returns general user memories relevant to the current prompt, scoped to the agent.

The memory system uses the current prompt text to determine relevance of stored memories.

### 6. Available Agents Awareness

Lists all other agents (excluding the current one) so the agent can suggest the user talk to a more appropriate specialist:

```
## Available Agents
You can suggest the user talk to another agent if their request is outside your expertise:
- **General** (`@general`) -- General-purpose assistant
- **Project Agent** (`@project`) -- Focused project specialist
```

### Task-Specific Prompt Building

`buildForTask()` is a convenience wrapper that looks up an agent by ID and calls `build()`. This is used by `ProcessManager.runTask()` when executing CLI tasks.

The full prompt is passed to the Claude CLI via the `--append-system-prompt` flag.

---

## Message Routing and Agent Resolution

**Key file:** `app/Web/ChatManager.php`

### Chat Message Flow (API Mode)

When a user sends a chat message via `sendChat()` or `sendChatApi()`:

1. **Explicit agent selection:** If the client sends an `agentId`, that agent is used.
2. **Existing conversation:** If continuing an existing conversation and no agent was explicitly specified, the agent stored on the conversation record is used.
3. **Fallback:** If no agent is resolved, `getDefaultAgent()` is called (returns the PM agent).

### Agent-to-Project Scoping

If the resolved agent has a `project_id` set, the conversation is automatically scoped to that project:

- The `routedProjectId` is set to the agent's project.
- The `agentType` is set to `project`.
- The project name is looked up for display purposes.

This means custom agents bound to a project automatically route all their work to that project's context.

### Channel/Room Routing (`@mention` system)

When a message is sent to a channel (`channels.send`), the system checks for `@slug` mentions:

1. `@claude` (legacy) -- Resolves to the room's default agent, or falls back to the global default agent (PM).
2. `@{slug}` (e.g., `@architect`, `@general`) -- Looks up the agent by slug via `getAgentBySlug()`.

If a mentioned agent is found, a coroutine is spawned to call `chatManager->sendRoomAgentReply()`, which:

1. Strips the `@mention` from the prompt.
2. Builds a channel-aware system prompt by appending channel context to the agent's prompt.
3. Fetches the last 20 channel messages for conversation history.
4. Calls the Anthropic API with the agent's prompt + channel context.
5. Saves the reply as a channel message attributed to the agent.
6. Broadcasts the reply to all connected WebSocket clients.

### Two Execution Modes

Claude Connect has two paths for executing agent work:

1. **API mode** (`mcp.chat.enabled = true`): The Anthropic Messages API is called directly from the Swoole server. The agent has access to tools (`create_task`, `check_task_status`, `search_memory`, etc.) and can use them in a tool-use loop. This is used for conversational chat.

2. **CLI/Supervisor mode**: Tasks are queued in PostgreSQL with `dispatch_mode = supervisor` and picked up by the external `bin/task-worker.php` process, which runs Claude CLI via `proc_open`. The system prompt is injected via `--append-system-prompt`. This is used for background work.

---

## Skills and MCP System

The skills system manages MCP (Model Context Protocol) server configurations that give Claude CLI tasks access to external tools.

### BuiltinSkills (`app/Skills/BuiltinSkills.php`)

Three MCP servers are always available:

| Skill | Command | Purpose |
|-------|---------|---------|
| `filesystem` | `npx @modelcontextprotocol/server-filesystem /tmp` | Read/write files in `/tmp` |
| `fetch` | `uvx mcp-server-fetch` | Retrieve URL content as text/markdown |
| `browser` | `npx @playwright/mcp@latest` | Full Playwright browser automation |

### SkillRegistry (`app/Skills/SkillRegistry.php`)

The registry manages three tiers of skills with an override hierarchy:

1. **Builtin skills** -- Always present (from `BuiltinSkills`).
2. **Global skills** -- Registered with scope `global`, available to all users.
3. **Per-user skills** -- Registered with a specific `userId`, available only to that user.

`getSkillsForUser()` merges all three tiers. User skills override global, global overrides builtin (via `array_merge` order).

Skills are stored in PostgreSQL via `PostgresStore.setSkill()` / `getAllSkills()` / `deleteSkill()`.

### McpConfigGenerator (`app/Skills/McpConfigGenerator.php`)

When a task is executed, `ProcessManager.runTask()` generates a temporary MCP config JSON file:

1. Calls `skillRegistry->getSkillsForUser()` to get all applicable skills.
2. Adds the `cc-system` MCP server -- a PHP script (`bin/cc-system-mcp.php`) that provides task context (user ID, task ID, project ID) back to the Claude CLI session.
3. Calls `mcpConfigGenerator->generateForTask()`, which writes a JSON file to `/tmp/cc-mcp-{taskId}.json` in the format:
   ```json
   {
     "mcpServers": {
       "filesystem": { "command": "npx", "args": [...] },
       "fetch": { "command": "uvx", "args": [...] },
       "browser": { "command": "npx", "args": [...] },
       "cc-system": { "command": "php", "args": [...], "env": {...} }
     }
   }
   ```
4. The path is passed to Claude CLI via `--mcp-config`.
5. After task completion, `cleanupMcpConfig()` deletes the temp file.

---

## Workflow Templates

**Key file:** `app/Workflow/TemplateResolver.php`
**Config:** `config/autoload/mcp.php` -> `workflow.templates`

Workflow templates control task budgets, turn limits, and post-processing pipeline stages. There are 4 templates:

### quick

- **Label:** Quick Answer
- **Max turns:** 5 | **Max budget:** $0.50
- **Pipeline stages:** `post_result`, `extract_memory`
- **Auto-detect keywords:** "what is", "how do", "explain", "define", "quick question"
- **Use case:** Simple questions that need a short, fast answer.

### standard (default)

- **Label:** Standard Task
- **Max turns:** 35 | **Max budget:** $5.00
- **Pipeline stages:** `post_result`, `upload_images`, `extract_memory`, `extract_conversation`, `project_detection`, `embed_conversation`, `embed_task_result`
- **Auto-detect keywords:** none (this is the fallback)
- **Use case:** General-purpose work.

### deep

- **Label:** Deep Work
- **Max turns:** 75 | **Max budget:** $10.00
- **Pipeline stages:** `post_result`, `upload_images`, `extract_memory`, `extract_conversation`, `project_detection`, `embed_conversation`, `embed_task_result`
- **Auto-detect keywords:** "build", "implement", "refactor", "architect", "redesign", "create"
- **Use case:** Complex, multi-step technical work.

### browse

- **Label:** Browse
- **Max turns:** 10 | **Max budget:** $1.00
- **Pipeline stages:** `post_result`, `upload_images`, `extract_memory`
- **Auto-detect keywords:** "browse", "screenshot", "navigate", "visit", "look at"
- **Use case:** Web browsing and visual inspection tasks.

### Template Resolution

`TemplateResolver.resolve()` picks a template in this priority order:

1. **Explicit name:** If the client passes a template name (e.g., `deep`), use that.
2. **Auto-detection:** If `mcp.workflow.auto_detect` is enabled (default: `true`), scan the user's prompt for keyword matches. The **longest matching keyword wins** to avoid false positives from short matches. For example, "quick question" (14 chars) beats "quick" (5 chars).
3. **Default:** Falls back to the `mcp.workflow.default_template` config value (`standard`).

---

## Room-Agent Associations

**Key files:** `app/Agent/AgentManager.php`, `app/Storage/PostgresStore.php`
**Schema:** `room_agents` junction table (migration `002_agents_and_rooms.sql`)

Rooms (channels) can have multiple agents associated with them. Each room-agent association has an `is_active_default` flag.

### Junction Table: `room_agents`

| Column | Type | Description |
|--------|------|-------------|
| `room_id` | VARCHAR(255) | References `channels(id)` |
| `agent_id` | UUID | References `agents(id)` |
| `is_active_default` | BOOLEAN | Whether this is the room's default agent |
| `added_at` | INTEGER | Unix timestamp |
| Primary key | (room_id, agent_id) | Composite |

### WebSocket API

| Message Type | Action |
|-------------|--------|
| `rooms.add_agent` | Add an agent to a room (with optional `is_default` flag) |
| `rooms.remove_agent` | Remove an agent from a room |
| `rooms.set_default` | Set one agent as the room's default (clears previous default) |
| `channels.detail` | Returns channel info including the `agents` array |

### How Room Agents Are Used

When a user sends a message to a channel with `@claude`:

1. The system calls `getRoomDefaultAgent(channelId)`.
2. If a default room agent exists, that agent handles the reply.
3. If no default is set, falls back to the global default agent (PM).

When a user sends `@{slug}`, the agent is looked up by slug directly, bypassing room associations.

Room agents are returned when a channel detail is requested, so the frontend can display which agents are available in that room.

---

## Custom Agent Creation

Users can create custom agents via the `agents.create` WebSocket message. A custom agent accepts:

- **slug** (required): Unique identifier, used for `@mentions`.
- **name** (required): Display name.
- **description**: What the agent does.
- **system_prompt**: The full system prompt text. This is the base that `AgentPromptBuilder` will augment with date, projects, tasks, memory, and agent awareness.
- **model**: Override the default Claude model for this agent.
- **tool_access**: JSON array of tool access configuration.
- **project_id**: Bind the agent to a specific project. All conversations with this agent will be scoped to that project.
- **memory_scope**: Custom memory scope identifier.
- **is_default**: Set as the default agent for new conversations (clears previous default).
- **color**: Hex color for UI display.
- **icon**: Icon identifier for UI display.

Custom agents (where `is_system = false`) can be freely edited and deleted. Their system prompts go through the same `AgentPromptBuilder` augmentation as system agents.

### Project-Scoped Agents

Setting `project_id` on an agent creates a project specialist. When this agent is selected:

- The conversation is automatically scoped to that project.
- Memory context is loaded for that project scope.
- The `agentType` is set to `project`, which affects how the system prompt is built and how tasks are dispatched.

---

## Agent Supervisor

**Key file:** `app/Agent/AgentSupervisor.php`

The AgentSupervisor is a long-running Swoole coroutine that monitors background tasks. It is enabled via `mcp.supervisor.enabled` config.

### Tick Loop

Every `tick_interval` seconds (default: 30), the supervisor:

1. **Checks running tasks** it is tracking for completion, failure, or stalls.
2. **Monitors external tasks** -- discovers running tasks dispatched to the external `bin/task-worker.php` process that it is not yet tracking.

### Stall Detection

If a task has been running longer than `stall_timeout` (default: 1800 seconds / 30 minutes):

1. Checks if the process is still alive via `posix_kill($pid, 0)`.
2. If the process is dead, marks the task as failed.
3. If still alive but past the timeout, sends `SIGTERM` to kill it and marks failed.

### Retry Logic

Failed tasks are retried up to `max_retries` times (default: 1). The supervisor calls `resetTaskForRetry()` which re-queues the task for the next tick.

### Post-Completion

When a task completes, the supervisor:

1. Appends the result to the chat conversation store.
2. Sends WebSocket notifications: `task.state_changed` (for badge/toast) and `chat.result` (to render in chat).
3. Spawns a coroutine to run the post-task pipeline (`PostTaskPipeline`) with the template config (name, max_turns, max_budget_usd).

---

## Agent Handoff

**Key file:** `app/Chat/ChatToolHandler.php`, `app/Chat/ToolDefinitions.php`

In API mode, agents have a `handoff_agent` tool that allows them to suggest switching to a different agent:

```json
{
  "name": "handoff_agent",
  "input_schema": {
    "properties": {
      "agent_slug": { "type": "string" },
      "reason": { "type": "string" }
    },
    "required": ["agent_slug"]
  }
}
```

When an agent calls `handoff_agent`:

1. The handler looks up the target agent by slug.
2. Returns the target agent's `id`, `slug`, `name`, and a suggestion message.
3. The calling agent can then communicate to the user that another agent would be better suited for their request.

This works in concert with the "Available Agents" awareness block in the system prompt, which lists all other agents so the current agent knows what specialists are available.

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `app/Agent/AgentManager.php` | Agent CRUD, seeding, room management |
| `app/Agent/AgentPromptBuilder.php` | Compose system prompts with context |
| `app/Agent/AgentSupervisor.php` | Monitor background tasks, handle completions |
| `app/Skills/SkillRegistry.php` | Manage builtin/global/user skill tiers |
| `app/Skills/BuiltinSkills.php` | Define the 3 always-available MCP servers |
| `app/Skills/McpConfigGenerator.php` | Write temporary MCP config JSON files |
| `app/Workflow/TemplateResolver.php` | Resolve workflow templates by name or auto-detect |
| `app/Web/ChatManager.php` | Route chat messages to agents, handle API and CLI modes |
| `app/Web/WebSocketHandler.php` | WebSocket message dispatch for agent CRUD and room management |
| `app/Chat/ToolDefinitions.php` | Tool schemas for Anthropic API (including handoff_agent) |
| `app/Chat/ChatToolHandler.php` | Execute tool calls from Anthropic API responses |
| `app/Claude/ProcessManager.php` | Execute Claude CLI tasks with agent prompts and MCP config |
| `app/Prompts/PromptLoader.php` | Load prompt `.md` files from disk |
| `config/autoload/mcp.php` | All workflow, agent, supervisor, and chat config |
| `migrations/002_agents_and_rooms.sql` | Database schema for agents and room-agent junctions |
| `prompts/chat_pm.md` | PM agent prompt |
| `prompts/general.md` | General agent prompt |
| `prompts/project_agent.md` | Project Agent prompt |
| `prompts/helper.md` | Architect agent prompt |
| `prompts/claude_swoole.md` | Claude Swoole agent prompt |
