# Work Pipeline: {Title}

| Field | Value |
|-------|-------|
| **Pipeline Type** | Work |
| **Work Type** | Feature / Integration / Infrastructure / Refactor |
| **Status** | Phase 1: Plan |
| **Created** | {date} |
| **Last Updated** | {date} |
| **Last Agent** | /pm |
| **Next Step** | Human review spec, then `/solutions design-work {Title}` |
| **Blocked** | No |
| **Designer Phase** | Included / Skipped — {reason} |

---

## Phase 1: Plan
**Agent:** /pm
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

### Work Spec
- **Title:** {descriptive title}
- **Type:** Feature | Integration | Infrastructure | Refactor
- **Scope:** {1-2 sentence summary of what this work accomplishes}
- **Files Expected:** {estimated count and locations}
- **Dependencies:** {packages, services, or entities this depends on}
- **Risks:** {what could go wrong, or "Low risk"}
- **Acceptance Criteria:**
  - {criterion 1}
  - {criterion 2}
  - {criterion 3}

### Human Confirmed
- [ ] Spec reviewed and confirmed

### Known Pitfalls (from RLM)
- {Call Forge MCP `recall(agent="pm", phase=1)` — list applicable pitfalls, or "None found"}

---

## Forge Briefing

Agents MUST call Forge MCP tools before starting each phase:

1. **Bootstrap** — Call `bootstrap` MCP tool (forge server) to get project context, architecture decisions, and active patterns
2. **Recall** — Call `recall` MCP tool with `agent="{role}", phase={N}, component_types=[...]` to get targeted failures, lessons, and prevention rules filtered by the component types you're working with
3. **Search** — Call `search-knowledge` MCP tool for concept-specific knowledge (e.g., state-machines, factory-relationships)

Knowledge is served from the centralized Forge knowledge base.

## Phase 2: Design
**Agent:** /solutions
**Status:** PASS | FAIL | BLOCKED | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

### Architecture

**Approach:**
- {high-level description of the solution approach}

**File Manifest:**
| # | File | Action | Purpose |
|---|------|--------|---------|
| 1 | {path} | Create / Modify | {what it does} |
| 2 | {path} | Create / Modify | {what it does} |

**Business Rules:**
- {validation constraints, side effects, edge cases}

**Testing Strategy:**
- {what tests to write, what to cover, edge cases}

**Architectural Decisions:**
- {any deviations from standard patterns, or "Standard patterns"}

### Deferred Items
- {anything that couldn't be decided — if present, Status MUST be BLOCKED}

### Issues Found
- {any concerns or risks}

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-solutions-phase-2"`, `pipeline_type="work"`, `work_title="{Title}"`}
- **Failures:** {count — call `report-failure` for design issues}
- **Component Types:** {list from design}

### Human Confirmed
- [ ] Design reviewed and confirmed

---

## Phase 3: Implement
**Agent:** /architect
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

### Files Created
| File | Path |
|------|------|
| {description} | {path} |

### Files Modified
| File | Change |
|------|--------|
| {path} | {what was changed} |

### Migrations
- {list of migrations run, or "None"}

### Routes Added
- {list of routes, or "None"}

### Providers/Config Updated
- {list of provider or config changes, or "None"}

### Pint
- **Status:** Pass | Fail
- **Issues Fixed:** {count or "Clean"}

### Package Check
- **Status:** CLEAN | VIOLATION
- **Details:** {confirm no files written to vendor/}

### Notes
- {any deviations from design, or "Followed design exactly"}

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-architect-phase-3"`, `pipeline_type="work"`, `work_title="{Title}"`}
- **Failures:** {count — call `report-failure` for issues encountered}
- **Component Types:** {list from file manifest}

---

## Phase 3.5: Style (Conditional)
**Agent:** /designer
**Status:** PASS | BLOCKED | SKIPPED | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

*This phase is included when the work has UI components, views, or frontend changes. Skipped for backend-only work.*

### Files Modified
| File | Change |
|------|--------|
| {file} | {what was improved} |

### Pint
- **Status:** Pass | Fail

### Review Summary
- **UI Quality:** {improvements made or "N/A"}
- **Component Reuse:** {components introduced or "None needed"}
- **Token Compliance:** {hardcoded values replaced or "All tokens correct"}
- **Dark Mode:** {verified or "N/A"}

### Issues Found
- {any concerns or recommendations}

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-designer-phase-3.5"`, `pipeline_type="work"`, `work_title="{Title}"`}
- **Failures:** {count — call `report-failure` for UI issues}
- **Component Types:** {list from files modified}

---

## Phase 4: Validate
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

### Code Review
**Agent:** /review work {Title}
- **Standards Compliance:** Pass | Fail — {details}
- **Package Boundary:** CLEAN | VIOLATION
- **Security Review:** Pass | Fail — {details}
- **Component Types:** {detected from files created/modified in Phase 3}
- **Feedback:** Agent records score and failures via Forge MCP with `component_types` and `pipeline_type="work"`

### Test Results
**Agent:** /tester
- **PHPUnit Test Count:** {N tests, N assertions}
- **PHPUnit Failing Tests:** {list with failure reasons, or "None"}
- **Retry Count:** 0

### Playwright Tests
- **Playwright Test Count:** {N tests}
- **Playwright Passing:** {N}
- **Playwright Failing:** {list with failure reasons, or "None"}
- **Tests Written:** {list of test files created from Regression Test Plan}

### Notes
- {code quality observations, or "Clean"}

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-tester-phase-4"`, `pipeline_type="work"`, `work_title="{Title}"`}
- **Failures:** {count — call `report-failure` for test/review issues}
- **Component Types:** {list from files under test/review}

---

## Phase 5: Verify (Full Suite)
**Agent:** /tester
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

- **PHPUnit Full Suite:** Pass | Fail
- **PHPUnit Test Count:** {N tests, N assertions}
- **PHPUnit Regressions:** {none or list of regressed tests}

### Playwright Full Suite
- **Playwright Full Suite:** Pass | Fail
- **Playwright Test Count:** {N tests}
- **Playwright Passing:** {N}
- **Playwright Failing:** {list or "None"}
- **Playwright Regressions:** {none or list of regressed tests}

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-tester-phase-5"`, `pipeline_type="work"`, `work_title="{Title}"`}
- **Failures:** {count — call `report-failure` for regression issues}
- **Component Types:** {list from test subjects}

---

## Phase 6: Complete
**Agent:** /docs
**Status:** PASS | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

- **Documentation Updated:** {list of docs updated, or "None needed"}
- **Changelog Updated:** Yes | No
- **Pipeline Doc Deleted:** Yes | No
- **Octane Reloaded:** Yes | No
- **Frontend Rebuilt:** Yes | No

### After-Action Review (MANDATORY)
**Agent:** /review aar
- **Generation Trace Saved:** Yes | No — call `save-generation-trace` with `pipeline_type="work"`, `work_title="{Title}"`, and `component_types`
- **Lessons Recorded:** {count} — call `learn` for each lesson discovered across all phases
- **Failures Recorded:** {count} — call `report-failure` for each failure encountered
- **Component Types Tagged:** {aggregated list across all phases}
- **Promotions:** {count — any lessons/failures promoted to global visibility}
