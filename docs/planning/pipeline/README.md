# Entity Generation Pipeline

**Owner:** `/pm` (Project Manager Agent)
**Scope:** Ships with product — this is the client-facing pipeline workspace.

This tree contains all pipeline tracking documents for entity generation. When a customer runs `composer create-project`, this directory ships with the project so their AI can generate entities using the 8-phase pipeline.

## Directory Structure

| Directory/File | Purpose | Owner |
|----------------|---------|-------|
| `active/` | Pipeline documents for entities currently being generated | `/pm` creates, all agents update |
| `pipeline-template.md` | Template for new pipeline documents | `/docs` maintains |
| `tmp/` | Agent scratch space (not tracked) | Any agent |

## Pipeline Lifecycle

1. `/pm` creates `PIPELINE-{Name}.md` in `active/` using `pipeline-template.md` (Phase 1)
2. Each agent updates their phase section as they work (Phases 2-7)
3. `/docs` finalizes the pipeline doc in Phase 8 (documentation, changelog, cleanup)
4. Pipeline doc is deleted from `active/` after Phase 8 completion

## Naming Convention

- `PIPELINE-{EntityName}.md` — Entity pipeline tracking documents

## Template

The pipeline document template is `pipeline-template.md` in this directory. Used by `/pm` to create new pipeline documents in Phase 1.

## Versioning (SemVer)

Both changelogs at the project root use **Semantic Versioning** — `MAJOR.MINOR.PATCH`:

- **New entity** → bump MINOR (e.g., `0.1.0` → `0.2.0`)
- **Bug fix or tweak** → bump PATCH (e.g., `0.2.0` → `0.2.1`)
- **Breaking change** → bump MAJOR (e.g., `0.2.1` → `1.0.0`)

The `/docs` agent (or `/generate` in single-agent mode) MUST bump the version and update the "Current version" line when adding a changelog entry.

## Final Step (Both Pipelines)

After completing all phases (entity pipeline Phase 8, or framework task completion), the final agent MUST run:

```bash
ddev octane-reload && ddev npm run build
```

This reloads Octane workers (picks up new PHP classes) and rebuilds the frontend CSS (picks up new Tailwind classes from new Filament resources, views, and components).
