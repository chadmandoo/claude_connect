-- Todo sections (groupings on the single todo page)
CREATE TABLE todo_sections (
    id UUID PRIMARY KEY,
    title VARCHAR(500) NOT NULL DEFAULT '',
    color VARCHAR(32) NOT NULL DEFAULT 'slate',
    sort_order INTEGER NOT NULL DEFAULT 0,
    collapsed BOOLEAN NOT NULL DEFAULT false,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);

-- Todo items within sections
CREATE TABLE todo_items (
    id UUID PRIMARY KEY,
    section_id UUID NOT NULL REFERENCES todo_sections(id) ON DELETE CASCADE,
    title VARCHAR(1000) NOT NULL DEFAULT '',
    note TEXT NOT NULL DEFAULT '',
    done BOOLEAN NOT NULL DEFAULT false,
    priority VARCHAR(16) NOT NULL DEFAULT 'normal',
    sort_order INTEGER NOT NULL DEFAULT 0,
    due_date INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    completed_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_todo_items_section ON todo_items(section_id);
CREATE INDEX idx_todo_items_done ON todo_items(done);
