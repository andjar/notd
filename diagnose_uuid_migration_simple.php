<?php
// Disable custom error handlers for this diagnostic script
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>UUID Migration Diagnostic Tool (Simple Version)</h1>";

// Test basic database connection without custom error handlers
echo "<h2>1. Basic Database Connection Test</h2>";

try {
    // Define DB_PATH directly to avoid config issues
    $dbPath = __DIR__ . '/db/database.sqlite';
    echo "<p><strong>Database path:</strong> $dbPath</p>";
    echo "<p><strong>Database exists:</strong> " . (file_exists($dbPath) ? "YES" : "NO") . "</p>";
    echo "<p><strong>Database size:</strong> " . (file_exists($dbPath) ? filesize($dbPath) . " bytes" : "N/A") . "</p>";
    
    if (!file_exists($dbPath)) {
        echo "<p style='color: red;'>❌ Database file does not exist!</p>";
        echo "<p>This means the database recreation failed or the file was not created.</p>";
        echo "<p><a href='force_recreate_database_web.php'>Click here to recreate the database</a></p>";
        exit;
    }
    
    // Try direct PDO connection
    echo "<p>Attempting direct PDO connection...</p>";
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>✅ Direct PDO connection successful</p>";
    
    // Check if tables exist
    echo "<h2>2. Database Schema Check</h2>";
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Tables found:</strong> " . implode(', ', $tables) . "</p>";
    
    if (empty($tables)) {
        echo "<p style='color: red;'>❌ No tables found in database!</p>";
        echo "<p>This indicates the schema was not applied correctly.</p>";
        echo "<p><a href='force_recreate_database_web.php'>Click here to recreate the database</a></p>";
        exit;
    }
    
    // Check critical tables
    $criticalTables = ['Pages', 'Notes', 'Attachments', 'Properties'];
    echo "<h3>Critical Table Schema:</h3>";
    
    foreach ($criticalTables as $table) {
        if (in_array($table, $tables)) {
            echo "<h4>Table: $table</h4>";
            $stmt = $pdo->query("PRAGMA table_info($table)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            echo "<tr><th>Column</th><th>Type</th><th>Primary Key</th><th>Not Null</th></tr>";
            
            foreach ($columns as $column) {
                $name = $column['name'];
                $type = $column['type'];
                $pk = $column['pk'];
                $notNull = $column['notnull'];
                
                $rowColor = ($name === 'id' && $type !== 'TEXT') ? 'background-color: #ffcccc;' : '';
                
                echo "<tr style='$rowColor'>";
                echo "<td>$name</td>";
                echo "<td>$type</td>";
                echo "<td>" . ($pk ? 'YES' : 'NO') . "</td>";
                echo "<td>" . ($notNull ? 'YES' : 'NO') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: red;'>❌ Table '$table' not found!</p>";
        }
    }
    
    // Test UUID insertion
    echo "<h2>3. UUID Insertion Test</h2>";
    
    // Simple UUID generation for testing
    function generateSimpleUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    $testPageId = generateSimpleUuid();
    echo "<p><strong>Generated test page UUID:</strong> $testPageId</p>";
    
    $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
    $result = $stmt->execute([$testPageId, 'Schema Test Page', 'Testing UUID insertion']);
    
    if ($result) {
        echo "<p style='color: green;'>✅ Successfully inserted page with UUID</p>";
        
        // Test note creation
        $testNoteId = generateSimpleUuid();
        echo "<p><strong>Generated test note UUID:</strong> $testNoteId</p>";
        
        $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
        $result = $stmt->execute([$testNoteId, $testPageId, 'Testing UUID insertion']);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Successfully inserted note with UUID</p>";
            
            // Clean up
            $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
            $stmt->execute([$testNoteId]);
            $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = ?");
            $stmt->execute([$testPageId]);
            echo "<p style='color: green;'>✅ Test data cleaned up</p>";
            
            echo "<h2>4. Summary</h2>";
            echo "<p style='color: green; font-weight: bold;'>✅ Database is working correctly with UUIDs!</p>";
            echo "<p>The database schema has been successfully updated to support UUIDs.</p>";
            echo "<p><a href='index.php'>Click here to test the application</a></p>";
            
        } else {
            echo "<p style='color: red;'>❌ Failed to insert note with UUID</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Failed to insert page with UUID</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 