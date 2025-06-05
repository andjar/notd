-- Enable Foreign Key support
PRAGMA foreign_keys = ON;

-- Pages Table
CREATE TABLE IF NOT EXISTS Pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    alias TEXT,
    active INTEGER NOT NULL DEFAULT 1, -- 1 for active, 0 for inactive/historical
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pages_name ON Pages(name);

-- Notes Table
CREATE TABLE IF NOT EXISTS Notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL,
    parent_note_id INTEGER,
    content TEXT,
    order_index INTEGER NOT NULL DEFAULT 0,
    collapsed INTEGER NOT NULL DEFAULT 0,
    internal INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1, -- 1 for active, 0 for inactive/historical
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_note_id) REFERENCES Notes(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notes_page_id ON Notes(page_id);
CREATE INDEX IF NOT EXISTS idx_notes_parent_note_id ON Notes(parent_note_id);

-- Attachments Table
CREATE TABLE IF NOT EXISTS Attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER,
    name TEXT NOT NULL,
    path TEXT NOT NULL UNIQUE,
    type TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_attachments_note_id ON Attachments(note_id);

-- Properties Table
CREATE TABLE IF NOT EXISTS Properties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER,
    page_id INTEGER,
    name TEXT NOT NULL,
    value TEXT,
    internal INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1, -- 1 for active, 0 for inactive/historical
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE,
    CHECK (
        (note_id IS NOT NULL AND page_id IS NULL) OR
        (note_id IS NULL AND page_id IS NOT NULL)
    ),
    UNIQUE (page_id, name)
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_properties_note_id_name ON Properties(note_id, name);
CREATE INDEX IF NOT EXISTS idx_properties_page_id_name ON Properties(page_id, name);
CREATE INDEX IF NOT EXISTS idx_properties_name_value ON Properties(name, value);

-- Property Definitions Table
-- This table defines which property names should be treated as internal
CREATE TABLE IF NOT EXISTS PropertyDefinitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    internal INTEGER NOT NULL DEFAULT 0,
    description TEXT,
    auto_apply INTEGER NOT NULL DEFAULT 1, -- Whether to auto-apply to existing properties
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_property_definitions_name ON PropertyDefinitions(name);
CREATE INDEX IF NOT EXISTS idx_property_definitions_internal ON PropertyDefinitions(internal);

-- Triggers for updated_at (optional, can be handled by PHP)
CREATE TRIGGER IF NOT EXISTS update_pages_updated_at
AFTER UPDATE ON Pages FOR EACH ROW
BEGIN
    UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

-- CREATE TRIGGER IF NOT EXISTS update_properties_updated_at
-- AFTER UPDATE ON Properties FOR EACH ROW
-- BEGIN
--     UPDATE Properties SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
-- END;

CREATE TRIGGER IF NOT EXISTS update_property_definitions_updated_at
AFTER UPDATE ON PropertyDefinitions FOR EACH ROW
BEGIN
    UPDATE PropertyDefinitions SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

-- Insert default property definitions
INSERT OR IGNORE INTO PropertyDefinitions (name, internal, description, auto_apply) VALUES
('internal', 1, 'Properties that control note/page visibility', 1),
('debug', 1, 'Debug and development properties', 1),
('system', 1, 'System-generated properties', 1),
('_private', 1, 'Private properties (underscore prefix)', 1),
('metadata', 1, 'Metadata properties for internal use', 1);