-- migrations/002_agents_and_rooms.sql
-- Agent system: stored agents with editable prompts, room-agent junctions

-- Agents table
CREATE TABLE agents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    system_prompt TEXT NOT NULL DEFAULT '',
    model VARCHAR(100) NOT NULL DEFAULT '',
    tool_access JSONB NOT NULL DEFAULT '[]',
    project_id UUID REFERENCES projects(id) ON DELETE SET NULL,
    memory_scope VARCHAR(255) NOT NULL DEFAULT '',
    is_default BOOLEAN NOT NULL DEFAULT false,
    is_system BOOLEAN NOT NULL DEFAULT false,
    color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
    icon VARCHAR(50) NOT NULL DEFAULT 'bot',
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_agents_slug ON agents(slug);
CREATE INDEX idx_agents_project ON agents(project_id);

-- Room agents junction (channels <-> agents)
CREATE TABLE room_agents (
    room_id VARCHAR(255) NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    agent_id UUID NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
    is_active_default BOOLEAN NOT NULL DEFAULT false,
    added_at INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (room_id, agent_id)
);

-- Add agent_id to conversations
ALTER TABLE conversations ADD COLUMN agent_id UUID REFERENCES agents(id) ON DELETE SET NULL;

-- Add agent_id to channel_messages
ALTER TABLE channel_messages ADD COLUMN agent_id UUID REFERENCES agents(id) ON DELETE SET NULL;

-- Add default_agent_id to projects
ALTER TABLE projects ADD COLUMN default_agent_id UUID REFERENCES agents(id) ON DELETE SET NULL;

-- Add agent_scope to memories
ALTER TABLE memories ADD COLUMN agent_scope VARCHAR(255) NOT NULL DEFAULT '';

-- Add notified_at to tasks if not present (used by markNotified)
DO $$ BEGIN
    ALTER TABLE tasks ADD COLUMN notified_at INTEGER NOT NULL DEFAULT 0;
EXCEPTION WHEN duplicate_column THEN NULL;
END $$;

-- Add title to conversations if not present
DO $$ BEGIN
    ALTER TABLE conversations ADD COLUMN title VARCHAR(500) NOT NULL DEFAULT '';
EXCEPTION WHEN duplicate_column THEN NULL;
END $$;
