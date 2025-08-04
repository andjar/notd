<?php
// Debug FTS table structure
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug FTS Structure</h1>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check all FTS-related tables
    echo "<h2>All FTS Tables:</h2>";
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%fts%'");
    $ftsTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p><strong>FTS tables found:</strong> " . implode(', ', $ftsTables) . "</p>";
    
    foreach ($ftsTables as $tableName) {
        echo "<h3>Table: $tableName</h3>";
        $stmt = $pdo->query("PRAGMA table_info($tableName)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Column</th><th>Type</th><th>Primary Key</th><th>Not Null</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>{$column['name']}</td>";
            echo "<td>{$column['type']}</td>";
            echo "<td>" . ($column['pk'] ? 'YES' : 'NO') . "</td>";
            echo "<td>" . ($column['notnull'] ? 'YES' : 'NO') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check the actual FTS table creation SQL
    echo "<h2>FTS Table Creation SQL:</h2>";
    $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='Notes_fts'");
    $ftsSql = $stmt->fetchColumn();
    
    if ($ftsSql) {
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
        echo htmlspecialchars($ftsSql);
        echo "</pre>";
    } else {
        echo "<p>No SQL found for Notes_fts table</p>";
    }
    
    // Try to insert into FTS table directly to see the exact error
    echo "<h2>Direct FTS Insert Test:</h2>";
    
    $testUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    echo "<p><strong>Test UUID:</strong> $testUuid</p>";
    
    try {
        // Try inserting with explicit docid
        $stmt = $pdo->prepare("INSERT INTO Notes_fts(docid, content) VALUES (?, ?)");
        $result = $stmt->execute([$testUuid, 'Test content']);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Direct FTS insert with UUID successful</p>";
            
            // Clean up
            $stmt = $pdo->prepare("DELETE FROM Notes_fts WHERE docid = ?");
            $stmt->execute([$testUuid]);
            echo "<p style='color: green;'>✅ FTS test data cleaned up</p>";
        } else {
            echo "<p style='color: red;'>❌ Direct FTS insert failed</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Direct FTS insert error: " . $e->getMessage() . "</p>";
    }
    
    // Test with integer docid
    echo "<h3>Testing with Integer docid:</h3>";
    try {
        $stmt = $pdo->prepare("INSERT INTO Notes_fts(docid, content) VALUES (?, ?)");
        $result = $stmt->execute([123, 'Test content']);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Direct FTS insert with integer successful</p>";
            
            // Clean up
            $stmt = $pdo->prepare("DELETE FROM Notes_fts WHERE docid = ?");
            $stmt->execute([123]);
            echo "<p style='color: green;'>✅ FTS test data cleaned up</p>";
        } else {
            echo "<p style='color: red;'>❌ Direct FTS insert with integer failed</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Direct FTS insert with integer error: " . $e->getMessage() . "</p>";
    }
    
    // Check if there are any triggers that might be causing issues
    echo "<h2>All Triggers:</h2>";
    $stmt = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='trigger'");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p>No triggers found</p>";
    } else {
        foreach ($triggers as $trigger) {
            echo "<h3>Trigger: {$trigger['name']}</h3>";
            echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
            echo htmlspecialchars($trigger['sql']);
            echo "</pre>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 