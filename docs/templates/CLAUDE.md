# Forge Integration

This project is connected to a Forge RLM server for centralized knowledge management.

> **[CONSTITUTION.md](CONSTITUTION.md)** — Immutable rules governing all agents and phases. Read it. Follow it. No exceptions.

## Workflow — MANDATORY

Every coding session MUST execute this workflow. These are not suggestions — they are enforced steps. Skipping them is a tracked process failure (BF-016).

### Before Writing Any Code

You MUST complete these steps before writing, editing, or generating any code:

1. **Bootstrap** — Call `bootstrap` to load project context, architecture decisions, open tickets, and recent lessons
2. **Claim Work** — Call `ticket-next` to pick up the highest-priority unassigned ticket, or `ticket-create` if no ticket exists for your task
3. **Recall** — Call `recall` with your agent name and pipeline phase to get targeted failures, prevention rules, and lessons
4. **Search Architecture Docs** — Call `search-architecture-docs` to review project-level architecture documentation for relevant patterns, conventions, and decisions

### Implement

5. **Build** — Implement the work using architecture decisions, project docs, and recalled knowledge

### During Work — Error Logging

When you encounter an error that requires a workaround, retry, or approach change:
- Call `ticket-comment` with `id` (ticket UUID), `actor` (your agent name), and structured `notes`
- Format: `"ERROR: {what failed}. WORKAROUND: {what worked}. ROOT CAUSE: {if known}."`
- Do NOT log trivial first-attempt failures (Pint formatting, typos, simple retries)
- DO log: dependency conflicts, API mismatches, package version issues, architectural pivots, test failures requiring code changes

### After Completing Work

You MUST complete these closing steps after implementation:

6. **Learn** — Call `learn` to record any new lessons discovered during implementation
7. **Report Failures** — Call `report-failure` for any issues encountered
8. **Advance Pipeline / Update Tickets:**
   - **Pipeline tickets** (has a `phase` field): Call `pipeline-advance` with `id`, `agent`, and `phase_result` containing `status`, `summary`, `files_created`, `test_count`, `errors`
   - **Non-pipeline tickets**: Call `ticket-update` to transition the ticket status
9. **Record Decisions** — Call `architecture-set` for any architectural decisions made

### What "Implement" Means

When a user says "build it", "implement", "proceed", or gives any broad directive, that means the **full workflow** — not just step 4. Bootstrap, claim a ticket, and recall first.

### Exceptions

The ONLY cases where pre-coding steps may be skipped:
- **Explicit user override** — the user specifically says to skip the workflow (e.g., "just make this change, no ticket needed")
- **Trivial single-line fix** — a typo fix, config tweak, or one-line change with no associated ticket

## Test Commenting Policy

Test code is the ONE exception to the "minimal comments" rule. All test files MUST be fully commented:
- Class-level docblock explaining what the test suite covers
- Comment block before each test method explaining what is being tested, why, and expected behavior
- Inline comments for non-obvious arrange/act/assert steps

Tests are living documentation. Agents and developers must understand test intent at a glance.

## Available MCP Tools

### Context
- `bootstrap` — Project context refresh (architecture decisions, open tickets, recent lessons)
- `bootstrap-project` — Get CLAUDE.md + slash command file map for new project setup
- `recall` — Primary knowledge retrieval for agent/phase
- `search-knowledge` — Full-text cross-entity search

### Knowledge
- `learn` — Record a new lesson
- `report-failure` — Report a failure
- `save-generation-trace` — Save generation trace data
- `list-failures` / `list-lessons` / `list-prevention-rules` / `list-distilled-lessons` / `list-patterns` — Filtered list queries

### Tickets
- `ticket-create` / `ticket-list` / `ticket-get` / `ticket-update` — CRUD operations
- `ticket-claim` / `ticket-next` / `ticket-comment` — Workflow operations

### Architecture
- `architecture-set` / `architecture-get` / `architecture-list` — Key-value decision store

### Documentation
- `search-docs` — Search framework docs (auto-scoped to your project's framework). Three modes: search by query, fetch a specific doc/section by slug, or list all docs
- `search-architecture-docs` — Search project-level architecture documentation in `docs/architecture/` and `.claude/architecture/`. Three modes: list all docs (no params), keyword search (query param), or fetch specific doc by slug (doc param with optional section). **Use this before making changes** to understand project-specific patterns, conventions, and architectural decisions.
- `search-patterns` — Search golden example code by component type (e.g. model, migration, filament-resource)
- `example-feedback` — Report which golden examples were used and whether they led to a successful generation (pass/fail/partial + score)
- `upload-golden-example` — Upload a successful code pattern as a project-scoped golden example candidate (auto-scoped, deduplicated)

### Pipeline
- `pipeline-status` — View pipeline ticket status and phase distribution
- `pipeline-advance` — **Primary tool for phase transitions** — advance a pipeline ticket to the next phase with structured `phase_result` data (status, summary, files_created, test_count, errors)
- `pipeline-context` — Get phase-matched golden examples for the current pipeline phase

### System
- `health` — System health check
- `stats` — Project-specific KPIs
- `promote-knowledge` — Promote lessons/failures to global visibility
