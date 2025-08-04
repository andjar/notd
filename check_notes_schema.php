<?php
// Check the actual schema of the Notes table
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Notes Table Schema Check</h1>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check Notes table schema
    echo "<h2>Notes Table Schema:</h2>";
    $stmt = $pdo->query("PRAGMA table_info(Notes)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Primary Key</th><th>Not Null</th><th>Default</th></tr>";
    
    foreach ($columns as $column) {
        $name = $column['name'];
        $type = $column['type'];
        $pk = $column['pk'];
        $notNull = $column['notnull'];
        $default = $column['dflt_value'];
        
        $rowColor = '';
        if ($name === 'id' && $type !== 'TEXT') {
            $rowColor = 'background-color: #ffcccc;';
        } elseif ($name === 'page_id' && $type !== 'TEXT') {
            $rowColor = 'background-color: #ffcccc;';
        } elseif ($name === 'parent_note_id' && $type !== 'TEXT') {
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
    
    // Check if there are any existing notes
    echo "<h2>Existing Notes:</h2>";
    $stmt = $pdo->query("SELECT id, page_id, content FROM Notes LIMIT 5");
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($notes)) {
        echo "<p>No notes found in the database.</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Page ID</th><th>Content</th></tr>";
        foreach ($notes as $note) {
            $idType = is_numeric($note['id']) ? 'INTEGER' : 'UUID';
            $pageIdType = is_numeric($note['page_id']) ? 'INTEGER' : 'UUID';
            $rowColor = (is_numeric($note['id']) || is_numeric($note['page_id'])) ? 'background-color: #ffcccc;' : 'background-color: #ccffcc;';
            echo "<tr style='$rowColor'>";
            echo "<td>{$note['id']} ($idType)</td>";
            echo "<td>{$note['page_id']} ($pageIdType)</td>";
            echo "<td>" . substr($note['content'], 0, 50) . "...</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Try to create a test note to see the exact error
    echo "<h2>Test Note Creation:</h2>";
    
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
                echo "<p style='color: green;'>✅ Test note creation successful!</p>";
                
                // Clean up
                $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
                $stmt->execute([$testUuid]);
                echo "<p style='color: green;'>✅ Test data cleaned up</p>";
            } else {
                echo "<p style='color: red;'>❌ Test note creation failed</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Test note creation error: " . $e->getMessage() . "</p>";
        }
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