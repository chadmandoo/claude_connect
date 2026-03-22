# Pipeline: {Entity Name}

| Field | Value |
|-------|-------|
| **Pipeline Type** | Entity |
| **Status** | Phase 1: Plan |
| **Tier** | 1 (Entity) |
| **Created** | {date} |
| **Last Updated** | {date} |
| **Last Agent** | /pm |
| **Next Step** | Human review spec, then `/solutions design {Name}` |
| **Blocked** | No |
| **Designer Phase** | Included / Skipped — {reason} |

---

## Phase 1: Plan
**Agent:** /pm
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

### Entity Spec
- **Name:** {PascalCase}
- **Table:** {snake_plural}
- **Spec File:** `specs/{Name}.entity.md` *(created by /pm, validated with `aicl:validate-spec`)*
- **Fields:**
  - `id` — bigIncrements (PK)
  - `owner_id` — foreignId (users)
  - {field} — {type}
  - `created_at` / `updated_at` — timestamps
  - `deleted_at` — softDeletes
- **Relationships:**
  - {method}(): {Type} → {RelatedModel} ({foreign_key})
- **States:** {if applicable — list with transitions, or "None"}
- **Traits:** {list from HasEntityEvents, HasAuditTrail, HasStandardScopes, HasTagging, HasSearchableFields}
- **Contracts:** {list — derived from traits}
- **Widgets:** {list — per world-model.md decision rules}
- **Notifications:** {list — assignment, status change}

### Spec Validation
```bash
ddev artisan aicl:validate-spec {Name}
```
- **Result:** PASS | FAIL
- **Errors:** {count or "None"}
- **Warnings:** {count or "None"}

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

### Design Blueprint

**Relationships:**
- {method}(): {Type} → {RelatedModel} (foreign key: {column})

**State Machine:**
- States: {list with label, color, icon}
- Transitions: {from → to}
- Default: {state}

**Business Rules:**
- {validation constraints, computed fields, side effects, scopes}

**Widget Selection:**
- {widget type}: {what it displays}

**Notification Triggers:**
- {event} → {recipients} via {channels}

**Architectural Decisions:**
- {any deviations from golden pattern, or "Standard golden pattern"}

### Deferred Items
- {anything that couldn't be decided — if present, Status MUST be BLOCKED}

### Issues Found
- {any concerns or risks}

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-solutions-phase-2"`}
- **Failures:** {count — call `report-failure` for design issues}
- **Component Types:** {list from design blueprint}

### Human Confirmed
- [ ] Design reviewed and confirmed

---

## Phase 3: Generate
**Agent:** /architect
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

### Scaffolder Command
```bash
ddev artisan aicl:make-entity {Name} --from-spec --no-interaction
```
*(Reads from `specs/{Name}.entity.md` — all fields, states, relationships, enums, and options defined in the spec file)*

### Files Created
| File | Path |
|------|------|
| Model | `app/Models/{Name}.php` |
| Migration | `database/migrations/{timestamp}_create_{table}_table.php` |
| Factory | `database/factories/{Name}Factory.php` |
| Seeder | `database/seeders/{Name}Seeder.php` |
| Policy | `app/Policies/{Name}Policy.php` |
| Observer | `app/Observers/{Name}Observer.php` |
| Filament Resource | `app/Filament/Resources/{Plural}/{Name}Resource.php` |
| API Controller | `app/Http/Controllers/Api/{Name}Controller.php` |
| Store Request | `app/Http/Requests/Store{Name}Request.php` |
| Update Request | `app/Http/Requests/Update{Name}Request.php` |
| API Resource | `app/Http/Resources/{Name}Resource.php` |
| Exporter | `app/Filament/Exporters/{Name}Exporter.php` |
| Widgets | `app/Filament/Widgets/{Name}*.php` |
| Notifications | `app/Notifications/{Name}*.php` |
| Test | `tests/Feature/Entities/{Name}Test.php` |

### Pint
- **Status:** Pass | Fail
- **Issues Fixed:** {count or "Clean"}

### Package Check
- **Status:** CLEAN | VIOLATION
- **Details:** {confirm no files written to vendor/}

### Cheat Sheet Delivered
- **Cheat Sheet Delivered:** {DL-001, DL-003, DL-007 -- comma-separated lesson codes from recall output}

### Notes
- {any deviations from design blueprint, or "Followed design exactly"}

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-architect-phase-3"`}
- **Failures:** {count — call `report-failure` for issues encountered}
- **Component Types:** {list from file manifest}

---

## Phase 3.5: Style (Conditional)
**Agent:** /designer
**Status:** PASS | BLOCKED | SKIPPED | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

*This phase is included when the entity has widgets, PDF templates, or custom UI. Skipped for simple CRUD entities.*

### Files Modified
| File | Change |
|------|--------|
| {file} | {what was improved} |

### Pint
- **Status:** Pass | Fail

### Review Summary
- **Form Layout:** {improvements made or "Adequate"}
- **Table Columns:** {improvements made or "Adequate"}
- **Widget Styling:** {improvements made or "N/A"}
- **PDF Templates:** {improvements made or "N/A"}
- **Component Reuse:** {components introduced or "None needed"}
- **Token Compliance:** {hardcoded values replaced or "All tokens correct"}
- **Dark Mode:** {verified or issues found}

### Issues Found
- {any concerns or recommendations}

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-designer-phase-3.5"`}
- **Failures:** {count — call `report-failure` for UI issues encountered}
- **Component Types:** {list from files modified}

---

## Phase 4: Validate (Pre-Registration)

### RLM Validation
**Agent:** /review validate {Name}
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}
- **Score:** {N}/40 ({percentage}%)
- **Failing Patterns:** {list with pattern names and fixes, or "None"}
- **Component Types:** {detected component types from file manifest}
- **Retry Count:** 0
- **Feedback:** Agent records score and failures via Forge MCP with `component_types`

### Tester Validation
**Agent:** /tester
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}
- **PHPUnit Test Count:** {N tests, N assertions}
- **PHPUnit Failing Tests:** {list with failure reasons, or "None"}
- **Retry Count:** 0

### Playwright Tests
- **Playwright Test Count:** {N tests}
- **Playwright Passing:** {N}
- **Playwright Failing:** {list with failure reasons, or "None"}
- **Tests Written:** {list of test files created from Regression Test Plan}

### Feedback
- **Feedback:** Call Forge MCP `learn(summary="Pipeline feedback phase-4: surfaced [{DL codes}], failures [{BF codes}]", topic="pipeline-feedback", source="pipeline-phase-4")`
- **Feedback Result:** {lessons surfaced, failures recorded}

---

## Phase 5: Register
**Agent:** /architect
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

### Modified Files
| File | Change |
|------|--------|
| `AppServiceProvider.php` | Added `Gate::policy()` and `Model::observe()` |
| `routes/api.php` | Added API resource routes |

### Pint
- **Status:** Pass | Fail

### Verification
- [ ] Policy bound in AppServiceProvider
- [ ] Observer bound in AppServiceProvider
- [ ] API routes added
- [ ] Filament resource auto-discovered

### Knowledge Recorded
- **Lessons:** {count — call `learn` with `source="pipeline-architect-phase-5"`}
- **Failures:** {count — call `report-failure` for registration issues}
- **Component Types:** {list from modified files}

---

## Phase 6: Re-Validate (Post-Registration)

### RLM Re-Validation
**Agent:** /review validate {Name}
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}
- **Score:** {N}/40 ({percentage}%)
- **Regressions from Phase 4:** {list or "None"}
- **Component Types:** {detected component types from file manifest}
- **Retry Count:** 0
- **Feedback:** Agent records score and failures via Forge MCP with `component_types`

### Tester Re-Validation
**Agent:** /tester
**Status:** PASS | FAIL | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}
- **PHPUnit Test Count:** {N tests, N assertions}
- **PHPUnit Regressions from Phase 4:** {list or "None"}
- **Retry Count:** 0

### Playwright Re-Validation
- **Playwright Test Count:** {N tests}
- **Playwright Passing:** {N}
- **Playwright Failing:** {list with failure reasons, or "None"}
- **Playwright Regressions from Phase 4:** {list or "None"}

### Feedback
- **Feedback:** Call Forge MCP `learn(summary="Pipeline feedback phase-6: surfaced [{DL codes}], failures [{BF codes}]", topic="pipeline-feedback", source="pipeline-phase-6")`
- **Feedback Result:** {lessons surfaced, failures recorded}

---

## Phase 7: Verify (Full Suite)
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
- **Lessons:** {count — call `learn` with `source="pipeline-tester-phase-7"`}
- **Failures:** {count — call `report-failure` for regression issues}
- **Component Types:** {list from test subjects}

---

## Phase 8: Complete
**Agent:** /docs
**Status:** PASS | Not Started
**Started:** {timestamp}
**Completed:** {timestamp}

- **Entity Doc:** `docs/entities/{name}.md`
- **API Doc Updated:** Yes | No
- **Changelog Updated:** Yes | No
- **Pipeline Doc Deleted:** Yes | No
- **Octane Reloaded:** Yes | No
- **Frontend Rebuilt:** Yes | No

### After-Action Review (MANDATORY)
- **Generation Trace Saved:** Yes | No — call `save-generation-trace` with full pipeline data and `component_types`
- **Lessons Recorded:** {count} — call `learn` for each lesson discovered across all phases
- **Failures Recorded:** {count} — call `report-failure` for each failure encountered
- **Component Types Tagged:** {aggregated list across all phases}
