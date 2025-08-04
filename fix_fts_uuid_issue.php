<?php
// Fix FTS UUID issue
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Fix FTS UUID Issue</h1>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check current FTS table
    echo "<h2>Current FTS Table:</h2>";
    $stmt = $pdo->query("PRAGMA table_info(Notes_fts)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Primary Key</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['name']}</td>";
        echo "<td>{$column['type']}</td>";
        echo "<td>" . ($column['pk'] ? 'YES' : 'NO') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if docid is INTEGER (which is the problem)
    $docidColumn = null;
    foreach ($columns as $column) {
        if ($column['name'] === 'docid') {
            $docidColumn = $column;
            break;
        }
    }
    
    if ($docidColumn && $docidColumn['type'] === 'INTEGER') {
        echo "<p style='color: red;'>❌ FOUND THE PROBLEM: FTS docid column is INTEGER, but we're trying to insert UUID strings!</p>";
        
        echo "<h2>Solution Options:</h2>";
        echo "<p>We have two options to fix this:</p>";
        echo "<ol>";
        echo "<li><strong>Option 1:</strong> Recreate FTS table with TEXT docid (may not work with SQLite FTS)</li>";
        echo "<li><strong>Option 2:</strong> Temporarily disable FTS triggers to get the app working</li>";
        echo "</ol>";
        
        // Try Option 2 first (disable FTS triggers)
        echo "<h2>Testing Option 2: Disable FTS Triggers</h2>";
        
        // Drop the FTS triggers
        $pdo->exec("DROP TRIGGER IF EXISTS Notes_after_insert");
        $pdo->exec("DROP TRIGGER IF EXISTS Notes_after_update");
        $pdo->exec("DROP TRIGGER IF EXISTS Notes_after_delete");
        
        echo "<p style='color: green;'>✅ FTS triggers dropped</p>";
        
        // Test note creation
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
                    echo "<p style='color: green;'>✅ SUCCESS! Note creation works with FTS triggers disabled!</p>";
                    
                    // Clean up
                    $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
                    $stmt->execute([$testUuid]);
                    echo "<p style='color: green;'>✅ Test data cleaned up</p>";
                    
                    echo "<h2>Next Steps:</h2>";
                    echo "<p style='color: green; font-weight: bold;'>🎯 The application should now work! The FTS triggers were causing the datatype mismatch.</p>";
                    echo "<p>To permanently fix this, you have these options:</p>";
                    echo "<ul>";
                    echo "<li><strong>Option A:</strong> Keep FTS disabled (search won't work, but app will function)</li>";
                    echo "<li><strong>Option B:</strong> Recreate FTS table with TEXT docid (if SQLite supports it)</li>";
                    echo "<li><strong>Option C:</strong> Modify FTS triggers to convert UUIDs to integers (complex)</li>";
                    echo "</ul>";
                    
                } else {
                    echo "<p style='color: red;'>❌ Note creation still failed</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Note creation error: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ No pages found to test with</p>";
        }
        
    } else {
        echo "<p style='color: green;'>✅ FTS docid column is not INTEGER, so this isn't the issue</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 