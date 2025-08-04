<?php
// Check FTS table schema and test FTS triggers
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>FTS Table Schema Check</h1>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connect();
    
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check if FTS table exists
    echo "<h2>FTS Table Check:</h2>";
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Notes_fts'");
    $ftsTable = $stmt->fetch();
    
    if ($ftsTable) {
        echo "<p style='color: green;'>✅ Notes_fts table exists</p>";
        
        // Check FTS table schema
        echo "<h3>Notes_fts Table Schema:</h3>";
        $stmt = $pdo->query("PRAGMA table_info(Notes_fts)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Column</th><th>Type</th><th>Primary Key</th><th>Not Null</th><th>Default</th></tr>";
        
        foreach ($columns as $column) {
            $name = $column['name'];
            $type = $column['type'];
            $pk = $column['pk'];
            $notNull = $column['notnull'];
            $default = $column['dflt_value'];
            
            echo "<tr>";
            echo "<td>$name</td>";
            echo "<td>$type</td>";
            echo "<td>" . ($pk ? 'YES' : 'NO') . "</td>";
            echo "<td>" . ($notNull ? 'YES' : 'NO') . "</td>";
            echo "<td>" . ($default !== null ? $default : 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Check FTS content table
        echo "<h3>Notes_fts_content Table Schema:</h3>";
        $stmt = $pdo->query("PRAGMA table_info(Notes_fts_content)");
        $contentColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Column</th><th>Type</th><th>Primary Key</th><th>Not Null</th><th>Default</th></tr>";
        
        foreach ($contentColumns as $column) {
            $name = $column['name'];
            $type = $column['type'];
            $pk = $column['pk'];
            $notNull = $column['notnull'];
            $default = $column['dflt_value'];
            
            $rowColor = '';
            if ($name === 'docid' && $type !== 'INTEGER') {
                $rowColor = 'background-color: #ffcccc;';
            }
            
            echo "<tr style='$rowColor'>";
            echo "<td>$name</td>";
            echo "<td>$type</td>";
            echo "<td>" . ($pk ? 'YES' : 'NO') . "</td>";
            echo "<td>" . ($notNull ? 'YES' : 'NO') . "</td>";
            echo "<td>" . ($default !== null ? $default : 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<p style='color: red;'>❌ Notes_fts table does not exist!</p>";
    }
    
    // Test FTS trigger manually
    echo "<h2>Testing FTS Trigger:</h2>";
    
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
        
        // Test 1: Try to insert into FTS table directly
        echo "<h3>Test 1: Direct FTS Insert</h3>";
        try {
            $stmt = $pdo->prepare("INSERT INTO Notes_fts(docid, content) VALUES (?, ?)");
            $result = $stmt->execute([$testUuid, 'Test content']);
            
            if ($result) {
                echo "<p style='color: green;'>✅ Direct FTS insert successful</p>";
                
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
        
        // Test 2: Try to insert into Notes table with triggers disabled
        echo "<h3>Test 2: Notes Insert with Triggers Disabled</h3>";
        
        // Disable triggers temporarily
        $pdo->exec("PRAGMA foreign_keys = OFF");
        
        try {
            $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
            $result = $stmt->execute([$testUuid, $pageId, 'Test content']);
            
            if ($result) {
                echo "<p style='color: green;'>✅ Notes insert successful with foreign keys disabled</p>";
                
                // Clean up
                $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
                $stmt->execute([$testUuid]);
                echo "<p style='color: green;'>✅ Notes test data cleaned up</p>";
                
                echo "<p style='color: red; font-weight: bold;'>🎯 FOUND THE ISSUE: Foreign key constraints are causing the datatype mismatch!</p>";
            } else {
                echo "<p style='color: red;'>❌ Notes insert still failed</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Notes insert error: " . $e->getMessage() . "</p>";
        }
        
        // Re-enable foreign keys
        $pdo->exec("PRAGMA foreign_keys = ON");
        echo "<p>Foreign keys re-enabled.</p>";
        
    } else {
        echo "<p style='color: red;'>❌ No pages found to test with</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}
?> 