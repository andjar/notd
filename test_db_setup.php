<?php

// Test database setup without any includes
echo "<h1>Testing Database Setup</h1>";

// Check if database file exists
$db_path = __DIR__ . '/db/database.sqlite';
echo "<p>Database path: $db_path</p>";
echo "<p>Database exists: " . (file_exists($db_path) ? 'YES' : 'NO') . "</p>";

// Try to create a simple PDO connection
try {
    $pdo = new PDO('sqlite:' . $db_path, null, null, [
        'ATTR_ERRMODE' => PDO::ERRMODE_EXCEPTION,
        'ATTR_DEFAULT_FETCH_MODE' => PDO::FETCH_ASSOC
    ]);
    echo "<p>PDO connection successful</p>";
    
    // Check if tables exist
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll();
    echo "<p>Tables found: " . count($tables) . "</p>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>" . $table['name'] . "</li>";
    }
    echo "</ul>";
    
    // Test if we can read the schema file
    $schema_path = __DIR__ . '/db/schema.sql';
    echo "<p>Schema path: $schema_path</p>";
    echo "<p>Schema exists: " . (file_exists($schema_path) ? 'YES' : 'NO') . "</p>";
    
    if (file_exists($schema_path)) {
        $schema_content = file_get_contents($schema_path);
        echo "<p>Schema content length: " . strlen($schema_content) . "</p>";
        
        // Try to execute a simple CREATE TABLE statement
        try {
            $pdo->exec("CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)");
            echo "<p>Test table creation successful</p>";
            
            // Check if the test table was created
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='test_table'");
            if ($stmt->fetch()) {
                echo "<p>Test table exists</p>";
                $pdo->exec("DROP TABLE test_table");
                echo "<p>Test table dropped</p>";
            } else {
                echo "<p>Test table was not created</p>";
            }
        } catch (Exception $e) {
            echo "<p>Test table creation failed: " . $e->getMessage() . "</p>";
        }
    }
    
    // Test the actual database setup function
    echo "<h2>Testing Database Setup Function</h2>";
    
    // Include the setup function
    require_once __DIR__ . '/db/setup_db_fixed.php';
    
    try {
        echo "<p>Calling run_database_setup_fixed...</p>";
        run_database_setup_fixed($pdo);
        echo "<p>Database setup completed successfully!</p>";
        
        // Check tables again
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll();
        echo "<p>Tables after setup: " . count($tables) . "</p>";
        echo "<ul>";
        foreach ($tables as $table) {
            echo "<li>" . $table['name'] . "</li>";
        }
        echo "</ul>";
        
    } catch (Exception $e) {
        echo "<p>Database setup failed: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p>PDO connection failed: " . $e->getMessage() . "</p>";
}

echo "<p>Test complete.</p>"; 