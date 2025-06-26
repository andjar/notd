<?php
// Override database path for testing
$GLOBALS['DB_PATH_OVERRIDE_FOR_TESTING'] = getenv('DB_PATH') ?: __DIR__ . '/../db/test_database.sqlite';

// Include config.php which will use the override
require_once __DIR__ . '/../config.php';

// Initialize test database
if (file_exists(DB_PATH)) {
    unlink(DB_PATH);
}

// Create test database structure
$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->exec("CREATE TABLE Pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    content TEXT,
    alias TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    active BOOLEAN DEFAULT 1
)");

$pdo->exec("CREATE TABLE Notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL,
    parent_note_id INTEGER,
    content TEXT NOT NULL,
    order_index INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    active BOOLEAN DEFAULT 1,
    FOREIGN KEY (page_id) REFERENCES Pages(id),
    FOREIGN KEY (parent_note_id) REFERENCES Notes(id)
)");

$pdo->exec("CREATE TABLE Properties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER,
    note_id INTEGER,
    name TEXT NOT NULL,
    value TEXT NOT NULL,
    weight INTEGER DEFAULT 2,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    active BOOLEAN DEFAULT 1,
    FOREIGN KEY (page_id) REFERENCES Pages(id),
    FOREIGN KEY (note_id) REFERENCES Notes(id)
)");

$pdo->exec("CREATE TABLE Attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER NOT NULL,
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    mime_type TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    active BOOLEAN DEFAULT 1,
    FOREIGN KEY (note_id) REFERENCES Notes(id)
)");

// Seed test data
$pdo->exec("INSERT INTO Pages (name, content) VALUES ('Home', 'Welcome')");
$pageId = $pdo->lastInsertId();

$pdo->exec("INSERT INTO Notes (page_id, content) VALUES ($pageId, 'First note')");
$noteId = $pdo->lastInsertId();

$pdo->exec("INSERT INTO Properties (note_id, name, value, weight) VALUES ($noteId, 'status', 'TODO', 2)");
$pdo->exec("INSERT INTO Properties (note_id, name, value, weight) VALUES ($noteId, 'internal', 'secret', 3)");