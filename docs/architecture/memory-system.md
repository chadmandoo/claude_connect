# Memory System & Nightly Consolidation

This document describes how Claude Connect stores, retrieves, ranks, and maintains agent memories, and how the nightly consolidation and cleanup pipelines keep the memory store healthy.

---

## Table of Contents

1. [Memory Types](#memory-types)
2. [Agent Scoping](#agent-scoping)
3. [Storage and Retrieval](#storage-and-retrieval)
4. [Hybrid Ranking (Vector + Keyword)](#hybrid-ranking-vector--keyword)
5. [Surfacing Tracking](#surfacing-tracking)
6. [Building Memory Context for Agent Prompts](#building-memory-context-for-agent-prompts)
7. [Nightly Consolidation Pipeline](#nightly-consolidation-pipeline)
8. [Cleanup Agent](#cleanup-agent)
9. [Embedding and Vector Search Integration](#embedding-and-vector-search-integration)
10. [Configuration Reference](#configuration-reference)

---

## Memory Types

Every memory entry has a `type` field that controls how it is treated during retrieval and maintenance.

### Core Memories (`type = 'core'`)

Core memories are **always included** in every prompt context, regardless of relevance scoring. They represent foundational knowledge that an agent needs at all times -- things like architectural decisions, critical user preferences, or essential project facts.

Core memories are **never auto-validated or auto-pruned** by the nightly pipeline. They can only be removed manually.

### Project Memories (`type = 'project'`)

Project memories are the standard type. They are **ranked by relevance** against the current prompt using hybrid vector + keyword scoring, and only the top-N most relevant are included in context. They are subject to all nightly maintenance phases: validation, deduplication, summarization, and staleness review.

### Scoping: General vs. Project-Scoped

Orthogonal to the `type` field, memories are scoped by `project_id`:

- **General memories** (`project_id = null`): User-wide knowledge not tied to any project. Stored in `<user_memory>` blocks.
- **Project-scoped memories** (`project_id = 'some_project'`): Knowledge specific to a project workspace. Stored in `<project_memory>` blocks.

Both general and project-scoped memories can be either `core` or `project` type.

---

## Agent Scoping

Memories can be restricted to specific agents via the `agent_scope` field:

| `agent_scope` value | Visibility |
|---|---|
| `'*'` | Visible to all agents |
| `''` (empty) | Visible to all agents |
| `'agent_abc'` | Visible only to `agent_abc` |
| `'agent_abc,agent_def'` | Visible to `agent_abc` and `agent_def` |

When `buildScopedContext()` or `buildSystemPromptContext()` is called with an `$agentId`, the `PostgresStore::getMemoriesForAgent()` method filters memories using SQL:

```
agent_scope = '' OR agent_scope = '*' OR agent_scope = :agentId OR agent_scope LIKE '%:agentId%'
```

This ensures each agent only sees memories that are globally shared or explicitly assigned to it.

**Reference:** `app/Storage/PostgresStore.php` method `getMemoriesForAgent()`, `app/Memory/MemoryManager.php` methods `buildSystemPromptContext()` and `buildScopedContext()`.

---

## Storage and Retrieval

### Where Memories Live

- **PostgreSQL** (`memories` table): The authoritative store for all memory entries. Fields include `id`, `user_id`, `category`, `content`, `importance`, `source`, `type`, `agent_scope`, `project_id`, `last_surfaced_at`, `created_at`, `updated_at`.
- **Redis (RediSearch)**: Vector embeddings for semantic search. Stored as Redis Hashes with prefix `cc:memvec:` and indexed by a RediSearch HNSW index (`idx:memory_vectors`).

### Memory Fields

| Field | Description |
|---|---|
| `id` | Unique identifier (e.g., `mem_a1b2c3d4`) |
| `category` | Classification: `fact`, `preference`, `project`, `context` |
| `content` | The actual memory text |
| `importance` | `normal` or `high` |
| `source` | Origin: `inline`, `nightly:merge`, `nightly:summarize`, `cleanup:consolidation` |
| `type` | `core` or `project` |
| `agent_scope` | Which agents can see this memory (see Agent Scoping) |
| `project_id` | `null` for general, or a project workspace ID |
| `last_surfaced_at` | Unix timestamp of when the memory was last included in a prompt |
| `created_at` | Unix timestamp of creation |

### Storing Memories

When a memory is stored via `MemoryManager::storeMemory()` or `storeProjectMemory()`:

1. A unique ID is generated (`mem_` + 8 hex chars).
2. The entry is persisted to PostgreSQL.
3. If the embedding service (Voyage AI) is available, a Swoole coroutine is spawned to asynchronously embed the memory content and upsert the vector into RediSearch. Failures here are non-blocking -- the nightly backfill phase will catch any missed embeddings.

### Legacy Key-Value Facts

A simpler `remember(key, value)` / `forget(key)` system exists for backward compatibility. These are stored as flat key-value pairs in PostgreSQL and always appear at the top of the `*Core Memories:*` section in prompts.

---

## Hybrid Ranking (Vector + Keyword)

Non-core memories are ranked by relevance before being included in prompt context. The system uses a **hybrid scoring** approach implemented in `MemoryManager::getRelevantMemories()`.

### Scoring Formula

```
combined_score = (0.7 * vector_score) + (0.3 * keyword_score)
```

### Vector Score (weight: 0.7)

Computed via Voyage AI semantic search through `EmbeddingService::semanticSearch()`:

1. The current user prompt is embedded using `VoyageClient::embedQuery()` (asymmetric query embedding).
2. A KNN search runs against the RediSearch HNSW index, filtered by `user_id` and optionally `project_id`.
3. Cosine distance is converted to similarity: `similarity = 1.0 - (distance / 2.0)`.
4. Top-20 results are returned.

### Keyword Score (weight: 0.3)

A word-intersection score computed locally:

1. Both the prompt and each memory's `content` + `category` are tokenized into lowercase words (3+ characters, stop words removed).
2. `keyword_score = |intersection| / |prompt_words|`
3. A **recency bias** is added: `recency_boost = max(0, 1 - (age_seconds / 30_days)) * 0.1`. This gives memories less than 30 days old a slight advantage, linearly decaying to zero at 30 days.
4. Keyword scores are normalized to [0, 1] range by dividing by the maximum keyword score in the batch.

### Fallback

If the embedding service is unavailable, the system falls back to keyword-only scoring. If the current prompt is empty, the most recent memories are returned (no scoring applied).

### Top-N Limit

The maximum number of relevant memories surfaced per context build is **30** (`MAX_RELEVANT_MEMORIES`).

**Reference:** `app/Memory/MemoryManager.php` methods `getRelevantMemories()`, `scoreAndRankMemories()`, `extractWords()`.

---

## Surfacing Tracking

Every time memories are included in an agent prompt, the system records this by updating the `last_surfaced_at` timestamp on each surfaced memory.

### How It Works

1. During `buildSystemPromptContext()` and `buildScopedContext()`, the IDs of all memories included in the prompt (both core and ranked) are collected into a `$surfacedIds` array.
2. `recordSurfaced()` spawns a Swoole coroutine that calls `PostgresStore::touchMemoriesSurfaced()`, which performs a bulk `UPDATE memories SET last_surfaced_at = :now WHERE id IN (...)`.
3. This runs asynchronously to avoid blocking prompt construction.

### Purpose

The `last_surfaced_at` field drives the **staleness detection** system (Phase 6 of nightly consolidation). Memories that have not been surfaced in 30+ days are flagged for review, since they may no longer be relevant to the user's active work.

**Reference:** `app/Memory/MemoryManager.php` method `recordSurfaced()`, `app/Storage/PostgresStore.php` methods `touchMemoriesSurfaced()` and `getStaleProjectMemories()`.

---

## Building Memory Context for Agent Prompts

Memory context is injected into Claude CLI calls via `--append-system-prompt`. Two methods handle this:

### `buildSystemPromptContext(userId, prompt, agentId)`

Builds the `<user_memory>` block for general (non-project) memories:

1. Load legacy key-value facts.
2. Load the last 10 conversation summaries from the memory log.
3. Load structured memories (filtered by agent scope if `$agentId` is provided), then filter out project-scoped entries.
4. Separate core memories (always included) from non-core memories.
5. Rank non-core memories using hybrid scoring against the current prompt.
6. Assemble the block:
   - `*Core Memories:*` -- legacy facts + core-type entries
   - `*Relevant Context:*` -- top-N ranked non-core entries
   - `*Recent Conversations:*` -- last 10 conversation summaries
7. Truncate to 8,000 characters max (`MAX_CONTEXT_CHARS`).
8. Record surfacing for all included memory IDs.

### `buildScopedContext(userId, prompt, projectId, agentId)`

Builds the full context: general `<user_memory>` + project-specific `<project_memory>`:

1. Call `buildSystemPromptContext()` for the general block.
2. If a `projectId` is provided, load project-scoped memories (filtered by agent scope).
3. Separate core project memories (always included) from non-core project memories.
4. Rank non-core project memories using hybrid scoring.
5. Assemble the `<project_memory>` block with core entries labeled `[core]`.
6. Combine both blocks, separated by a blank line.

**Reference:** `app/Memory/MemoryManager.php` methods `buildSystemPromptContext()` and `buildScopedContext()`.

---

## Nightly Consolidation Pipeline

The `NightlyConsolidationAgent` runs once per day (default: 2:00 AM Central Time) on worker 0. It executes six phases sequentially, each with budget guards to prevent runaway costs.

### Execution Model

- A coroutine loop checks every 60 seconds whether the current time matches the configured run window.
- A Redis distributed lock (`nightly:lock`, 2-hour TTL) prevents concurrent runs.
- Run statistics are persisted via `PostgresStore::addNightlyRunResult()`.
- Individual phases use Claude Haiku 4.5 for LLM-based analysis, called through the task management system.

### Phase 1: Backfill Missing Embeddings

**Goal:** Ensure every memory has a vector embedding in RediSearch.

**Process:**
1. Iterate all general memories and all project memories across every workspace.
2. For each memory, check `VectorStore::exists(id)`.
3. Collect memories missing embeddings.
4. Call `EmbeddingService::embedBatch()` to generate and upsert vectors in bulk.

This catches memories where the initial async embedding failed (network errors, Voyage API downtime, etc.).

### Phase 2: Project Expert Validation

**Goal:** Identify and remove stale or inaccurate memories using project context.

**Process:**
1. For each project workspace, load all non-core project memories.
2. Build expert context using `CodebaseContextBuilder`, which reads priority files from the project directory: `CLAUDE.md`, `README.md`, `composer.json`, `package.json`, `.env.example`, `ARCHITECTURE.md` (max 1,500 chars per file, 4,000 chars total).
3. Gather project metadata (epics, items, descriptions).
4. Send batches of memories to Claude Haiku along with the project context, using the `prompts/nightly/validate.md` template.
5. Haiku classifies each memory as `accurate`, `stale`, `inaccurate`, or `merge_with`.
6. Memories classified as `stale` or `inaccurate` with confidence > 0.7 are deleted.
7. General (non-project) memories are also validated, but without codebase context.

**Prompt template:** `prompts/nightly/validate.md`

### Phase 3: Similarity Deduplication

**Goal:** Merge semantically duplicate memories into single, richer entries.

**Process:**
1. For each memory (general and per-project, excluding core), find its 5 nearest neighbors in vector space using `VectorStore::findNeighbors()`.
2. If any neighbor's cosine similarity exceeds the configured threshold (default: 0.85), the pair is flagged as a duplicate cluster.
3. Each cluster is sent to Claude Haiku with the `prompts/nightly/merge.md` template, which produces a single merged memory preserving all unique information.
4. The merged memory is stored (with source `nightly:merge`) and both originals are deleted.
5. Each memory is merged at most once per run (one merge per memory per night).

**Prompt template:** `prompts/nightly/merge.md`

### Phase 4: Summarization

**Goal:** Compress large categories of project memories down to ~50% count while preserving all details.

**Process:**
1. For each project, count non-core memories. Skip if below the summarization threshold (default: 50).
2. Group memories by `category`.
3. For any category with 15+ memories, send the full batch to Claude Haiku with the `prompts/nightly/summarize.md` template.
4. Haiku produces consolidated summaries (targeting 50% reduction).
5. New summary entries are stored (with source `nightly:summarize`) and all originals in that category are deleted.

**Prompt template:** `prompts/nightly/summarize.md`

### Phase 5: Orphan Vector Cleanup

**Goal:** Remove vector embeddings in RediSearch that no longer have a corresponding memory in PostgreSQL.

**Process:**
1. Build a set of all known memory IDs from general and project memories.
2. Scan all keys in the vector store using `VectorStore::scanAllIds()` (Redis SCAN with prefix `cc:memvec:*`).
3. Delete any vector ID not present in the known-good set.

This phase does not use any LLM calls and has no budget cost.

### Phase 6: Staleness Review

**Goal:** Review memories that have not been surfaced in any agent prompt for 30+ days and decide whether to keep, archive, or delete them.

**Process:**
1. Query `PostgresStore::getStaleProjectMemories()` for project-type memories where `last_surfaced_at` is non-zero and older than the staleness threshold (default: 30 days).
2. Group stale memories into batches.
3. For each batch, resolve the owning agent's system prompt (from `agent_scope`) to provide context.
4. Send to Claude Haiku with the `prompts/nightly/staleness.md` template.
5. Haiku returns verdicts: `keep`, `archive`, or `delete` with a confidence score.
6. Memories marked `delete` with confidence above the threshold (default: 0.7) are removed.
7. Low-confidence verdicts are **escalated** (logged but not acted upon).
8. `keep` and `archive` verdicts leave the memory in place.

**Prompt template:** `prompts/nightly/staleness.md`

---

## Cleanup Agent

The `CleanupAgent` is a separate periodic agent that manages task and conversation lifecycle, running every 6 hours by default. It complements the nightly pipeline by handling tasks/conversations rather than memories directly.

### Execution

- Runs on worker 0 in a Swoole coroutine loop.
- Configurable interval (default: 21,600 seconds = 6 hours).
- Uses its own budget cap (default: $0.50/run).

### Phase 0: Stale Task Reaping

Finds tasks stuck in `running` or `pending` state past the stale timeout (default: 90 minutes). If the process PID is dead (verified via `posix_kill`), the task is transitioned to `FAILED`.

### Phase 1: Candidate Gathering

Collects old completed/failed tasks and old closed conversations that exceed their retention periods:
- Tasks: default retention of 7 days
- Conversations: default retention of 14 days

Active or still-running items are never gathered as candidates.

### Phase 2: Triage (LLM-Based Classification)

All candidates are sent to Claude Haiku in batches using the `prompts/cleanup/triage.md` template. Each item is classified as:

| Classification | Meaning | Retention |
|---|---|---|
| **core** | Architectural decisions, learned patterns, important bug fixes | Knowledge extracted, then kept until 2x retention |
| **operational** | Deployment notes, routine maintenance, status updates | Kept until 2x retention period, then pruned |
| **ephemeral** | Test tasks, trivial questions, one-off debugging | Pruned immediately |

The triage prompt instructs the model to classify based on **content value**, not age. If Haiku is unavailable, all items default to `operational` (safe fallback).

### Phase 3: Consolidation (Knowledge Extraction)

Core items flagged with `extract_memory = true` have their knowledge extracted:

1. Full task/conversation data is gathered.
2. Existing memories and available projects are provided as context to avoid duplicates.
3. Claude Haiku processes each batch using the `prompts/cleanup/consolidate.md` template and returns:
   - New memories to store (with category, content, importance, and target project).
   - IDs of existing memories that are duplicates of the new knowledge.
4. New memories are stored via `MemoryManager::storeMemory()` or `storeProjectMemory()`.
5. Identified duplicates are deleted.
6. Core and operational conversations are marked as `learned`.

### Phase 4: Pruning

- **Ephemeral items**: Deleted immediately (tasks from the task store, conversations via `ConversationManager::deleteConversation()`).
- **Operational items**: Deleted only if older than 2x the retention period.
- **Learned conversations**: Old conversations in the `learned` state past retention are pruned.

Active conversations and running/pending tasks are never pruned.

**Reference:** `app/Cleanup/CleanupAgent.php`, `app/Cleanup/CleanupConfig.php`, `prompts/cleanup/triage.md`, `prompts/cleanup/consolidate.md`.

---

## Embedding and Vector Search Integration

### Voyage AI Client

The `VoyageClient` calls the Voyage AI embeddings API (`https://api.voyageai.com/v1/embeddings`). Key behaviors:

- **Asymmetric embedding**: Documents use `input_type: 'document'`; queries use `input_type: 'query'`. This optimizes for retrieval accuracy.
- **Batch support**: Large sets of texts are chunked by a configurable batch size before calling the API.
- **Cost estimation**: Voyage 3.5 Lite costs ~$0.02 per 1M tokens.

### Vector Store (RediSearch)

The `VectorStore` uses Redis with the RediSearch module for HNSW-based approximate nearest neighbor search:

- **Index**: `idx:memory_vectors` on Hash keys with prefix `cc:memvec:`.
- **Schema**: Tags for `memory_id`, `user_id`, `project_id`, `category`, `importance`; TEXT for `content`; NUMERIC SORTABLE for `created_at`; VECTOR HNSW for the embedding.
- **Distance metric**: Cosine. Raw distance is converted to similarity via `1.0 - (distance / 2.0)`.
- **Search**: KNN queries with optional tag pre-filters (user, project, category).
- **Neighbor detection**: `findNeighbors()` retrieves a memory's own vector, then runs a KNN search, filtering out the self-match. Used by the deduplication phase.
- **Orphan scanning**: `scanAllIds()` uses Redis SCAN to iterate all vector keys for cleanup.

**Reference:** `app/Embedding/VoyageClient.php`, `app/Embedding/VectorStore.php`, `app/Embedding/EmbeddingService.php`.

---

## Configuration Reference

All settings live under the `mcp.*` config namespace (typically in `config/autoload/mcp.php` or environment variables).

### Nightly Consolidation (`mcp.nightly.*`)

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Enable/disable the nightly agent |
| `run_hour` | `2` | Hour to run (24h format, America/Chicago timezone) |
| `run_minute` | `0` | Minute to run |
| `max_budget_usd` | `1.00` | Maximum total Haiku spend per nightly run |
| `haiku_call_budget_usd` | `0.05` | Budget cap per individual Haiku call |
| `batch_size` | `20` | Number of memories per Haiku batch |
| `summarization_threshold` | `50` | Minimum project memories before summarization triggers |
| `similarity_threshold` | `0.85` | Cosine similarity above which two memories are considered duplicates |
| `staleness_threshold_days` | `30` | Days without surfacing before a memory is flagged as stale |
| `staleness_confidence_threshold` | `0.7` | Minimum Haiku confidence to act on a staleness verdict |

### Cleanup Agent (`mcp.cleanup.*`)

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Enable/disable the cleanup agent |
| `interval` | `21600` | Seconds between cleanup runs (6 hours) |
| `retention_days_tasks` | `7` | Days to retain completed/failed tasks |
| `retention_days_conversations` | `14` | Days to retain closed conversations |
| `batch_size` | `15` | Items per triage batch |
| `max_budget_usd` | `0.50` | Maximum Haiku spend per cleanup run |
| `haiku_call_budget_usd` | `0.05` | Budget cap per individual Haiku call |
| `max_items_per_run` | `200` | Maximum candidates to process per run |
| `stale_task_timeout` | `5400` | Seconds before a running task is considered stale (90 minutes) |

### Memory Manager Constants

| Constant | Value | Location |
|---|---|---|
| `MAX_CONTEXT_CHARS` | `8000` | Maximum characters in the `<user_memory>` block |
| `MAX_RELEVANT_MEMORIES` | `30` | Maximum non-core memories surfaced per prompt |

### Codebase Context Builder Constants

| Constant | Value | Description |
|---|---|---|
| `MAX_FILE_CHARS` | `1500` | Maximum characters read per priority file |
| `MAX_TOTAL_CHARS` | `4000` | Maximum total codebase context characters |
| Priority files | `CLAUDE.md`, `README.md`, `composer.json`, `package.json`, `.env.example`, `ARCHITECTURE.md` | Files read for project expert validation |
