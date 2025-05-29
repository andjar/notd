<?php
// Create db directory if it doesn't exist
$dbDir = __DIR__;
if (!file_exists($dbDir)) {
    mkdir($dbDir, 0777, true);
}

// If the database does not exist, create it and initialize schema
$dbPath = $dbDir . '/notes.db';
if (!file_exists($dbPath)) {
    try {
        echo "Creating new database at: " . $dbPath . "\n";
        $db = new SQLite3($dbPath);
        
        if (!$db) {
            throw new Exception('Failed to create database: ' . SQLite3::lastErrorMsg());
        }

        echo "Creating tables...\n";

        // Create pages table first (since it's referenced by other tables)
        echo "Creating pages table...\n";
        $result = $db->exec('CREATE TABLE IF NOT EXISTS pages (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            type TEXT DEFAULT "journal",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        if (!$result) {
            throw new Exception('Failed to create pages table: ' . $db->lastErrorMsg());
        }

        // Create notes table with block_id
        echo "Creating notes table...\n";
        $result = $db->exec('CREATE TABLE IF NOT EXISTS notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id TEXT NOT NULL,
            content TEXT NOT NULL,
            parent_id INTEGER,
            block_id TEXT,
            "order" INTEGER DEFAULT 0,
            is_favorite INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
        )');
        if (!$result) {
            throw new Exception('Failed to create notes table: ' . $db->lastErrorMsg());
        }

        // Create indexes for notes table
        echo "Creating indexes for notes table...\n";
        $result = $db->exec('CREATE INDEX IF NOT EXISTS idx_notes_page_id ON notes(page_id);');
        if (!$result) {
            throw new Exception('Failed to create index idx_notes_page_id: ' . $db->lastErrorMsg());
        }

        $result = $db->exec('CREATE INDEX IF NOT EXISTS idx_notes_parent_id ON notes(parent_id);');
        if (!$result) {
            throw new Exception('Failed to create index idx_notes_parent_id: ' . $db->lastErrorMsg());
        }

        // Create attachments table
        echo "Creating attachments table...\n";
        $result = $db->exec('CREATE TABLE IF NOT EXISTS attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            note_id INTEGER,
            filename TEXT NOT NULL,
            original_name TEXT NOT NULL,
            file_path TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
        )');
        if (!$result) {
            throw new Exception('Failed to create attachments table: ' . $db->lastErrorMsg());
        }

        // Create properties table
        echo "Creating properties table...\n";
        $result = $db->exec('CREATE TABLE IF NOT EXISTS properties (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id TEXT,
            note_id INTEGER,
            property_key TEXT NOT NULL,
            property_value TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
            FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
        )');
        if (!$result) {
            throw new Exception('Failed to create properties table: ' . $db->lastErrorMsg());
        }

        // Create FTS5 table for notes search
        echo "Creating notes_fts (FTS5) table...\n";
        $result = $db->exec('CREATE VIRTUAL TABLE IF NOT EXISTS notes_fts USING fts5(
            note_id UNINDEXED, 
            content,
            tokenize = \'porter unicode61\'
        )');
        if (!$result) {
            // Note: FTS5 might not be enabled. The application should handle this gracefully if possible,
            // or this error will indicate a setup issue.
            throw new Exception('Failed to create notes_fts table. FTS5 module may not be enabled in SQLite: ' . $db->lastErrorMsg());
        }

        // Triggers to keep FTS table synchronized with notes table
        echo "Creating triggers for notes_fts synchronization...\n";
        $triggers = [
            "CREATE TRIGGER IF NOT EXISTS notes_ai AFTER INSERT ON notes BEGIN
                INSERT INTO notes_fts (note_id, content) VALUES (new.id, new.content);
            END;",
            "CREATE TRIGGER IF NOT EXISTS notes_ad AFTER DELETE ON notes BEGIN
                DELETE FROM notes_fts WHERE note_id = old.id;
            END;",
            "CREATE TRIGGER IF NOT EXISTS notes_au AFTER UPDATE OF content ON notes BEGIN
                UPDATE notes_fts SET content = new.content WHERE note_id = new.id;
            END;"
        ];

        foreach ($triggers as $triggerSql) {
            $result = $db->exec($triggerSql);
            if (!$result) {
                throw new Exception('Failed to create FTS trigger (' . substr($triggerSql, 20, 30) . '...): ' . $db->lastErrorMsg());
            }
        }
        
        // It's good practice to populate the FTS table if notes already exist.
        // However, init.php is for new databases, so notes table should be empty.
        // If this script were a migration tool, an initial population step would go here:
        // $db->exec('INSERT INTO notes_fts (note_id, content) SELECT id, content FROM notes;');


        // Create recent_pages table
        echo "Creating recent_pages table...\n";
        $result = $db->exec('CREATE TABLE IF NOT EXISTS recent_pages (
            page_id TEXT,
            last_opened DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
        )');
        if (!$result) {
            throw new Exception('Failed to create recent_pages table: ' . $db->lastErrorMsg());
        }

        // Create page_links table
        echo "Creating page_links table...\n";
        $result = $db->exec('CREATE TABLE IF NOT EXISTS page_links (
            source_page_id TEXT NOT NULL,
            target_page_id TEXT NOT NULL,
            source_note_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (source_page_id) REFERENCES pages(id) ON DELETE CASCADE,
            FOREIGN KEY (target_page_id) REFERENCES pages(id) ON DELETE CASCADE,
            FOREIGN KEY (source_note_id) REFERENCES notes(id) ON DELETE CASCADE,
            PRIMARY KEY (source_page_id, target_page_id, source_note_id)
        )');
        if (!$result) {
            throw new Exception('Failed to create page_links table: ' . $db->lastErrorMsg());
        }

        // Create indexes for page_links table
        echo "Creating indexes for page_links table...\n";
        $result = $db->exec('CREATE INDEX IF NOT EXISTS idx_page_links_target_page_id ON page_links(target_page_id);');
        if (!$result) {
            throw new Exception('Failed to create index idx_page_links_target_page_id: ' . $db->lastErrorMsg());
        }
        $result = $db->exec('CREATE INDEX IF NOT EXISTS idx_page_links_source_page_id ON page_links(source_page_id);');
        if (!$result) {
            throw new Exception('Failed to create index idx_page_links_source_page_id: ' . $db->lastErrorMsg());
        }
        $result = $db->exec('CREATE INDEX IF NOT EXISTS idx_page_links_source_note_id ON page_links(source_note_id);');
        if (!$result) {
            throw new Exception('Failed to create index idx_page_links_source_note_id: ' . $db->lastErrorMsg());
        }

        // Create user_settings table
        echo "Creating user_settings table...\n";
        $result = $db->exec('CREATE TABLE IF NOT EXISTS user_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT UNIQUE NOT NULL,
            setting_value TEXT
        )');
        if (!$result) {
            throw new Exception('Failed to create user_settings table: ' . $db->lastErrorMsg());
        }

        // Verify tables were created
        echo "Verifying tables...\n";
        $tables = ['pages', 'notes', 'attachments', 'properties', 'notes_fts', 'recent_pages', 'page_links', 'user_settings']; // Added notes_fts
        foreach ($tables as $table) {
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
            if (!$result || !$result->fetchArray()) {
                throw new Exception("Table '$table' was not created properly");
            }
        }

        $db->close();
        echo "Database initialized successfully!\n";
        echo json_encode(['success' => true, 'message' => 'Database initialized successfully!']);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// If the database exists, verify tables
try {
    $db = new SQLite3($dbPath);
    $tables = ['pages', 'notes', 'attachments', 'properties', 'notes_fts', 'recent_pages', 'page_links', 'user_settings']; // Added notes_fts
    $missing = [];
    
    foreach ($tables as $table) {
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if (!$result || !$result->fetchArray()) {
            $missing[] = $table;
        }
    }
    
    if (!empty($missing)) {
        echo "Missing tables: " . implode(', ', $missing) . "\n";
        echo "Please delete the database and try again.\n";
        echo json_encode(['error' => 'Database is missing required tables: ' . implode(', ', $missing)]);
    }
    
    $db->close();
} catch (Exception $e) {
    echo "Error verifying database: " . $e->getMessage() . "\n";
    echo json_encode(['error' => $e->getMessage()]);
}
?> 