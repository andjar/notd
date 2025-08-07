<?php
if (!function_exists('log_db_error')) {
    function log_db_error($message, $context = []) {
        // Disabled to prevent HTML output
    }
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/setup_db_fixed.php';
require_once __DIR__ . '/db_helpers.php'; // Moved helpers to separate file

function get_db_connection() {
    // Remove static connection to avoid locking issues with concurrent requests
    // Each request gets its own connection
    $pdo = null;

    try {
        $db_path = DB_PATH;
        $db_dir = dirname($db_path);
        if (!is_dir($db_dir) && !mkdir($db_dir, 0777, true)) {
            throw new Exception("Failed to create database directory: $db_dir");
        }
        
        $pdo = new PDO('sqlite:' . $db_path, null, null, [
            'ATTR_ERRMODE' => PDO::ERRMODE_EXCEPTION, 
            'ATTR_DEFAULT_FETCH_MODE' => PDO::FETCH_ASSOC,
            'ATTR_TIMEOUT' => 30,
            'ATTR_PERSISTENT' => false // Ensure fresh connections
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 15000;');        // Increased timeout to 15 seconds for SQLite's internal retry
        $pdo->exec('PRAGMA journal_mode = WAL;');         // WAL mode for better concurrency
        $pdo->exec('PRAGMA synchronous = NORMAL;');       // Faster writes
        $pdo->exec('PRAGMA cache_size = 10000;');         // Larger cache
        $pdo->exec('PRAGMA temp_store = MEMORY;');        // Use memory for temp storage

        // Check if Pages table exists and run setup if needed
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Pages'");
        if ($stmt->fetch() === false) {
            log_db_error("Running database setup...");
            run_database_setup_fixed($pdo);
            log_db_error("Database setup completed.");
        }
        
        return $pdo;
    } catch (Exception $e) {
        log_db_error("CRITICAL DATABASE SETUP FAILED: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'A critical error occurred during database setup.', 'details' => $e->getMessage()]);
        exit;
    }
}

function close_db_connection($pdo) {
    if ($pdo) {
        $pdo = null;
    }
}