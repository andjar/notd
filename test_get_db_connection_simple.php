<?php

// Test get_db_connection() without welcome notes setup
echo "<h1>Testing get_db_connection() (Simple)</h1>";

// Include the necessary files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db/setup_db_fixed.php';
require_once __DIR__ . '/api/db_helpers.php';

// Test the database connection directly
try {
    $db_path = DB_PATH;
    echo "<p>Database path: $db_path</p>";
    
    $pdo = new PDO('sqlite:' . $db_path, null, null, [
        'ATTR_ERRMODE' => PDO::ERRMODE_EXCEPTION, 
        'ATTR_DEFAULT_FETCH_MODE' => PDO::FETCH_ASSOC,
        'ATTR_TIMEOUT' => 30
    ]);
    echo "<p>PDO connection created successfully</p>";
    
    // Check if Pages table exists
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Pages'");
    if ($stmt->fetch() === false) {
        echo "<p>Pages table does not exist, running setup...</p>";
        run_database_setup_fixed($pdo);
        echo "<p>Database setup completed</p>";
    } else {
        echo "<p>Pages table exists</p>";
    }
    
    // Test a simple query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM Pages");
    $result = $stmt->fetch();
    echo "<p>Pages count: " . $result['count'] . "</p>";
    
    echo "<p>Database connection test successful!</p>";
    
} catch (Exception $e) {
    echo "<p>Database connection failed: " . $e->getMessage() . "</p>";
    echo "<p>Error trace: " . $e->getTraceAsString() . "</p>";
}

echo "<p>Test complete.</p>"; 