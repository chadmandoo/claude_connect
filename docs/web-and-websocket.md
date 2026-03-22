# Web Frontend & WebSocket System

## Server Architecture

### Swoole WebSocket Mode

Claude Connect runs on a Swoole 6.0 coroutine HTTP/WebSocket server (port 9501) managed by the Hyperf 3.1 framework. The same server process handles both HTTP requests (for serving the SPA and API endpoints) and persistent WebSocket connections (for real-time chat and data operations).

Key characteristics:

- **Coroutine-per-message**: Every inbound WebSocket message is dispatched inside a `\Swoole\Coroutine::create()` call, so long-running handlers (e.g., Claude CLI invocation) never block other connections.
- **Heartbeat**: The server sends a `ping` frame every 30 seconds. If a client misses 3 consecutive pongs, the server disconnects it (`1001 Heartbeat timeout`).
- **Connection tracking**: Active WebSocket connections are stored in a `SwooleTableCache` (in-memory Swoole Table) keyed by file descriptor (fd), with `user_id`, `last_ping`, and `last_pong` timestamps.

### HTTP Routes

Defined in `config/routes.php`:

| Method | Path | Handler | Purpose |
|--------|------|---------|---------|
| GET | `/health` | `HealthController::index` | JSON health check (Redis, Postgres, Swoole stats) |
| GET | `/` | `WebController::index` | Serve `public/index.html` (SPA entry point) |
| GET | `/assets/{file:.+}` | `WebController::asset` | Serve static assets with ETag caching |
| POST | `/api/auth` | `WebController::authenticate` | REST-based password authentication (alternative to WS auth) |
| GET | `/conversations[/{path}]`, `/channels[/{path}]`, `/projects[/{path}]`, `/tasks`, `/memory[/{path}]`, `/skills`, `/agents[/{path}]` | `WebController::spa` | SPA catch-all -- returns `index.html` for client-side routing |

### Static Asset Serving & ETag Caching

`WebController::asset()` handles static files from `public/assets/`:

- **Directory traversal protection**: Rejects paths containing `..`.
- **MIME detection**: Maps file extensions to content types (html, css, js, json, png, jpg, gif, svg, ico, woff2).
- **ETag caching**: Generates an ETag from `md5_file()`. If the client sends a matching `If-None-Match` header, the server returns `304 Not Modified`.
- **Cache-Control**: HTML files get `no-cache`; all other assets (typically hash-named by the bundler) get `public, max-age=31536000, immutable`.

### SPA Serving

Any request to a client-side route (e.g., `/conversations/abc123`) hits the SPA catch-all, which returns `public/index.html` with `Cache-Control: no-cache`. The React app's Wouter router then handles routing client-side.

### Health Check

`GET /health` returns:

```json
{
  "status": "healthy | degraded",
  "timestamp": "ISO 8601",
  "checks": {
    "redis": "ok | error",
    "postgres": "ok | error",
    "swoole": "ok"
  },
  "stats": {
    "active_tasks": 3,
    "active_sessions": 1,
    "worker_id": 42
  }
}
```

Status is `"healthy"` only when both Redis and Postgres are reachable.

---

## Authentication Flow

Authentication is password-based with session tokens stored in Redis.

### Sequence

1. **Client connects via WebSocket** with a token in the query string: `ws://host:9501/?token=<token>`.
2. **Server validates the token** against Redis (`WebAuthManager::validateToken`).
   - If valid: stores the connection in SwooleTableCache, sends `auth.ok` with `user_id`, starts heartbeat timer.
   - If invalid/missing: sends `auth.required`.
3. **Client without a valid token** shows the `LoginOverlay` component.
4. **User submits password**: client sends `{ type: "auth", password: "..." }` over the WebSocket.
5. **Server authenticates** via `WebAuthManager::authenticate`:
   - Compares the password to `mcp.web.auth_password` config using `hash_equals`.
   - On success: generates a 32-character hex token (`bin2hex(random_bytes(16))`), stores it in Redis with a 24-hour TTL, sends `auth.ok` with `user_id` and `token`.
   - On failure: sends `auth.error`.
6. **Client stores the token** in `localStorage` as `cc_token` and `user_id` as `cc_user_id`.
7. **On reconnect**, the stored token is included in the WebSocket URL query string, enabling automatic re-authentication.

### Alternative REST Auth

`POST /api/auth` accepts `{ "password": "..." }` and returns `{ "token": "...", "user_id": "..." }`. This can be used by non-WebSocket clients.

### Token Properties

- **Format**: 32 hex characters (128 bits of randomness)
- **TTL**: 86400 seconds (24 hours), set at creation time in Redis
- **Storage**: Redis key managed by `RedisStore::setWebToken` / `hasWebToken` / `deleteWebToken`
- **Revocation**: `WebAuthManager::revokeToken` deletes from Redis immediately

### User Identity

The `user_id` is a static config value (`mcp.web.user_id`, defaults to `"web_user"`). All web clients share the same user identity -- this is a single-user system.

---

## WebSocket Protocol

All messages are JSON objects with a `type` field. Request messages may include an `id` field (auto-incrementing integer); the response will echo back the same `id`, enabling the client's `request()` method to match responses to requests via promises.

### Connection Lifecycle

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| S -> C | `auth.ok` | `user_id`, `token?` | Authentication successful |
| S -> C | `auth.required` | -- | Token missing or invalid, login required |
| S -> C | `auth.error` | `error` | Password authentication failed |
| C -> S | `auth` | `password` | Authenticate with password |
| S -> C | `ping` | `timestamp` | Server heartbeat (every 30s) |
| C -> S | `pong` | -- | Client heartbeat response |
| S -> C | `error` | `error`, `id?` | Generic error response |

### Chat Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `chat.send` | `prompt`, `conversation_id?`, `parent_task_id?`, `template?`, `agent_id?`, `images?` | Send a chat message. `images` is an array of `{data: base64, media_type: string}`. |
| S -> C | `chat.ack` | `task_id`, `conversation_id`, `agent_type`, `project_name`, `agent?` | Server acknowledges the chat request and returns routing info. The `agent` field (with `id`, `slug`, `name`, `color`, `icon`) is included in API mode. |
| S -> C | `chat.progress` | `task_id`, `elapsed`, `stderr_lines`, `timestamp` | Periodic progress update while Claude CLI is running |
| S -> C | `chat.result` | `task_id`, `conversation_id`, `result`, `claude_session_id`, `cost_usd`, `duration`, `images` | Final response from Claude |
| S -> C | `chat.error` | `task_id`, `error` | Chat processing failed |

### Conversations Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `conversations.list` | `project_id?`, `limit?`, `show_archived?`, `conv_type?` | List conversations (default limit 30, max 100). Filters out archived unless `show_archived` is true. |
| S -> C | `conversations.list` | `id`, `conversations` | Response with conversation array |
| C -> S | `conversations.get` | `conversation_id` | Get a single conversation with its turns |
| S -> C | `conversations.detail` | `id`, `conversation`, `turns` | Conversation metadata plus all turns |
| C -> S | `conversations.update` | `conversation_id`, `title?` | Update conversation fields (currently title only) |
| S -> C | `conversations.updated` | `id`, `conversation_id` | Confirmation of update |
| C -> S | `conversations.complete` | `conversation_id` | Mark conversation as completed |
| S -> C | `conversations.completed` | `id`, `conversation_id` | Confirmation |
| C -> S | `conversations.archive` | `conversation_id` | Archive a conversation (sets state to completed) |
| S -> C | `conversations.archived` | `id`, `conversation_id` | Confirmation |
| C -> S | `conversations.delete` | `conversation_id` | Permanently delete a conversation |
| S -> C | `conversations.deleted` | `id`, `conversation_id` | Confirmation |
| C -> S | `conversations.detail` | `conversation_id` | Alias for `conversations.get` |

### Tasks Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `tasks.list` | `state?`, `limit?` | List tasks (default limit 50, max 100). Filters to `source=web` tasks only. |
| S -> C | `tasks.list` | `id`, `tasks` | Array of stripped task objects (prompt truncated to 200 chars) |
| C -> S | `tasks.get` | `task_id` | Get full task details |
| S -> C | `tasks.detail` | `id`, `task` | Full task object including result, error, timestamps |
| C -> S | `tasks.delete` | `task_id` | Delete a completed/failed task (cannot delete running/pending) |
| S -> C | `tasks.deleted` | `id`, `task_id` | Confirmation |
| S -> C | `task.state_changed` | `task_id`, `state`, `conversation_id`, `prompt_preview`, `cost_usd`, `timestamp`, `result_preview?`, `error?` | Broadcast when a background task changes state (from TaskNotifier) |
| S -> C | `task.progress` | `task_id`, `elapsed`, `stderr_lines`, `timestamp` | Broadcast progress for background tasks (from TaskNotifier) |

### Projects Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `projects.list` | -- | List all project workspaces |
| S -> C | `projects.list` | `id`, `projects` | Array of project objects with `item_counts` |
| C -> S | `projects.get` | `project_id` | Get a single project with item and epic counts |
| S -> C | `projects.detail` | `id`, `project` | Full project object |
| C -> S | `projects.create` | `name`, `description?`, `cwd?` | Create a new project (rejects duplicates) |
| S -> C | `projects.created` | `id`, `project_id`, `name` | Confirmation |
| C -> S | `projects.update` | `project_id`, `name?`, `description?`, `cwd?` | Update project fields |
| S -> C | `projects.updated` | `id`, `project_id` | Confirmation |
| C -> S | `projects.delete` | `project_id` | Delete project and all its epics/items (cannot delete "General") |
| S -> C | `projects.deleted` | `id`, `project_id` | Confirmation |

### Epics Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `epics.list` | `project_id` | List epics for a project (auto-creates Backlog epic) |
| S -> C | `epics.list` | `id`, `epics` | Array of epics with `item_counts` and `progress` percentage |
| C -> S | `epics.create` | `project_id`, `title`, `description?` | Create a new epic |
| S -> C | `epics.created` | `id`, `epic_id`, `project_id` | Confirmation |
| C -> S | `epics.update` | `epic_id`, `title?`, `description?`, `state?` | Update epic fields or transition state |
| S -> C | `epics.updated` | `id`, `epic_id`, `epic` | Confirmation with full updated epic |
| C -> S | `epics.reorder` | `project_id`, `epic_ids` | Set epic sort order (array of epic IDs in desired order) |
| S -> C | `epics.reordered` | `id`, `project_id` | Confirmation |
| C -> S | `epics.delete` | `epic_id` | Delete an epic |
| S -> C | `epics.deleted` | `id`, `epic_id`, `project_id` | Confirmation |

### Items (Work Items) Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `items.list` | `project_id?`, `epic_id?`, `state?` | List items by project or epic |
| S -> C | `items.list` | `id`, `items` | Array of item objects |
| C -> S | `items.create` | `project_id`, `title`, `epic_id?`, `description?`, `priority?`, `conversation_id?` | Create a work item (priority: low/normal/high/urgent) |
| S -> C | `items.created` | `id`, `item_id`, `item` | Confirmation with full item |
| C -> S | `items.update` | `item_id`, `title?`, `description?`, `priority?`, `state?` | Update item fields or transition state |
| S -> C | `items.updated` | `id`, `item_id`, `item` | Confirmation with full updated item |
| C -> S | `items.move` | `item_id`, `epic_id` | Move item to a different epic |
| S -> C | `items.moved` | `id`, `item_id`, `item` | Confirmation |
| C -> S | `items.reorder` | `epic_id`, `item_ids` | Set item sort order within an epic |
| S -> C | `items.reordered` | `id`, `epic_id` | Confirmation |
| C -> S | `items.delete` | `item_id` | Delete an item |
| S -> C | `items.deleted` | `id`, `item_id`, `project_id` | Confirmation |
| C -> S | `items.notes` | `item_id` | Get notes for an item |
| S -> C | `items.notes` | `id`, `item_id`, `notes` | Array of notes |
| C -> S | `items.addNote` | `item_id`, `content` | Add a note to an item |
| S -> C | `items.noteAdded` | `id`, `item_id` | Confirmation |
| C -> S | `items.assign` | `item_id`, `assignee` | Assign an item to a user/agent |
| S -> C | `items.assigned` | `id`, `item_id`, `assignee`, `item` | Confirmation |

### Memory Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `memory.list` | `project_id?` | List all memories (general + project-scoped) |
| S -> C | `memory.list` | `id`, `facts`, `memories`, `project_memories`, `count`, `project_id` | Memory data |
| C -> S | `memory.get` | `memory_id` | Get a single memory entry |
| S -> C | `memory.detail` | `id`, `memory` | Full memory object |
| C -> S | `memory.create` | `content`, `category`, `importance?`, `memory_type?`, `agent_scope?`, `project_id?` | Create a memory entry. `memory_type`: "core" (always included) or "project" (relevance-ranked). `agent_scope`: "*" for all agents or a specific agent ID. |
| S -> C | `memory.created` | `id`, `memory_id` | Confirmation |
| C -> S | `memory.update` | `memory_id`, `content?`, `importance?`, `category?`, `memory_type?`, `agent_scope?`, `project_id?` | Update a memory entry |
| S -> C | `memory.updated` | `id`, `memory_id` | Confirmation |
| C -> S | `memory.delete` | `memory_id` | Delete a memory entry |
| S -> C | `memory.deleted` | `id`, `memory_id` | Confirmation |
| C -> S | `memory.search` | `query`, `project_id?`, `limit?`, `search_type?` | Semantic search across memories (requires VOYAGE_API_KEY) |
| S -> C | `memory.search` | `id`, `query`, `results` | Search results |
| C -> S | `memory.analytics` | -- | Get memory analytics overview |
| S -> C | `memory.analytics` | `id`, `...overview` | Analytics data |

### Agents Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `agents.list` | `project_id?` | List all agents (optionally filtered by project) |
| S -> C | `agents.list` | `id`, `agents` | Array of agent objects |
| C -> S | `agents.get` | `agent_id` | Get a single agent |
| S -> C | `agents.detail` | `id`, `agent` | Full agent object |
| C -> S | `agents.create` | `slug`, `name`, `description?`, `system_prompt?`, `model?`, `tool_access?`, `project_id?`, `memory_scope?`, `is_default?`, `color?`, `icon?` | Create an agent |
| S -> C | `agents.created` | `id`, `agent` | Confirmation with full agent |
| C -> S | `agents.update` | `agent_id`, plus any fields from create | Update agent fields |
| S -> C | `agents.updated` | `id`, `agent` | Confirmation with full agent |
| C -> S | `agents.delete` | `agent_id` | Delete a non-system agent |
| S -> C | `agents.deleted` | `id`, `agent_id` | Confirmation |
| C -> S | `agents.seed` | -- | Seed default agents and backfill conversation agents |
| S -> C | `agents.seeded` | `id` | Confirmation |

### Rooms (Channels) Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `channels.list` | -- | List all channels |
| S -> C | `channels.list` | `id`, `channels` | Array of channel objects |
| C -> S | `channels.create` | `name`, `description?` | Create a channel |
| S -> C | `channels.created` | `id`, `channel` | Confirmation with full channel |
| C -> S | `channels.detail` | `channel_id` | Get channel with messages and assigned agents |
| S -> C | `channels.detail` | `id`, `channel`, `messages`, `agents` | Channel data, message history, and room agents |
| C -> S | `channels.send` | `channel_id`, `content` | Send a message to a channel. If content contains `@slug`, the matching agent is invoked. `@claude` invokes the room's default agent. |
| S -> C | `channels.send` | `id`, `message` | Confirmation to sender |
| S -> C | `channels.message` | `channel_id`, `message` | Broadcast to all other connected clients (including agent replies) |
| C -> S | `channels.delete` | `channel_id` | Delete a channel |
| S -> C | `channels.deleted` | `id`, `channel_id` | Confirmation |
| C -> S | `rooms.add_agent` | `room_id` / `channel_id`, `agent_id`, `is_default?` | Add an agent to a room |
| S -> C | `rooms.agent_added` | `id`, `room_id`, `agent_id` | Confirmation |
| C -> S | `rooms.remove_agent` | `room_id` / `channel_id`, `agent_id` | Remove an agent from a room |
| S -> C | `rooms.agent_removed` | `id`, `room_id`, `agent_id` | Confirmation |
| C -> S | `rooms.set_default` | `room_id` / `channel_id`, `agent_id` | Set the default agent for a room |
| S -> C | `rooms.default_set` | `id`, `room_id`, `agent_id` | Confirmation |

### Sessions & Scheduler Domain

| Direction | Type | Fields | Description |
|-----------|------|--------|-------------|
| C -> S | `sessions.list` | -- | List active Claude CLI sessions |
| S -> C | `sessions.list` | `id`, `sessions` | Array of session objects |
| C -> S | `nightly.status` | -- | Get nightly job status and history |
| S -> C | `nightly.status` | `id`, `last_run`, `last_run_stats`, `next_run`, `lock_held`, `history` | Nightly run info |
| C -> S | `scheduler.list` | -- | List scheduled jobs |
| S -> C | `scheduler.list` | `id`, `jobs` | Array of job objects |
| C -> S | `scheduler.create` | job config fields | Create a scheduled job |
| S -> C | `scheduler.created` | `id`, `job_id` | Confirmation |
| C -> S | `scheduler.toggle` | `job_id`, `enabled` | Enable/disable a scheduled job |
| S -> C | `scheduler.toggled` | `id`, `job_id`, `enabled`, `success` | Confirmation |
| C -> S | `scheduler.delete` | `job_id` | Delete a scheduled job |
| S -> C | `scheduler.deleted` | `id`, `job_id` | Confirmation |

---

## Chat Flow: User Message to Claude Response

There are two execution paths, selected by the `mcp.chat.enabled` config flag.

### Path 1: Anthropic API Mode (`mcp.chat.api_key` configured)

This path calls the Anthropic Messages API directly from the Swoole process, enabling tool use loops.

1. **Client sends** `chat.send` with `prompt`, optional `conversation_id`, `agent_id`, and `images`.
2. **Server resolves agent**: uses `agent_id` if provided, falls back to the conversation's agent, then the system default agent.
3. **Server resolves conversation**: reuses `conversation_id` if provided, otherwise creates a new conversation in Postgres via `ConversationManager`.
4. **Server sends `chat.ack`** immediately with `task_id`, `conversation_id`, `agent_type`, `project_name`, and `agent` metadata.
5. **Server builds context**:
   - Builds a system prompt via `AgentPromptBuilder::build` (includes agent personality, memory, project context).
   - Retrieves chat history from `ChatConversationStore`.
   - Injects recent task completions as context for new conversations.
   - Sanitizes history to fix tool_use/tool_result mismatches.
6. **Server calls Anthropic API** via `AnthropicClient::sendMessage`, which handles multi-turn tool_use loops internally (the server provides `ToolDefinitions` and `ChatToolHandler`).
7. **Server stores results**:
   - Replaces chat history in `ChatConversationStore` with the full updated message exchange.
   - Records turns in `ConversationManager`.
8. **Server sends `chat.result`** with `result`, `cost_usd`, `duration`.
9. **Async post-processing** (in separate coroutines):
   - Memory extraction: uses Claude Haiku to extract facts, preferences, and work items from the exchange.
   - History compaction: if conversation exceeds threshold, summarizes older messages using the API.

### Path 2: Claude CLI Mode (default)

This path dispatches work to an external `task-worker.php` process via a supervisor queue.

1. **Client sends** `chat.send`.
2. **Server resolves agent and conversation** (same as API mode).
3. **For new conversations**: `autoDispatchTask()` creates a task in `TaskManager` with `dispatch_mode: supervisor`, immediately sends `chat.ack` and a `chat.result` containing a "task dispatched" acknowledgment.
4. **For continued conversations** (has `parent_task_id`): `continueChat()` calls `ProcessManager::continueTaskWithCallbacks`, which runs Claude CLI via `proc_open` in the Swoole process with streaming callbacks:
   - `onStderrChunk`: sends `chat.progress` with elapsed time and stderr line count.
   - `onComplete`: sends `chat.result` or `chat.error`.
5. **Background task completion** (from supervisor worker): `TaskNotifier::notifyStateChange` broadcasts `task.state_changed` to all connected clients. `TaskNotifier::notifyTaskResult` sends a `chat.result` message.

### Memory Extraction (Both Paths)

After each exchange, `extractMemoryAsync()` spawns a coroutine that:

1. Creates a Haiku task with the conversation context and an extraction prompt template.
2. Polls for completion (up to 30 seconds).
3. Parses the JSON response to extract:
   - **Conversation summary**: logged via `MemoryManager::logConversation`.
   - **Memories**: stored as preference, project, fact, or context entries scoped to the user and project.
   - **Work items**: auto-created in the project (deduplicated by title).

---

## Frontend Architecture

### Stack

- **React 18** with TypeScript
- **Wouter** for client-side routing (lightweight alternative to React Router)
- **TanStack Table** for data tables with sorting and search
- **Tailwind CSS** with a dark theme
- **Lucide React** for icons
- **Sonner** for toast notifications
- **date-fns** for date formatting
- **Vite** as the build tool (output to `public/` for Swoole to serve)

### Core Component: `WebSocketProvider`

The entire app is wrapped in `<WebSocketProvider>`, which provides the `useWs()` hook to all components. This hook exposes:

| Method | Signature | Description |
|--------|-----------|-------------|
| `status` | `ConnectionStatus` | One of `"disconnected"`, `"connecting"`, `"connected"`, `"authenticated"` |
| `userId` | `string \| null` | The authenticated user ID |
| `send` | `(msg: WsMessage) => void` | Fire-and-forget message send |
| `request` | `(msg: WsMessage, timeoutMs?) => Promise<WsMessage>` | Send a message and await a response matched by `id` (default 30s timeout) |
| `subscribe` | `(type: string, handler) => () => void` | Subscribe to messages of a given type. Returns an unsubscribe function. Supports `"*"` for wildcard. |
| `authenticate` | `(password: string) => void` | Send auth message |

### Connection Management

- **Auto-connect on mount**: `WebSocketProvider` opens a WebSocket immediately on mount.
- **Auto-reconnect with backoff**: On close, reconnects after a delay that grows from 1s to 15s (factor 1.5x).
- **Token reuse**: The stored `cc_token` from `localStorage` is included in the WebSocket URL on every connection attempt, enabling seamless re-auth.
- **Pending request cleanup**: All in-flight `request()` promises are rejected when the socket closes.

### Request/Response Matching

The `request()` method assigns an auto-incrementing `id` to outgoing messages. When the server echoes back a message with the same `id`, the matching promise is resolved. If no response arrives within `timeoutMs` (default 30000ms), the promise is rejected with a timeout error.

### Routing

Defined in `App.tsx` using Wouter's `<Switch>` and `<Route>`:

| Path | Component | Description |
|------|-----------|-------------|
| `/` | Redirect | Redirects to `/conversations` |
| `/conversations` | `Conversations` | Conversation list page |
| `/conversations/:id` | `ConversationDetail` | Chat interface (also handles `/conversations/new`) |
| `/channels` | `Channels` | Channel (room) list |
| `/channels/:id` | `ChannelDetail` | Channel chat view |
| `/projects` | `Projects` | Project list with cards |
| `/projects/:id` | `ProjectKanban` | Kanban board for a project |
| `/tasks` | `Tasks` | Background task list |
| `/agents` | `Agents` | Agent list |
| `/agents/:id` | `AgentDetail` | Agent configuration (also handles `/agents/new`) |
| `/memory` | `Memory` | Memory list with filters |
| `/memory/:id` | `MemoryDetail` | Single memory entry |
| `/skills` | `Skills` | Scheduler/skills management |
| `*` | `NotFound` | 404 page |

The `AuthenticatedRouter` component wraps all routes. If `status !== "authenticated"`, it renders `<LoginOverlay />` instead.

---

## Frontend Pages

### Layout (`components/Layout.tsx`)

The shared layout provides:

- **Desktop sidebar** (always visible at `md+` breakpoints) with navigation links: Conversations, Rooms, Projects, Tasks, Memory, Scheduler, Agents.
- **Mobile sidebar** (slide-over drawer triggered by hamburger menu).
- **Mobile bottom tab bar** showing the first 4 nav items plus a "More" button.
- **Connection status indicator** in the sidebar footer showing the user avatar, user ID, and connection state (green Wifi icon when authenticated, red WifiOff otherwise).
- **Notification bell** in the sidebar header.

### Conversations List (`pages/conversations/index.tsx`)

Displays all conversations in a sortable, searchable `DataTable` with columns: Title, Type, Project, Messages, State, Updated, Actions (archive/delete).

Features:
- **Agent selector**: Dropdown to pick which agent to use for new conversations.
- **Archive toggle**: Show/hide archived conversations.
- **New Chat button**: Creates a new conversation with the selected agent, navigating to `/conversations/new?agent=<id>`.
- **Real-time updates**: Subscribes to `conversations.archived` and `conversations.deleted` to auto-refresh the list.

### Conversation Detail (`pages/conversations/[id].tsx`)

Full chat interface with:

- **Message display**: User messages (right-aligned, primary color) and assistant messages (left-aligned, card style with markdown rendering).
- **Image upload**: Supports attaching images (max 5MB each, JPEG/PNG/GIF/WEBP) via the image button. Images are base64-encoded and sent with the message.
- **Streaming indicators**: Shows a "Thinking..." spinner with elapsed time during processing.
- **Client-side timeout**: After 5 minutes with no response, displays a timeout message and resets loading state.
- **Polling fallback**: Every 5 seconds, polls the server for new messages via `conversations.get`. If the server has more messages than the client, it merges them (catches results that the WebSocket may have missed).
- **Real-time subscriptions**: Listens for `chat.ack`, `chat.progress`, `chat.result`, `chat.error`, and `task.state_changed`.
- **Title editing**: Inline editing of conversation title via the info dialog.
- **Info dialog**: Shows conversation metadata (ID, title, type, agent, project, state, source, creation date).

### Projects List (`pages/projects/index.tsx`)

Displays projects as a responsive card grid. Each card shows:
- Project name and description
- Total item count (from `item_counts`)
- Last updated date

Features:
- **Create dialog**: Name + description form.
- **Real-time updates**: Subscribes to `projects.created`, `projects.updated`, `projects.deleted`.

### Agents List (`pages/agents/index.tsx`)

Sortable data table of agents with columns: Agent (avatar + name + description), Slug, Type badges (default/system), Model, Actions (delete).

Features:
- **New Agent button**: Navigates to `/agents/new`.
- **Delete**: System agents cannot be deleted.
- **Row click**: Navigates to agent detail/edit page.

### Memory Page (`pages/memory/index.tsx`)

Data table of memory entries with columns: Type (Core/Project badge), Category, Content, Agent scope, Priority, Project, Created, Actions (delete).

Features:
- **Type filter**: Toggle between All, Core, and Project memory types.
- **Create dialog**: Form with type selector (Core vs Project), category, importance, content, agent scope, and project scope.
- **Real-time updates**: Subscribes to `memory.created`, `memory.updated`, `memory.deleted`.

### Additional Pages

- **Channels** (`pages/channels/`): IRC-style rooms with @mention-triggered agent responses.
- **Tasks** (`pages/tasks/`): Background task monitoring.
- **Skills/Scheduler** (`pages/skills/`): Manage scheduled jobs.
- **Agent Detail** (`pages/agents/[id].tsx`): Full agent configuration editor.
- **Memory Detail** (`pages/memory/[id].tsx`): View and edit a single memory entry.
- **Project Kanban** (`pages/projects/[id].tsx`): Kanban board with epics as swim lanes and items as cards.

---

## Real-Time Update Patterns

The frontend uses three complementary strategies to keep the UI current:

### 1. WebSocket Subscriptions (Primary)

Pages subscribe to relevant message types via `useWs().subscribe()` and refresh data when events arrive.

Examples:
- Conversations page subscribes to `conversations.archived` and `conversations.deleted`.
- Projects page subscribes to `projects.created`, `projects.updated`, `projects.deleted`.
- Memory page subscribes to `memory.created`, `memory.updated`, `memory.deleted`.
- Conversation detail subscribes to `chat.ack`, `chat.progress`, `chat.result`, `chat.error`, `task.state_changed`.

The subscription pattern is consistent: the handler calls the `load*` function to re-fetch the full list from the server.

### 2. Polling Fallback

The conversation detail page polls every 5 seconds via `conversations.get`. If the server reports more messages than the client has locally, it replaces the local state. This catches background task results that may arrive while the WebSocket was reconnecting.

### 3. Optimistic Updates

The chat interface adds user messages to the local state immediately upon send (before server confirmation). The message appears instantly with a locally generated ID. The server-side message is reconciled on the next poll or via the `chat.result` subscription.

### 4. Server-Push Broadcasts

The `TaskNotifier` class broadcasts `task.state_changed` and `chat.result` messages to all connected clients (optionally filtered by `user_id`) when background tasks complete. This is the mechanism that delivers results for supervisor-dispatched tasks, which may complete minutes after the original request.

---

## Key Configuration Options

| Config Key | Default | Description |
|------------|---------|-------------|
| `mcp.web.auth_password` | `""` | Password for web authentication. Empty string disables auth (all passwords rejected). |
| `mcp.web.user_id` | `"web_user"` | Static user ID assigned to all web clients |
| `mcp.chat.enabled` | `false` | When true, uses Anthropic API directly instead of Claude CLI |
| `mcp.chat.api_key` | `""` | Anthropic API key for API mode |
| `mcp.nightly.run_hour` | `2` | Hour (0-23) for nightly maintenance job |
| `mcp.nightly.run_minute` | `0` | Minute for nightly maintenance job |
| `CLAUDE_PROCESS_TIMEOUT` | `0` | Process timeout for Claude CLI (0 = no timeout) |

### WebSocket Internals

| Parameter | Value | Description |
|-----------|-------|-------------|
| Ping interval | 30 seconds | Time between server heartbeat pings |
| Max missed pongs | 3 | Consecutive missed pongs before disconnect |
| Token TTL | 86400 seconds (24h) | Redis expiration for auth tokens |
| Client reconnect backoff | 1s to 15s | Exponential backoff with 1.5x growth factor |
| Client request timeout | 30 seconds | Default timeout for `request()` promise resolution |
| Client-side chat timeout | 5 minutes | Hard timeout on the frontend for chat responses |
| Conversation poll interval | 5 seconds | Polling frequency for conversation detail page |
| Max image size | 5 MB | Client-side limit per image attachment |
| Allowed image types | JPEG, PNG, GIF, WEBP | Server-validated media types |
