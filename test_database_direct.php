<?php
// Standalone database test - no config.php included
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Direct Database Test</h1>";

try {
    // Test database path
    $dbPath = __DIR__ . '/db/database.sqlite';
    echo "<p><strong>Database path:</strong> $dbPath</p>";
    echo "<p><strong>Database exists:</strong> " . (file_exists($dbPath) ? "YES" : "NO") . "</p>";
    echo "<p><strong>Database size:</strong> " . (file_exists($dbPath) ? filesize($dbPath) . " bytes" : "N/A") . "</p>";
    
    if (!file_exists($dbPath)) {
        echo "<p style='color: red;'>❌ Database file does not exist!</p>";
        exit;
    }
    
    // Direct PDO connection
    echo "<p>Testing direct PDO connection...</p>";
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>✅ PDO connection successful</p>";
    
    // List all tables
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>All tables:</strong> " . implode(', ', $tables) . "</p>";
    
    // Check Pages table specifically
    if (in_array('Pages', $tables)) {
        echo "<h3>Pages Table Schema:</h3>";
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
        
        // Test UUID insertion
        echo "<h3>UUID Insertion Test:</h3>";
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
            echo "<p style='color: green;'>✅ UUID insertion successful!</p>";
            
            // Clean up
            $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = ?");
            $stmt->execute([$testUuid]);
            echo "<p style='color: green;'>✅ Test data cleaned up</p>";
            
            echo "<h3>Summary:</h3>";
            echo "<p style='color: green; font-weight: bold;'>✅ Database is working with UUIDs!</p>";
            echo "<p>The database schema supports UUIDs correctly.</p>";
            
        } else {
            echo "<p style='color: red;'>❌ UUID insertion failed</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Pages table not found!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 