<?php
// Debug which database the application is actually using
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Path Debug</h1>";

// Check what DB_PATH is set to
echo "<h2>1. DB_PATH Configuration</h2>";
require_once 'config.php';
echo "<p><strong>DB_PATH constant:</strong> " . DB_PATH . "</p>";
echo "<p><strong>DB_PATH exists:</strong> " . (file_exists(DB_PATH) ? "YES" : "NO") . "</p>";
echo "<p><strong>DB_PATH size:</strong> " . (file_exists(DB_PATH) ? filesize(DB_PATH) . " bytes" : "N/A") . "</p>";

// Test the actual database connection that the application uses
echo "<h2>2. Application Database Connection Test</h2>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    echo "<p style='color: green;'>✅ Application database connection successful</p>";
    
    // Check what database file we're actually connected to
    $stmt = $pdo->query("PRAGMA database_list");
    $databases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Connected Databases:</h3>";
    foreach ($databases as $db) {
        echo "<p><strong>Database:</strong> {$db['name']} - <strong>File:</strong> {$db['file']}</p>";
    }
    
    // Check the schema of the actual database
    echo "<h3>Actual Database Schema:</h3>";
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Tables:</strong> " . implode(', ', $tables) . "</p>";
    
    // Check Pages table schema
    if (in_array('Pages', $tables)) {
        echo "<h4>Pages Table Schema:</h4>";
        $stmt = $pdo->query("PRAGMA table_info(Pages)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Column</th><th>Type</th><th>Primary Key</th></tr>";
        
        foreach ($columns as $column) {
            $name = $column['name'];
            $type = $column['type'];
            $pk = $column['pk'];
            
            $rowColor = ($name === 'id' && $type !== 'TEXT') ? 'background-color: #ffcccc;' : '';
            
            echo "<tr style='$rowColor'>";
            echo "<td>$name</td>";
            echo "<td>$type</td>";
            echo "<td>" . ($pk ? 'YES' : 'NO') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Test UUID insertion in the actual database
        echo "<h4>UUID Insertion Test in Application Database:</h4>";
        $testUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        echo "<p><strong>Test UUID:</strong> $testUuid</p>";
        
        $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
        $result = $stmt->execute([$testUuid, 'Test Page', 'Testing UUID']);
        
        if ($result) {
            echo "<p style='color: green;'>✅ UUID insertion successful in application database!</p>";
            
            // Clean up
            $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = ?");
            $stmt->execute([$testUuid]);
            echo "<p style='color: green;'>✅ Test data cleaned up</p>";
            
        } else {
            echo "<p style='color: red;'>❌ UUID insertion failed in application database</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Pages table not found in application database!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Application database connection failed: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

echo "<h2>3. Summary</h2>";
echo "<p>This will show us if the application is using the same database we tested earlier.</p>";
?> 