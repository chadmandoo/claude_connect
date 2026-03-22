-- migrations/001_initial.sql
-- Full schema for Claude Connect PostgreSQL migration

-- Conversations
CREATE TABLE conversations (
    id UUID PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL DEFAULT '',
    type VARCHAR(32) NOT NULL DEFAULT 'task',
    state VARCHAR(32) NOT NULL DEFAULT 'active',
    project_id VARCHAR(255) NOT NULL DEFAULT 'general',
    source VARCHAR(32) NOT NULL DEFAULT 'web',
    summary TEXT NOT NULL DEFAULT '',
    key_takeaways TEXT NOT NULL DEFAULT '[]',
    total_cost_usd NUMERIC(12,6) NOT NULL DEFAULT 0,
    turn_count INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_conversations_project ON conversations(project_id);
CREATE INDEX idx_conversations_created ON conversations(created_at);
CREATE INDEX idx_conversations_state ON conversations(state);

-- Conversation turns
CREATE TABLE conversation_turns (
    id BIGSERIAL PRIMARY KEY,
    conversation_id UUID NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role VARCHAR(16) NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    task_id VARCHAR(255) NOT NULL DEFAULT '',
    cost_usd NUMERIC(12,6) NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_conv_turns_conversation ON conversation_turns(conversation_id);

-- Projects
CREATE TABLE projects (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    goal TEXT NOT NULL DEFAULT '',
    plan TEXT NOT NULL DEFAULT '[]',
    state VARCHAR(32) NOT NULL DEFAULT 'planning',
    current_step INTEGER NOT NULL DEFAULT 0,
    total_steps INTEGER NOT NULL DEFAULT 0,
    completed_steps INTEGER NOT NULL DEFAULT 0,
    total_cost_usd NUMERIC(12,6) NOT NULL DEFAULT 0,
    max_iterations INTEGER NOT NULL DEFAULT 20,
    max_budget_usd NUMERIC(12,6) NOT NULL DEFAULT 10.00,
    checkpoint_interval INTEGER NOT NULL DEFAULT 5,
    current_task_id VARCHAR(255) NOT NULL DEFAULT '',
    retry_count INTEGER NOT NULL DEFAULT 0,
    paused_reason TEXT NOT NULL DEFAULT '',
    error TEXT NOT NULL DEFAULT '',
    waiting_for_reply VARCHAR(8) NOT NULL DEFAULT '0',
    cwd VARCHAR(1024) NOT NULL DEFAULT '',
    user_id VARCHAR(255) NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    completed_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_projects_state ON projects(state);
CREATE INDEX idx_projects_created ON projects(created_at);

-- Project name lookup
CREATE TABLE project_names (
    name_lower VARCHAR(255) PRIMARY KEY,
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE
);

-- Project history
CREATE TABLE project_history (
    id BIGSERIAL PRIMARY KEY,
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    from_state VARCHAR(32),
    to_state VARCHAR(32) NOT NULL,
    reason TEXT,
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_project_history_project ON project_history(project_id);

-- Project steps
CREATE TABLE project_steps (
    id BIGSERIAL PRIMARY KEY,
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    step_data JSONB NOT NULL DEFAULT '{}',
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_project_steps_project ON project_steps(project_id);

-- Tasks (conversation_id FK ensures integrity)
-- project_id is VARCHAR because 'general' is a virtual project, not a real UUID
CREATE TABLE tasks (
    id UUID PRIMARY KEY,
    prompt TEXT NOT NULL DEFAULT '',
    session_id VARCHAR(255) NOT NULL DEFAULT '',
    claude_session_id VARCHAR(255) NOT NULL DEFAULT '',
    parent_task_id VARCHAR(255) NOT NULL DEFAULT '',
    conversation_id UUID REFERENCES conversations(id) ON DELETE SET NULL,
    project_id VARCHAR(255) NOT NULL DEFAULT 'general',
    source VARCHAR(32) NOT NULL DEFAULT 'web',
    state VARCHAR(32) NOT NULL DEFAULT 'pending',
    result TEXT NOT NULL DEFAULT '',
    error TEXT NOT NULL DEFAULT '',
    pid INTEGER NOT NULL DEFAULT 0,
    cost_usd NUMERIC(12,6) NOT NULL DEFAULT 0,
    images TEXT NOT NULL DEFAULT '',
    progress TEXT NOT NULL DEFAULT '',
    options TEXT NOT NULL DEFAULT '{}',
    user_id VARCHAR(255) NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    started_at INTEGER NOT NULL DEFAULT 0,
    completed_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_tasks_state ON tasks(state);
CREATE INDEX idx_tasks_created ON tasks(created_at);
CREATE INDEX idx_tasks_conversation ON tasks(conversation_id);
CREATE INDEX idx_tasks_user ON tasks(user_id);
CREATE INDEX idx_tasks_project ON tasks(project_id);

-- Task history (state transitions)
CREATE TABLE task_history (
    id BIGSERIAL PRIMARY KEY,
    task_id UUID NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
    from_state VARCHAR(32),
    to_state VARCHAR(32) NOT NULL,
    extra JSONB NOT NULL DEFAULT '{}',
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_task_history_task ON task_history(task_id);

-- Epics
CREATE TABLE epics (
    id UUID PRIMARY KEY,
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title VARCHAR(500) NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    state VARCHAR(32) NOT NULL DEFAULT 'open',
    is_backlog BOOLEAN NOT NULL DEFAULT false,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_epics_project ON epics(project_id);
CREATE UNIQUE INDEX idx_epics_backlog_unique ON epics(project_id) WHERE is_backlog = true;

-- Items (work items)
CREATE TABLE items (
    id UUID PRIMARY KEY,
    epic_id UUID REFERENCES epics(id) ON DELETE SET NULL,
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title VARCHAR(500) NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    state VARCHAR(32) NOT NULL DEFAULT 'open',
    priority VARCHAR(16) NOT NULL DEFAULT 'normal',
    sort_order INTEGER NOT NULL DEFAULT 0,
    conversation_id VARCHAR(255) NOT NULL DEFAULT '',
    assigned_to VARCHAR(255) NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    completed_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_items_epic ON items(epic_id);
CREATE INDEX idx_items_project ON items(project_id);
CREATE INDEX idx_items_state ON items(state);

-- Item notes / activity log
CREATE TABLE item_notes (
    id BIGSERIAL PRIMARY KEY,
    item_id UUID NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    content TEXT NOT NULL DEFAULT '',
    author VARCHAR(255) NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_item_notes_item ON item_notes(item_id);

-- Item <-> Conversation links
CREATE TABLE item_conversations (
    item_id UUID NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    conversation_id UUID NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    linked_at INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (item_id, conversation_id)
);

-- Structured memory entries
CREATE TABLE memories (
    id VARCHAR(255) PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    category VARCHAR(64) NOT NULL DEFAULT 'fact',
    content TEXT NOT NULL DEFAULT '',
    importance VARCHAR(16) NOT NULL DEFAULT 'normal',
    source VARCHAR(64) NOT NULL DEFAULT 'inline',
    project_id VARCHAR(255),
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_memories_user ON memories(user_id);
CREATE INDEX idx_memories_user_project ON memories(user_id, project_id);
CREATE INDEX idx_memories_created ON memories(created_at);

-- Legacy key-value memory facts
CREATE TABLE memory_facts (
    user_id VARCHAR(255) NOT NULL,
    key VARCHAR(255) NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (user_id, key)
);

-- Memory conversation log
CREATE TABLE memory_log (
    id BIGSERIAL PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    summary TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_memory_log_user ON memory_log(user_id);

-- Sessions
CREATE TABLE sessions (
    id VARCHAR(255) PRIMARY KEY,
    data JSONB NOT NULL DEFAULT '{}',
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);

-- Skills
CREATE TABLE skills (
    scope VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    config JSONB NOT NULL DEFAULT '{}',
    PRIMARY KEY (scope, name)
);

-- Channels
CREATE TABLE channels (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    member_count INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);

-- Channel messages
CREATE TABLE channel_messages (
    id VARCHAR(255) PRIMARY KEY,
    channel_id VARCHAR(255) NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    author VARCHAR(255) NOT NULL DEFAULT '',
    content TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_channel_messages_channel ON channel_messages(channel_id, created_at);

-- Scheduled jobs
CREATE TABLE scheduled_jobs (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    schedule_type VARCHAR(32) NOT NULL DEFAULT 'interval',
    schedule_seconds INTEGER NOT NULL DEFAULT 3600,
    schedule_hour INTEGER NOT NULL DEFAULT 0,
    schedule_minute INTEGER NOT NULL DEFAULT 0,
    enabled BOOLEAN NOT NULL DEFAULT true,
    handler VARCHAR(255) NOT NULL DEFAULT '',
    last_run INTEGER NOT NULL DEFAULT 0,
    next_run INTEGER NOT NULL DEFAULT 0,
    last_result TEXT NOT NULL DEFAULT '',
    last_duration INTEGER NOT NULL DEFAULT 0,
    run_count INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);

-- Nightly run history
CREATE TABLE nightly_run_history (
    id BIGSERIAL PRIMARY KEY,
    stats JSONB NOT NULL DEFAULT '{}',
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_nightly_history_created ON nightly_run_history(created_at);
