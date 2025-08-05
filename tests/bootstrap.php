<?php
// Override database path for testing (must be set before config.php is included)
$GLOBALS['DB_PATH_OVERRIDE_FOR_TESTING'] = getenv('DB_PATH') ?: __DIR__ . '/../db/test_database.sqlite';

// Always include config.php first (needed for DB_PATH constant)
require_once __DIR__ . '/../config.php';

// Set error reporting to catch any issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load composer autoloader if it exists, otherwise manually include required files
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    error_log("Composer autoloader loaded successfully");
} else {
    // Manual includes for testing when autoloader is not available
    error_log("Composer autoloader not found. Loading classes manually for testing...");
    
    // Include dependencies first (in correct order to avoid circular dependencies)
    // Don't include config.php again as it's already included above
    require_once __DIR__ . '/../api/response_utils.php';
    require_once __DIR__ . '/../api/validator_utils.php';
    require_once __DIR__ . '/../api/db_helpers.php';
    require_once __DIR__ . '/../api/UuidUtils.php';
    require_once __DIR__ . '/../db/setup_db.php';
    require_once __DIR__ . '/../api/db_connect.php';
    
    // Include classes that are actually used by tests
    // Order is important - include classes with no dependencies first
    require_once __DIR__ . '/../api/v1/WebhooksManager.php';
    error_log("WebhooksManager file included");
    
    require_once __DIR__ . '/../api/PropertyTriggerService.php';
    error_log("PropertyTriggerService file included");
    
    require_once __DIR__ . '/../api/PatternProcessor.php';
    error_log("PatternProcessor file included");
    
    require_once __DIR__ . '/../api/DataManager.php';
    error_log("DataManager file included");
    
    // Verify classes exist after manual loading
    error_log("Checking if classes exist after manual loading:");
    error_log("WebhooksManager exists: " . (class_exists('App\\WebhooksManager') ? 'YES' : 'NO'));
    error_log("PropertyTriggerService exists: " . (class_exists('App\\PropertyTriggerService') ? 'YES' : 'NO'));
    error_log("PatternProcessor exists: " . (class_exists('App\\PatternProcessor') ? 'YES' : 'NO'));
    error_log("DataManager exists: " . (class_exists('App\\DataManager') ? 'YES' : 'NO'));
    
    // If classes still don't exist, it's a more serious issue
    if (!class_exists('App\\DataManager')) {
        error_log("CRITICAL: DataManager class not found even after manual includes!");
        error_log("Trying to debug by checking if the file exists...");
        error_log("DataManager file exists: " . (file_exists(__DIR__ . '/../api/DataManager.php') ? 'YES' : 'NO'));
        
        // Try to get more info about what's wrong
        if (file_exists(__DIR__ . '/../api/DataManager.php')) {
            error_log("File exists but class not found - possible syntax error in file");
        }
    }
    
    error_log("Manual class loading completed");
}

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
    error_log("Test database created successfully at: " . DB_PATH);
} catch (PDOException $e) {
    error_log("Failed to create test database: " . $e->getMessage());
    throw $e;
}

// Load and execute the schema
$schemaPath = __DIR__ . '/../db/schema.sql';
if (file_exists($schemaPath)) {
    $schema = file_get_contents($schemaPath);
    $pdo->exec($schema);
    error_log("Schema loaded from schema.sql");
} else {
    error_log("Schema.sql not found, using fallback schema");
    // Fallback to basic schema if schema.sql doesn't exist
    $pdo->exec("CREATE TABLE Pages (
        id TEXT PRIMARY KEY, -- UUID v7 format
        name TEXT UNIQUE NOT NULL,
        content TEXT,
        alias TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE Notes (
        id TEXT PRIMARY KEY, -- UUID v7 format
        rowid INTEGER UNIQUE, -- Integer ID for FTS compatibility
        page_id TEXT NOT NULL, -- UUID v7 format
        parent_note_id TEXT, -- UUID v7 format
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
        id TEXT PRIMARY KEY, -- UUID v7 format
        note_id TEXT, -- UUID v7 format
        page_id TEXT, -- UUID v7 format
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
        id TEXT PRIMARY KEY, -- UUID v7 format
        note_id TEXT, -- UUID v7 format
        name TEXT NOT NULL,
        path TEXT NOT NULL UNIQUE,
        type TEXT,
        size INTEGER,
        active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (note_id) REFERENCES Notes(id) ON DELETE SET NULL
    )");

    // Add missing tables for webhook functionality
    $pdo->exec("CREATE TABLE IF NOT EXISTS Webhooks (
        id TEXT PRIMARY KEY, -- UUID v7 format
        url TEXT NOT NULL,
        entity_type TEXT NOT NULL,
        property_names TEXT NOT NULL,
        event_types TEXT DEFAULT '[\"property_change\"]',
        secret TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        verified INTEGER NOT NULL DEFAULT 0,
        last_verified DATETIME,
        last_triggered DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS WebhookEvents (
        id TEXT PRIMARY KEY, -- UUID v7 format
        webhook_id TEXT NOT NULL, -- UUID v7 format
        event_type TEXT NOT NULL,
        payload TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        response_code INTEGER,
        response_body TEXT,
        attempts INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (webhook_id) REFERENCES Webhooks(id) ON DELETE CASCADE
    )");
    
    error_log("Created fallback schema with all required tables");
}

// Seed test data with UUIDs
$homePageId = \App\UuidUtils::generateUuidV7();
$pdo->exec("INSERT INTO Pages (id, name, content) VALUES ('$homePageId', 'Home', 'Welcome')");

$firstNoteId = \App\UuidUtils::generateUuidV7();
$pdo->exec("INSERT INTO Notes (id, page_id, content) VALUES ('$firstNoteId', '$homePageId', 'First note')");

$statusPropId = \App\UuidUtils::generateUuidV7();
$internalPropId = \App\UuidUtils::generateUuidV7();
$pdo->exec("INSERT INTO Properties (id, note_id, name, value, weight) VALUES ('$statusPropId', '$firstNoteId', 'status', 'TODO', 2)");
$pdo->exec("INSERT INTO Properties (id, note_id, name, value, weight) VALUES ('$internalPropId', '$firstNoteId', 'internal', 'secret', 3)");

error_log("Test data seeded successfully");