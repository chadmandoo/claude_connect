# Project Constitution

> Immutable rules that govern all agents, all phases, all sessions.
> No agent may override, skip, or reinterpret these rules under any circumstances.

---

## 0. Quality Above All Else

- **Best-practice code is the only acceptable output.** Every agent must produce the cleanest, most idiomatic, most maintainable solution — not the fastest or cheapest one.
- **Never cut corners to save time or tokens.** The human does not care how long a task takes or how many iterations it requires. Thoroughness is not a cost — it is the standard.
- **Workarounds are technical debt.** If the correct solution exists, use it. If a workaround is unavoidable, flag it explicitly, record it as a lesson, and document why the proper approach was blocked.
- **Design patterns and Hyperf/Swoole conventions are non-negotiable.** Follow established patterns (Dependency Injection, coroutine-safe design, Swoole Table for shared state) even when a shortcut would "work." Code that works but violates conventions is not done.
- **Prefer clarity over cleverness.** Readable, well-structured code that any developer can maintain beats compact or "elegant" code that requires explanation.

## 1. Package Boundary

- **`vendor/` is READ-ONLY.** No agent may create, modify, or delete any file under `vendor/`.
- All generated code targets: `app/`, `config/`, `prompts/`, `migrations/`, `frontend/src/`, `tests/`.

## 2. Pipeline by Default

- **All code changes go through a pipeline.** Any bug fix, feature, refactor, or infrastructure change — regardless of size — MUST use the appropriate pipeline (entity or work). There are no exceptions unless the human explicitly says to skip the pipeline.
- **"It's a small fix" is not an excuse.** Small fixes still get a work pipeline. The pipeline ensures design review, testing, validation, and knowledge recording happen. Skipping the pipeline means skipping quality gates.
- **Only the human can waive the pipeline.** If the human explicitly says "no pipeline", "just do it", "skip the pipeline", or similar — then and only then may an agent proceed without a pipeline. Agents may NOT self-classify work as "too small" for a pipeline.
- **PM decides the pipeline type.** Use the decision tree in PM's SKILL.md. Entity work = entity pipeline. Everything else = work pipeline. Quick fixes that the human explicitly waives = direct to agent.

## 3. Phase Gates

- **Every phase has an entry gate.** The previous phase must be PASS before the next phase begins.
- **Broad directives are NOT permission to skip gates.** "Build the whole thing" still means phase-by-phase.
- **Human checkpoints are mandatory** between entities in multi-entity pipelines.
- **NEVER have two pipeline documents active** for the same project simultaneously.
- **Deferred work is not done work.** Incomplete = BLOCKED, not PASS.

## 4. Agent Scope

Each agent has a defined boundary. Crossing it is a constitutional violation.

| Agent | Writes Code | Makes Design Decisions | Runs Tests | Validates Patterns | Modifies Vendor |
|-------|:-----------:|:---------------------:|:----------:|:-----------------:|:--------------:|
| PM | NO | NO | NO | NO | NO |
| Solutions | NO | YES | NO | NO | NO |
| Architect | YES | NO | YES | NO | NO |
| Designer | YES (UI only) | NO | NO | NO | NO |
| Verifier | NO | NO | YES (Dusk) | NO | NO |
| Tester | YES (tests) | NO | YES | NO | NO |
| Reviewer | NO | NO | NO | YES | NO |
| Docs | NO | NO | NO | NO | NO |

## 5. File Placement

| Component | Location |
|-----------|----------|
| Controllers | `app/Controller/` |
| State Machines | `app/StateMachine/` |
| CLI Process Mgmt | `app/Claude/` |
| Storage Layers | `app/Storage/` |
| WebSocket/Chat | `app/Web/` |
| Agent Routing | `app/Agent/` |
| Conversations | `app/Conversation/` |
| Projects/Epics | `app/Project/`, `app/Epic/` |
| Work Items | `app/Item/` |
| Memory System | `app/Memory/` |
| Pipeline Stages | `app/Pipeline/Stages/` |
| Commands | `app/Command/` |
| Event Listeners | `app/Listener/` |
| Prompt Templates | `prompts/` (custom: `prompts/custom/`) |
| Config | `config/autoload/` |
| Migrations | `migrations/` |
| Frontend | `frontend/src/` |
| Tests | `tests/Unit/` |

## 6. Testing Standards

- Every test verifies one thing. AAA pattern: Arrange, Act, Assert.
- Mock external services (Redis, Claude CLI). Use Mockery for test doubles.
- **NEVER mark PASS if tests didn't actually run.** Command errors = "Not Run", not PASS.
- Report actual test counts from runner output. Never estimate.
- **Test code MUST be fully commented.** Every test file must include: a class-level docblock explaining what the test suite covers, a comment block before each test method explaining what is being tested, why it matters, and the expected behavior, and inline comments for non-obvious arrange/act/assert steps. Tests are living documentation.
- Run tests with: `vendor/bin/phpunit`

## 7. Knowledge Recording

Before completing any phase or handing off, every agent MUST:

### Step 1: Self-Reflection (MANDATORY)

Stop and honestly answer these three questions before recording knowledge:

1. **Did I use any workarounds instead of a proper solution?** — If something felt like a hack, a temporary fix, or a "good enough for now" approach, it must be flagged. Record it as a failure with the root cause of why the proper approach was blocked, and what the correct solution would be.
2. **Is my implementation the cleanest, most maintainable version of this solution?** — Review your own output critically. If there is a more idiomatic Laravel pattern, a cleaner abstraction, or a more readable structure that you passed over, go back and fix it before handing off. Do not record "lesson learned" and move on — actually fix it.
3. **Would a senior PHP/Swoole developer reviewing this code approve it without changes?** — If the answer is no, identify what they would flag and address it now. If you cannot address it (blocked by a dependency, package limitation, or scope constraint), record it explicitly as a lesson with the specific improvement that should be made.

### Step 2: Record Knowledge

1. **Call `learn`** — Record lessons with `source="pipeline-{agent}-phase-{N}"`.
2. **Call `report-failure`** — Record any failures encountered.
3. **Call `save-generation-trace`** — Reviewer and Docs agents only.

Knowledge must include `component_types` array detected from file paths.
For work pipelines: include `pipeline_type="work"` and `work_title`.

## 8. Pipeline Document Integrity

- **The pipeline document is the source of truth**, not conversational memory.
- Every agent must update their phase section with complete details before finishing.
- Every agent must set phase Status to PASS, FAIL, or BLOCKED.
- Every agent must update the header (Status, Last Updated, Last Agent, Next Step).
- **Context continuity check is mandatory** — always verify pipeline state before proceeding.

## 9. Recall Before Action

- **NEVER skip `recall`.** Every agent calls `recall` with their agent name and pipeline phase before starting work.
- Prevention rules returned by recall are binding. They are not suggestions.

## 10. Production Safety

- Swoole-aware code: avoid static state that persists between requests. No request-specific singletons.
- Swoole Tables are shared memory — design for concurrent access.
- Coroutine-safe: no blocking I/O in coroutine context. Use Swoole hooks or async alternatives.
- Restart the server after code changes (`launchctl stop com.chadpeppers.claude-connect`).

## 11. Uncertainty Handling

- **NEVER guess when uncertain.** Mark ambiguity explicitly with `[NEEDS CLARIFICATION]` in specs and blueprints.
- Agents return `CLARIFICATION_NEEDED` blocks to PM for routing. Silent assumptions are constitutional violations.

## 12. Code Quality Standards

Every PHP file produced by any agent must meet these standards. No exceptions.

- **`declare(strict_types=1)` is required** in every PHP file. No file may omit it.
- **Every class property MUST have a PHP 8 type declaration.** Untyped properties are not acceptable.
- **Return type declarations are mandatory** on all methods. No method may omit its return type.
- **Parameter type hints are mandatory** on all method parameters. No parameter may be untyped.
- **Use Hyperf DI annotations** (`#[Inject]`) for dependency injection where applicable.
- **PSR-4 autoloading** under `App\` namespace mapping to `app/`.
