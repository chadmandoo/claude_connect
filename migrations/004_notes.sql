-- Notebooks (sections/containers for notes)
CREATE TABLE notebooks (
    id UUID PRIMARY KEY,
    title VARCHAR(500) NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    color VARCHAR(32) NOT NULL DEFAULT 'slate',
    icon VARCHAR(64) NOT NULL DEFAULT 'notebook',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);

-- Pages (individual notes within notebooks)
CREATE TABLE pages (
    id UUID PRIMARY KEY,
    notebook_id UUID REFERENCES notebooks(id) ON DELETE CASCADE,
    title VARCHAR(500) NOT NULL DEFAULT '',
    content TEXT NOT NULL DEFAULT '',
    pinned BOOLEAN NOT NULL DEFAULT false,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_pages_notebook ON pages(notebook_id);
CREATE INDEX idx_pages_pinned ON pages(pinned) WHERE pinned = true;
