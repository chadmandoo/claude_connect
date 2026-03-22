-- migrations/003_memory_redesign.sql
-- Memory system redesign: core vs project types, agent scoping, staleness tracking

-- Memory type: 'core' (always injected, never auto-pruned) or 'project' (ranked, subject to staleness)
DO $$ BEGIN
    ALTER TABLE memories ADD COLUMN type VARCHAR(16) NOT NULL DEFAULT 'project';
EXCEPTION WHEN duplicate_column THEN NULL;
END $$;

-- Track when a memory was last included in a prompt (for staleness detection)
DO $$ BEGIN
    ALTER TABLE memories ADD COLUMN last_surfaced_at INTEGER NOT NULL DEFAULT 0;
EXCEPTION WHEN duplicate_column THEN NULL;
END $$;

-- Track last modification
DO $$ BEGIN
    ALTER TABLE memories ADD COLUMN updated_at INTEGER NOT NULL DEFAULT 0;
EXCEPTION WHEN duplicate_column THEN NULL;
END $$;

-- Indexes
CREATE INDEX IF NOT EXISTS idx_memories_type ON memories(type);
CREATE INDEX IF NOT EXISTS idx_memories_agent_scope ON memories(agent_scope);
CREATE INDEX IF NOT EXISTS idx_memories_last_surfaced ON memories(last_surfaced_at);
CREATE INDEX IF NOT EXISTS idx_memories_type_project ON memories(type, project_id);

-- Migrate existing high-importance general memories to 'core' type
UPDATE memories SET type = 'core' WHERE importance = 'high' AND project_id IS NULL AND type = 'project';

-- Backfill updated_at from created_at where not set
UPDATE memories SET updated_at = created_at WHERE updated_at = 0;
