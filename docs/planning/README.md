# Planning Directory Structure

**Owner:** `/pm` (Project Manager Agent)

## Pipeline Workspace

Entity and work pipeline workspace. Contains templates and active workspace for the 8-phase entity generation pipeline and the 6-phase work pipeline.

```
pipeline/
├── active/                      ← In-progress pipelines (PIPELINE-{Name}.md, WORK-{Title}.md)
├── pipeline-template.md         ← Entity pipeline template (8 phases)
├── work-pipeline-template.md    ← Work pipeline template (6 phases)
├── project-plan-template.md     ← Project planning template
└── tmp/                         ← Agent scratch space
```

## Knowledge (Database-Backed)

All RLM knowledge has been migrated to the Forge database and is accessible via MCP:

- **Validation Patterns** — `search-docs(category=validation)` — 11 pattern files + world model
- **Golden Entity Guide** — `search-docs(category=ai-quality)` — generation reference
- **Base Failures** — `recall` MCP tool — 15 universal failures (BF-001 through BF-015)
- **RLM Scores** — `rlm-scores` MCP tool — entity quality scores
- **Slash Commands** — `sync-agents` MCP tool — agent command distribution
