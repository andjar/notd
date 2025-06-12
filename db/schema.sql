-- Notd Database Schema
-- Version: 2.0 (Content-first Property Model)
--
-- This schema implements a "content-first" architecture where the `content`
-- field of a Note or Page is the single source of truth for its properties.
-- The `Properties` table serves as a queryable index of the properties
-- parsed from the content.

-- Enable Foreign Key support for data integrity
PRAGMA foreign_keys = ON;

-- Pages Table
-- Pages are top-level containers for notes. They now include a `content`
-- field to store page-level properties and other metadata.
CREATE TABLE IF NOT EXISTS Pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    content TEXT, -- Content for the page itself, used for page-level properties
    alias TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pages_name ON Pages(LOWER(name));

-- Notes Table
-- Notes are the core content blocks, belonging to a single page.
CREATE TABLE IF NOT EXISTS Notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL,
    parent_note_id INTEGER,
    content TEXT,
    order_index INTEGER NOT NULL DEFAULT 0,
    collapsed INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_note_id) REFERENCES Notes(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notes_page_id ON Notes(page_id);
CREATE INDEX IF NOT EXISTS idx_notes_parent_note_id ON Notes(parent_note_id);

-- Attachments Table
-- Stores file attachments linked to notes.
CREATE TABLE IF NOT EXISTS Attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER,
    name TEXT NOT NULL,
    path TEXT NOT NULL UNIQUE,
    type TEXT,
    size INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_attachments_note_id ON Attachments(note_id);

-- Properties Table
-- This table is an INDEX of properties parsed from Note and Page content.
-- It is managed by the backend and should not be written to directly.
CREATE TABLE IF NOT EXISTS Properties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER,
    page_id INTEGER,
    name TEXT NOT NULL,
    value TEXT,
    weight INTEGER NOT NULL DEFAULT 2, -- Derived from the number of colons (e.g., '::' -> 2, ':::' -> 3)
    active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE,
    CHECK (
        (note_id IS NOT NULL AND page_id IS NULL) OR
        (note_id IS NULL AND page_id IS NOT NULL)
    )
);

-- Indexes for performance on the Properties table
CREATE INDEX IF NOT EXISTS idx_properties_note_id_name ON Properties(note_id, name);
CREATE INDEX IF NOT EXISTS idx_properties_page_id_name ON Properties(page_id, name);
CREATE INDEX IF NOT EXISTS idx_properties_name_value ON Properties(name, value);
CREATE INDEX IF NOT EXISTS idx_properties_weight ON Properties(weight); -- New index for querying by weight

-- Triggers to automatically update `updated_at` timestamps
CREATE TRIGGER IF NOT EXISTS update_pages_updated_at
AFTER UPDATE ON Pages FOR EACH ROW
BEGIN
    UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS update_properties_updated_at
AFTER UPDATE ON Properties FOR EACH ROW
BEGIN
    UPDATE Properties SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

-- Webhooks Table
-- Manages webhook subscriptions for real-time event notifications.
CREATE TABLE IF NOT EXISTS Webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT NOT NULL,
    secret TEXT NOT NULL,
    entity_type TEXT NOT NULL, -- 'note' or 'page'
    property_names TEXT NOT NULL, -- JSON array of property names or "*" for all properties
    event_types TEXT NOT NULL DEFAULT '["property_change"]', -- JSON array of event types
    active INTEGER NOT NULL DEFAULT 1,
    verified INTEGER NOT NULL DEFAULT 0,
    last_verified DATETIME,
    last_triggered DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_webhooks_lookup ON Webhooks(entity_type, active);

-- Webhook Events Log Table
-- Logs all outgoing webhook attempts and their results.
CREATE TABLE IF NOT EXISTS WebhookEvents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id INTEGER NOT NULL,
    event_type TEXT NOT NULL, -- e.g., 'property_change', 'test', 'verification'
    payload TEXT,
    response_code INTEGER,
    response_body TEXT,
    success INTEGER NOT NULL, -- 1 for success (2xx), 0 for failure
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (webhook_id) REFERENCES Webhooks(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_webhook_events_webhook_id ON WebhookEvents(webhook_id);

-- FTS5 Virtual Table for Full-Text Search on Notes
-- This table is a shadow of the Notes table, indexed for fast searching.
CREATE VIRTUAL TABLE IF NOT EXISTS Notes_fts USING fts4(
    content
);

-- Triggers to keep the FTS table in sync with the Notes table
CREATE TRIGGER IF NOT EXISTS Notes_after_insert AFTER INSERT ON Notes BEGIN
  INSERT INTO Notes_fts(rowid, content) VALUES (new.id, new.content);
END;
CREATE TRIGGER IF NOT EXISTS Notes_after_delete AFTER DELETE ON Notes BEGIN
  DELETE FROM Notes_fts WHERE docid=old.id;
END;
CREATE TRIGGER IF NOT EXISTS Notes_after_update AFTER UPDATE ON Notes BEGIN
  DELETE FROM Notes_fts WHERE docid=old.id;
  INSERT INTO Notes_fts(rowid, content) VALUES (new.id, new.content);
END;