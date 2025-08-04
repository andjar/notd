<?php

// Test database connection without welcome notes setup
echo "<h1>Testing Database Connection (Simple)</h1>";

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
    
    // Test if we can read the welcome_notes.json file
    $welcome_notes_path = __DIR__ . '/assets/template/page/welcome_notes.json';
    echo "<p>Welcome notes path: $welcome_notes_path</p>";
    echo "<p>Welcome notes exists: " . (file_exists($welcome_notes_path) ? 'YES' : 'NO') . "</p>";
    
    if (file_exists($welcome_notes_path)) {
        $notes_json = file_get_contents($welcome_notes_path);
        echo "<p>Welcome notes content length: " . strlen($notes_json) . "</p>";
        
        // Test JSON parsing
        $notes_data = json_decode($notes_json, true);
        echo "<p>JSON parsing successful: " . (is_array($notes_data) ? 'YES' : 'NO') . "</p>";
        if (is_array($notes_data)) {
            echo "<p>Number of welcome notes: " . count($notes_data) . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p>PDO connection failed: " . $e->getMessage() . "</p>";
}

echo "<p>Test complete.</p>"; 