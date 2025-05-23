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
            level INTEGER NOT NULL,
            parent_id INTEGER,
            block_id TEXT,
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

        // Verify tables were created
        echo "Verifying tables...\n";
        $tables = ['pages', 'notes', 'attachments', 'properties', 'recent_pages'];
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
    $tables = ['pages', 'notes', 'attachments', 'properties', 'recent_pages'];
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