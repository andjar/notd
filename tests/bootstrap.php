<?php
// Load composer autoloader if it exists, otherwise manually include required files
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // Manual includes for testing when autoloader is not available
    error_log("Loading classes manually for testing...");
    
    // Include dependencies first
    require_once __DIR__ . '/../api/db_connect.php';
    require_once __DIR__ . '/../api/response_utils.php';
    require_once __DIR__ . '/../api/validator_utils.php';
    
    // Include main classes
    require_once __DIR__ . '/../api/data_manager.php';
    error_log("DataManager file included");
    
    require_once __DIR__ . '/../api/pattern_processor.php';
    error_log("PatternProcessor file included");
    
    require_once __DIR__ . '/../api/property_trigger_service.php';
    error_log("PropertyTriggerService file included");
    
    require_once __DIR__ . '/../api/template_processor.php';
    error_log("TemplateProcessor file included");
    
    require_once __DIR__ . '/../api/v1/webhooks.php';
    error_log("WebhooksManager file included");
    
    require_once __DIR__ . '/../api/v1/attachments.php';
    error_log("AttachmentManager file included");
    
    // Verify classes exist
    error_log("Checking if classes exist:");
    error_log("DataManager exists: " . (class_exists('App\DataManager') ? 'YES' : 'NO'));
    error_log("PatternProcessor exists: " . (class_exists('App\PatternProcessor') ? 'YES' : 'NO'));
    error_log("PropertyTriggerService exists: " . (class_exists('App\PropertyTriggerService') ? 'YES' : 'NO'));
    error_log("TemplateProcessor exists: " . (class_exists('App\TemplateProcessor') ? 'YES' : 'NO'));
    error_log("WebhooksManager exists: " . (class_exists('App\WebhooksManager') ? 'YES' : 'NO'));
    error_log("AttachmentManager exists: " . (class_exists('App\AttachmentManager') ? 'YES' : 'NO'));
    
    error_log("Manual class loading completed");
}

// Override database path for testing
$GLOBALS['DB_PATH_OVERRIDE_FOR_TESTING'] = getenv('DB_PATH') ?: __DIR__ . '/../db/test_database.sqlite';

// Include config.php which will use the override
require_once __DIR__ . '/../config.php';

// Set error reporting to catch any issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize test database
// Ensure the directory exists
$dbDir = dirname(DB_PATH);
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

if (file_exists(DB_PATH)) {
    unlink(DB_PATH);
}

// Create test database structure using the proper schema
try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Failed to create test database: " . $e->getMessage());
    throw $e;
}

// Load and execute the schema
$schemaPath = __DIR__ . '/../db/schema.sql';
if (file_exists($schemaPath)) {
    $schema = file_get_contents($schemaPath);
    $pdo->exec($schema);
} else {
    // Fallback to basic schema if schema.sql doesn't exist
    $pdo->exec("CREATE TABLE Pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        content TEXT,
        alias TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE Notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_id INTEGER NOT NULL,
        parent_note_id INTEGER,
        content TEXT,
        internal INTEGER NOT NULL DEFAULT 0,
        order_index INTEGER NOT NULL DEFAULT 0,
        collapsed INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE,
        FOREIGN KEY (parent_note_id) REFERENCES Notes(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE Properties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        note_id INTEGER,
        page_id INTEGER,
        name TEXT NOT NULL,
        value TEXT,
        weight INTEGER NOT NULL DEFAULT 2,
        active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE CASCADE,
        FOREIGN KEY (page_id) REFERENCES Pages(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE Attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        note_id INTEGER,
        name TEXT NOT NULL,
        path TEXT NOT NULL UNIQUE,
        type TEXT,
        size INTEGER,
        active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE SET NULL
    )");
}

// Seed test data
$pdo->exec("INSERT INTO Pages (name, content) VALUES ('Home', 'Welcome')");
$pageId = $pdo->lastInsertId();

$pdo->exec("INSERT INTO Notes (page_id, content) VALUES ($pageId, 'First note')");
$noteId = $pdo->lastInsertId();

$pdo->exec("INSERT INTO Properties (note_id, name, value, weight) VALUES ($noteId, 'status', 'TODO', 2)");
$pdo->exec("INSERT INTO Properties (note_id, name, value, weight) VALUES ($noteId, 'internal', 'secret', 3)");