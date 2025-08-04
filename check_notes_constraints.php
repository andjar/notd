<?php
// Check foreign key constraints, triggers, and indexes on Notes table
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Notes Table Constraints Check</h1>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check foreign key constraints
    echo "<h2>Foreign Key Constraints:</h2>";
    $stmt = $pdo->query("PRAGMA foreign_key_list(Notes)");
    $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($foreignKeys)) {
        echo "<p>No foreign key constraints found on Notes table.</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>From Column</th><th>To Table</th><th>To Column</th><th>On Update</th><th>On Delete</th></tr>";
        foreach ($foreignKeys as $fk) {
            echo "<tr>";
            echo "<td>{$fk['from']}</td>";
            echo "<td>{$fk['table']}</td>";
            echo "<td>{$fk['to']}</td>";
            echo "<td>{$fk['on_update']}</td>";
            echo "<td>{$fk['on_delete']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check triggers on Notes table
    echo "<h2>Triggers on Notes Table:</h2>";
    $stmt = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='trigger' AND tbl_name='Notes'");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p>No triggers found on Notes table.</p>";
    } else {
        foreach ($triggers as $trigger) {
            echo "<h3>Trigger: {$trigger['name']}</h3>";
            echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
            echo htmlspecialchars($trigger['sql']);
            echo "</pre>";
        }
    }
    
    // Check indexes on Notes table
    echo "<h2>Indexes on Notes Table:</h2>";
    $stmt = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='Notes'");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($indexes)) {
        echo "<p>No custom indexes found on Notes table.</p>";
    } else {
        foreach ($indexes as $index) {
            echo "<h3>Index: {$index['name']}</h3>";
            echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
            echo htmlspecialchars($index['sql']);
            echo "</pre>";
        }
    }
    
    // Check if foreign keys are enabled
    echo "<h2>Foreign Key Settings:</h2>";
    $stmt = $pdo->query("PRAGMA foreign_keys");
    $fkEnabled = $stmt->fetchColumn();
    echo "<p><strong>Foreign keys enabled:</strong> " . ($fkEnabled ? 'YES' : 'NO') . "</p>";
    
    // Try to disable foreign keys and test insertion
    echo "<h2>Testing with Foreign Keys Disabled:</h2>";
    
    $pdo->exec("PRAGMA foreign_keys = OFF");
    echo "<p>Foreign keys disabled for testing.</p>";
    
    $testUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    echo "<p><strong>Test UUID:</strong> $testUuid</p>";
    
    // Get a page ID
    $stmt = $pdo->query("SELECT id FROM Pages LIMIT 1");
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($page) {
        $pageId = $page['id'];
        echo "<p><strong>Page ID:</strong> $pageId</p>";
        
        try {
            $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
            $result = $stmt->execute([$testUuid, $pageId, 'Test content']);
            
            if ($result) {
                echo "<p style='color: green;'>✅ Test note creation successful with foreign keys disabled!</p>";
                
                // Clean up
                $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
                $stmt->execute([$testUuid]);
                echo "<p style='color: green;'>✅ Test data cleaned up</p>";
                
                echo "<p style='color: red; font-weight: bold;'>🎯 FOUND THE ISSUE: Foreign key constraints are causing the datatype mismatch!</p>";
            } else {
                echo "<p style='color: red;'>❌ Test note creation still failed even with foreign keys disabled</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Test note creation error: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ No pages found to test with</p>";
    }
    
    // Re-enable foreign keys
    $pdo->exec("PRAGMA foreign_keys = ON");
    echo "<p>Foreign keys re-enabled.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 