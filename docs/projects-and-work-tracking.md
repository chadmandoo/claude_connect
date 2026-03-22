# Projects, Epics, Items, and Conversations

This document covers the work-tracking and conversation systems in Claude Connect: how projects are created and orchestrated, how epics and items organize work, how conversations track multi-turn interactions, and how the post-task pipeline ties everything together.

---

## Table of Contents

1. [Projects](#projects)
2. [Project Orchestrator](#project-orchestrator)
3. [Epics](#epics)
4. [Work Items](#work-items)
5. [Conversations](#conversations)
6. [Post-Task Pipeline](#post-task-pipeline)
7. [How Everything Relates](#how-everything-relates)
8. [Configuration Reference](#configuration-reference)

---

## Projects

**Source**: `app/Project/ProjectManager.php`, `app/Project/ProjectState.php`

Projects come in two flavors: **goal-driven projects** (autonomous multi-step execution) and **workspaces** (persistent containers for organizing conversations and work items).

### Project States

```
planning  -->  active  -->  completed
                 |   \
                 |    -->  cancelled
                 v
              paused  -->  active (resume)
                 |
                 v
              stalled -->  active (resume)

workspace  (permanent, no transitions)
```

| State | Description |
|-------|-------------|
| `planning` | Initial state for goal-driven projects. The orchestrator generates a step-by-step plan. |
| `active` | The project is executing steps autonomously. |
| `paused` | Execution paused -- either by a scheduled checkpoint or a safety limit. Can resume to `active`. |
| `stalled` | A step failed twice consecutively. Requires manual intervention to resume. |
| `completed` | All steps finished successfully. Terminal state. |
| `cancelled` | Manually cancelled. Terminal state. |
| `workspace` | A named container for conversations, epics, and items. Never transitions. |

### Creating a Goal-Driven Project

`ProjectManager::createProject(goal, userId, options)` creates a project in the `planning` state. The project stores:

- **goal**: The high-level objective (free-text description).
- **plan**: A JSON array of step descriptions (populated later by the orchestrator).
- **current_step / total_steps / completed_steps**: Step progress counters.
- **total_cost_usd**: Accumulated API cost across all steps.
- **max_iterations**: Maximum number of steps before auto-pause (default 20).
- **max_budget_usd**: Maximum total spend before auto-pause (default $10.00).
- **checkpoint_interval**: Pause for user review every N completed steps (default 5).
- **current_task_id**: The task ID of the currently executing step.
- **retry_count**: How many times the current step has been retried after failure.
- **cwd**: Working directory for Claude CLI execution.
- **waiting_for_reply**: Flag to pause orchestration while waiting for user input.

### Creating a Workspace

`ProjectManager::createWorkspace(name, description, userId, cwd)` creates a project in the permanent `workspace` state. Workspaces have no iteration limits, no budget caps, and no orchestration. They serve as organizational containers.

A **General** workspace is automatically created (via `ensureGeneralProject`) as the default home for conversations that are not associated with a specific project.

### State Transitions

All transitions go through `ProjectManager::transition()`, which validates the transition is allowed, records the timestamp, and adds a history entry. When a terminal state is reached (`completed` or `cancelled`), the active project is cleared from Redis.

### Project History

Every state transition is recorded as a history entry with `from`, `to`, `timestamp`, and an optional `reason`. Retrieved via `getProjectHistory()`.

---

## Project Orchestrator

**Source**: `app/Project/ProjectOrchestrator.php`

The orchestrator is a long-running Swoole coroutine loop that autonomously drives goal-driven projects from planning through completion. It polls on a configurable interval (default 5 seconds).

### Tick Lifecycle

Each tick follows this decision tree:

1. **No active project?** Return immediately.
2. **Project in `planning` state?** Generate or await the plan.
3. **Project not `active`?** Skip (paused, stalled, etc.).
4. **Waiting for user reply?** Skip.
5. **Current task still running?** Wait for it.
6. **Current task completed?** Handle step completion.
7. **Current task failed?** Handle step failure.
8. **Safety limits exceeded?** Pause the project.
9. **Checkpoint interval reached?** Pause for user review.
10. **All steps complete?** Transition to `completed`.
11. **Otherwise?** Execute the next step.

### Plan Generation

When a project is in `planning`, the orchestrator sends the goal to Claude Haiku with a prompt asking for 3-10 concrete, independently executable steps. The response is parsed as a JSON array.

- If parsing succeeds, the plan is stored and the project transitions to `active`.
- If the planning task fails or the response cannot be parsed, a single-step fallback plan is used (the original goal becomes the only step).

### Step Execution

Each step is executed as a separate Claude task. The prompt includes:
- The overall project goal
- The full plan with `[DONE]`, `[NOW]`, and `[TODO]` markers
- Summaries of all previously completed steps (up to 2000 chars each)
- The current step instruction

The step runs with a per-step budget (configurable, default $2.00) and respects the project's working directory.

### Step Completion

On success:
- The step result (instruction, summary, cost, timestamp) is stored.
- Progress counters are incremented.
- Cost is accumulated.
- Retry count is reset.
- **Every 3 completed steps**, the orchestrator re-evaluates the remaining plan by sending the completed summaries and remaining steps to Claude Haiku, which can revise the plan mid-execution. This runs in a separate coroutine to avoid blocking the main tick loop.

### Step Failure and Retry

- **First failure**: The current task ID is cleared, and the same step will be re-attempted on the next tick.
- **Second consecutive failure**: The project transitions to `stalled` with the error message recorded. Manual intervention is required to resume.

### Safety Limits

Before executing any step, the orchestrator checks:
- **Iteration limit**: `completed_steps >= max_iterations` triggers a pause.
- **Budget cap**: `total_cost_usd >= max_budget_usd` triggers a pause.

Both result in a transition to `paused` with a descriptive reason.

### Checkpointing

If `completed_steps` is a multiple of `checkpoint_interval` (and > 0), the project pauses with reason "Scheduled checkpoint". This ensures a human reviews progress periodically.

### Pause/Resume for User Replies

`pauseForReply(projectId)` sets the `waiting_for_reply` flag, causing the orchestrator to skip the project on each tick without changing the project state. `resumeFromReply(projectId)` clears the flag to resume execution.

---

## Epics

**Source**: `app/Epic/EpicManager.php`, `app/Epic/EpicState.php`

Epics are groups of related work items within a project. They provide a mid-level organizational layer between projects and items.

### Epic States

```
open  -->  in_progress  -->  completed
  |             |
  v             v
cancelled    cancelled
```

| State | Description |
|-------|-------------|
| `open` | No items started yet. |
| `in_progress` | At least one item is in progress or done (but not all finished). |
| `completed` | All items are done or cancelled. Terminal. |
| `cancelled` | Epic cancelled. Terminal. |

### The Backlog Epic

Every project has a special **Backlog** epic (created lazily via `ensureBacklogEpic`). It has these properties:
- Title is always "Backlog", description is "Ungrouped items".
- `is_backlog` flag is set to `'1'`.
- Sort order is fixed at 999999 (always last).
- Cannot be transitioned, renamed, or deleted.
- Items with no specified epic are automatically placed here.
- Creation uses a SETNX-style race condition guard to prevent duplicates.

### Automatic State Refresh

`refreshEpicState(epicId)` recalculates the epic's state based on its items:
- All items done/cancelled --> epic becomes `completed`.
- Any items in progress, blocked, or done (but not all finished) --> epic becomes `in_progress`.
- All items open --> epic stays/returns to `open`.

This is called automatically after every item state transition.

### Epic Deletion

When an epic is deleted, all its items are moved to the project's Backlog epic. The Backlog epic itself cannot be deleted.

### Sort Order

Epics have a `sort_order` field. New epics get `max_existing_sort_order + 1`. The Backlog epic is always at 999999.

---

## Work Items

**Source**: `app/Item/ItemManager.php`, `app/Item/ItemState.php`, `app/Item/ItemPriority.php`

Work items are the atomic units of tracked work. They live within an epic (defaulting to the Backlog epic) and belong to a project.

### Item States

```
open  -->  in_progress  -->  review  -->  done  -->  open (reopen)
  |             |              |
  |             v              v
  |          blocked  -->  in_progress
  |             |
  v             v
done         cancelled
  |
  v
cancelled
```

| State | Description |
|-------|-------------|
| `open` | Not yet started. |
| `in_progress` | Actively being worked on. |
| `review` | Work complete, awaiting review. |
| `blocked` | Cannot proceed due to a dependency or issue. |
| `done` | Completed. Can be reopened back to `open`. |
| `cancelled` | Cancelled. Can be reopened back to `open`. Only `cancelled` is considered truly terminal by `isTerminal()`. |

Full transition table:

| From | Allowed To |
|------|-----------|
| `open` | `in_progress`, `done`, `cancelled` |
| `in_progress` | `open`, `review`, `blocked`, `done`, `cancelled` |
| `review` | `done`, `in_progress`, `open` |
| `blocked` | `in_progress`, `done`, `cancelled` |
| `done` | `open` |
| `cancelled` | `open` |

### Item Priorities

Four priority levels: `low`, `normal` (default), `high`, `urgent`.

### Item Fields

- **title**: Short description.
- **description**: Detailed description.
- **priority**: One of the four priority levels.
- **epic_id**: The parent epic (defaults to Backlog).
- **project_id**: The parent project.
- **conversation_id**: The conversation that originated this item (if any).
- **sort_order**: Position within the epic.
- **assigned_to**: Assignee identifier.
- **completed_at**: Timestamp, set when transitioning to `done` or `cancelled`, cleared on reopen.

### Key Operations

- **Moving between epics**: `moveToEpic(itemId, newEpicId)` validates the target epic belongs to the same project, removes the item from the old epic, and adds it to the new one.
- **Conversation linking**: Items can be linked to conversations bidirectionally via `linkConversation`. `getLinkedConversations(itemId)` and `getLinkedItems(conversationId)` traverse these links.
- **Assignment**: Items can be assigned to and unassigned from users. `getAssignedItems(assignee)` scans all items globally.
- **Notes**: Items support attached notes with content and author, retrieved in reverse-chronological order.
- **Project-level counts**: `getProjectItemCounts(projectId)` returns a breakdown by state: `open`, `in_progress`, `review`, `blocked`, `done`, `cancelled`, `total`.

### Cascading State Updates

When an item transitions state, `EpicManager::refreshEpicState()` is called automatically on the parent epic to keep the epic's state consistent with its items.

---

## Conversations

**Source**: `app/Conversation/ConversationManager.php`, `app/Conversation/ConversationType.php`, `app/Conversation/ConversationState.php`

Conversations track multi-turn interactions between users and Claude. They are associated with a project and accumulate turns, cost, summaries, and extracted knowledge.

### Conversation Types

| Type | Purpose |
|------|---------|
| `brainstorm` | Open-ended ideation and exploration. |
| `planning` | Structured planning sessions. |
| `task` | Task execution (the default type). |
| `discussion` | General discussion. |
| `check_in` | Status updates and check-ins. |

The type influences how the post-task pipeline extracts information (different extraction prompts are loaded per type).

### Conversation States

| State | Description |
|-------|-------------|
| `active` | Conversation is ongoing. |
| `completed` | Conversation finished normally. |
| `abandoned` | Conversation was abandoned (no explicit completion). |
| `learned` | Memories and knowledge have been extracted and stored. |

### Conversation Fields

- **user_id**: The user who owns the conversation.
- **type**: One of the five conversation types.
- **state**: Current lifecycle state.
- **project_id**: Associated project (defaults to `'general'`).
- **source**: Where the conversation originated (e.g., `'web'`).
- **summary**: AI-generated summary of the conversation.
- **key_takeaways**: JSON array of key points extracted from the conversation.
- **total_cost_usd**: Accumulated API cost across all turns.
- **turn_count**: Number of turns in the conversation.
- **agent_id**: Optional agent identifier.

### Turns

Each turn records:
- **role**: `'user'` or `'assistant'`.
- **content**: The message content (truncated to 5000 characters).
- **task_id**: The Claude task that generated this turn (for assistant turns).
- **cost_usd**: API cost for this turn.
- **timestamp**: When the turn occurred.

Turns are added via `addTurn()`, which also increments the turn counter and accumulates cost.

### Summary and Takeaways

`updateSummary(id, summary, takeaways)` stores an AI-generated summary and key takeaways array. These are populated by the `ExtractConversationStage` in the post-task pipeline.

### Lifecycle

Conversations transition through:
1. Created as `active` via `createConversation()`.
2. Turns are added via `addTurn()`.
3. Completed via `completeConversation()` (sets state to `completed`).
4. Optionally marked as `learned` via `markLearned()` after memories have been extracted.

Conversations linked to a non-general project are indexed in the project's conversation list via `addConversationToProject`.

---

## Post-Task Pipeline

**Source**: `app/Pipeline/PostTaskPipeline.php`, `app/Pipeline/PipelineContext.php`, `app/Pipeline/PipelineStage.php`, `app/Pipeline/Stages/*`

The post-task pipeline runs after every completed task. It extracts memories, updates conversations, detects projects, and generates vector embeddings. The pipeline is invoked by the `AgentSupervisor` in a separate Swoole coroutine.

### Pipeline Architecture

The pipeline is a sequential stage runner. Each stage implements `PipelineStage`:

```php
interface PipelineStage {
    public function name(): string;
    public function shouldRun(PipelineContext $context): bool;
    public function execute(PipelineContext $context): array; // {success, error?}
}
```

**Key design decisions:**
- Stages never abort the pipeline. If a stage throws an exception or returns `success: false`, the pipeline logs the error and continues to the next stage.
- A `PipelineContext` carries the completed task data, user ID, template config, conversation ID, and conversation type. It also has a mutable `bag` array for inter-stage communication.
- The pipeline can be limited to a subset of stages by passing stage names, but by default all registered stages run.
- Execution timing is logged for every stage.

### Registered Stages (in order)

The pipeline is assembled in `config/autoload/dependencies.php` with stages registered in this order:

#### 1. `extract_memory`

**Source**: `app/Pipeline/Stages/ExtractMemoryStage.php`

**Runs when**: `userId` is non-empty.

**What it does**: Sends the task prompt (truncated to 1000 chars) and result (truncated to 2000 chars) to Claude Haiku, asking it to extract structured memory:
- A 2-3 sentence summary with topics.
- Categorized memories: `preference`, `project`, `fact`, or `context`, each with an importance level (`high`, `normal`, `low`).

**Processing**:
- The summary (with topic tags) is logged via `MemoryManager::logConversation()`.
- Each extracted memory is stored via `MemoryManager::storeMemory()` with the category, content, importance, and source `'extraction'`.
- Backward-compatible with an older flat `facts` format.

**Extraction task config**: Uses `claude-haiku-4-5-20251001`, single turn, $0.05 budget, up to 30 second wait.

#### 2. `extract_conversation`

**Source**: `app/Pipeline/Stages/ExtractConversationStage.php`

**Runs when**: `userId` is non-empty AND `conversationId` is non-empty.

**What it does**: Similar to `extract_memory` but conversation-aware. Uses a **type-specific extraction prompt** loaded via `PromptLoader::loadExtractionPrompt(conversationType)`, so brainstorm, planning, task, discussion, and check-in conversations each get tailored extraction.

**Processing**:
- Updates the conversation's summary and key takeaways.
- Logs the summary to the memory system.
- Stores extracted memories with type-aware routing:
  - For non-general projects, `project` and `context` category memories are stored as **project-scoped memories** via `storeProjectMemory()`.
  - Other categories are stored as global user memories.
- **Creates work items**: If the extraction yields `work_items` and the conversation belongs to a non-general project, items are automatically created in that project. Deduplication is performed by case-insensitive title matching against existing project items.

**Extraction task config**: Uses `claude-haiku-4-5-20251001`, single turn, $0.05 budget, up to 30 second wait.

#### 3. `project_detection`

**Source**: `app/Pipeline/Stages/ProjectDetectionStage.php`

**Runs when**: Auto-detection is enabled (`mcp.project.auto_detect`, default `true`), no project is currently active, and the task has both a prompt and a result.

**What it does**: Sends the task prompt (truncated to 500 chars) and result (truncated to 1000 chars) to Claude Haiku to evaluate whether the completed task is part of a larger project with significant remaining work.

**Processing**:
- If `is_project` is `true`, a new goal-driven project is created and set as the active project.
- Includes a race condition guard (re-checks for active project before creating).
- The new project inherits configuration from `mcp.project.*` config values.

**Extraction task config**: Uses `claude-haiku-4-5-20251001`, single turn, $0.05 budget, up to 30 second wait.

#### 4. `embed_conversation`

**Source**: `app/Pipeline/Stages/EmbedConversationStage.php`

**Runs when**: `conversationId` is non-empty AND the embedding service is available (Voyage API configured).

**What it does**: Takes the conversation's summary (populated by the `extract_conversation` stage) and generates a vector embedding, stored with the vector ID `conv_{conversationId}`. This enables semantic search over past conversations.

#### 5. `embed_task_result`

**Source**: `app/Pipeline/Stages/EmbedTaskResultStage.php`

**Runs when**: The embedding service is available.

**What it does**: Embeds the task result (truncated to 500 chars, minimum 100 chars) as a vector with ID `task_{taskId}`. Skips tasks with very short results. This enables semantic search over past task results.

---

## How Everything Relates

```
Project (workspace or goal-driven)
  |
  +-- Epics (organizational groups)
  |     |
  |     +-- Work Items (atomic tracked work)
  |           |
  |           +-- Notes
  |           +-- linked Conversations
  |
  +-- Conversations (multi-turn interactions)
        |
        +-- Turns (individual messages)
```

### Data Flow

1. **User sends a message** via WebSocket. A conversation is created (or continued) within a project.
2. **A task is executed** by Claude CLI. The result is delivered to the user.
3. **The post-task pipeline runs**, which:
   - Extracts memories from the exchange and stores them globally or per-project.
   - Extracts a conversation summary, key takeaways, and potentially new work items.
   - Evaluates whether the task warrants an autonomous multi-step project.
   - Generates vector embeddings for semantic retrieval.
4. **Work items created by the pipeline** land in the project's Backlog epic with the originating conversation linked.
5. **If a project is auto-detected**, the orchestrator takes over and autonomously executes a generated plan, step by step, with safety limits and periodic checkpoints.
6. **Epic states auto-update** as their items progress, providing a roll-up view of work status.

### Linking Mechanisms

- **Project <-> Conversation**: Conversations store a `project_id` and are indexed in the project's conversation list.
- **Project <-> Epic**: Epics store a `project_id` and are indexed in a sorted set per project.
- **Epic <-> Item**: Items store an `epic_id` and are indexed in a sorted set per epic. Items are also indexed in a set per project.
- **Item <-> Conversation**: Bidirectional links via `linkItemToConversation`. Items store the originating `conversation_id`, and a separate index supports lookup in both directions.
- **Project <-> Orchestrator**: Only one project can be active at a time (stored in Redis). The orchestrator polls for the active project each tick.

---

## Configuration Reference

All configuration lives under the `mcp.project.*` namespace.

| Key | Default | Description |
|-----|---------|-------------|
| `mcp.project.orchestrator_interval` | `5` | Seconds between orchestrator ticks. |
| `mcp.project.step_budget_usd` | `2.00` | Maximum API spend per individual step. |
| `mcp.project.max_iterations` | `20` | Maximum steps before auto-pause. |
| `mcp.project.max_budget_usd` | `10.00` | Maximum total project spend before auto-pause. |
| `mcp.project.checkpoint_interval` | `5` | Pause for user review every N steps. |
| `mcp.project.auto_detect` | `true` | Whether the pipeline auto-detects and creates projects from task completions. |
| `mcp.supervisor.enabled` | `false` | Whether the AgentSupervisor (which runs the pipeline) is active. |
| `mcp.supervisor.tick_interval` | `30` | Seconds between supervisor ticks. |
| `mcp.supervisor.stall_timeout` | `1800` | Seconds before a running task is considered stalled. |
| `mcp.supervisor.max_retries` | `1` | Maximum retry attempts for failed tasks. |

### Extraction Tasks

All extraction sub-tasks (memory, conversation, project detection) use:
- **Model**: `claude-haiku-4-5-20251001`
- **Max turns**: 1
- **Max budget**: $0.05
- **Timeout**: 30 seconds (polling with 1-second sleep)
