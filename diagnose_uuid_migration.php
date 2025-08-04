<?php
require_once 'config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>UUID Migration Diagnostic Tool</h1>";

// Function to check database schema
function check_database_schema() {
    echo "<h2>1. Database Schema Check</h2>";
    
    try {
        echo "<p>Attempting to connect to database...</p>";
        $pdo = get_db_connection();
        echo "<p style='color: green;'>✅ Database connection successful</p>";
        
        // Check database file
        $dbPath = DB_PATH;
        echo "<p><strong>Database path:</strong> $dbPath</p>";
        echo "<p><strong>Database exists:</strong> " . (file_exists($dbPath) ? "YES" : "NO") . "</p>";
        echo "<p><strong>Database size:</strong> " . (file_exists($dbPath) ? filesize($dbPath) . " bytes" : "N/A") . "</p>";
        echo "<p><strong>Database readable:</strong> " . (is_readable($dbPath) ? "YES" : "NO") . "</p>";
        echo "<p><strong>Database writable:</strong> " . (is_writable($dbPath) ? "YES" : "NO") . "</p>";
        
        // Get all tables
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<p><strong>Tables found:</strong> " . implode(', ', $tables) . "</p>";
        
        // Check critical tables for ID column types
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
        
        // Check foreign key constraints
        echo "<h3>Foreign Key Constraints:</h3>";
        $stmt = $pdo->query("PRAGMA foreign_key_list");
        $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($foreignKeys)) {
            echo "<p style='color: orange;'>⚠️ No foreign key constraints found.</p>";
        } else {
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            echo "<tr><th>Table</th><th>From</th><th>To</th><th>On Delete</th></tr>";
            foreach ($foreignKeys as $fk) {
                echo "<tr>";
                echo "<td>{$fk['table']}</td>";
                echo "<td>{$fk['from']}</td>";
                echo "<td>{$fk['to']}</td>";
                echo "<td>{$fk['on_delete']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Database schema check failed: " . $e->getMessage() . "</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
        echo htmlspecialchars($e->getTraceAsString());
        echo "</pre>";
    }
}

// Function to test UUID insertion
function test_uuid_insertion() {
    echo "<h2>2. UUID Insertion Test</h2>";
    
    try {
        require_once 'api/uuid_utils.php';
        
        $pdo = get_db_connection();
        
        // Test page creation
        $testPageId = \App\UuidUtils::generateUuidV7();
        echo "<p><strong>Generated test page UUID:</strong> $testPageId</p>";
        
        $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
        $result = $stmt->execute([$testPageId, 'Schema Test Page', 'Testing UUID insertion']);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Successfully inserted page with UUID</p>";
            
            // Test note creation
            $testNoteId = \App\UuidUtils::generateUuidV7();
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
            } else {
                echo "<p style='color: red;'>❌ Failed to insert note with UUID</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Failed to insert page with UUID</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ UUID insertion test failed: " . $e->getMessage() . "</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
        echo htmlspecialchars($e->getTraceAsString());
        echo "</pre>";
    }
}

// Function to test API endpoints
function test_api_endpoints() {
    echo "<h2>3. API Endpoint Test</h2>";
    
    // Test batch operations endpoint
    echo "<h3>Testing Batch Operations API</h3>";
    
    $testData = [
        'operations' => [
            [
                'type' => 'create',
                'payload' => [
                    'page_id' => \App\UuidUtils::generateUuidV7(),
                    'content' => 'Test note from API',
                    'order_index' => 0
                ]
            ]
        ]
    ];
    
    $url = 'api/v1/batch_operations.php';
    $postData = http_build_query($testData);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $postData
        ]
    ]);
    
    $response = file_get_contents($url, false, $context);
    
    echo "<p><strong>API Response:</strong></p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($response);
    echo "</pre>";
}

// Function to check current database content
function check_database_content() {
    echo "<h2>4. Current Database Content</h2>";
    
    try {
        $pdo = get_db_connection();
        
        // Check Pages table
        echo "<h3>Pages Table:</h3>";
        $stmt = $pdo->query("SELECT id, name FROM Pages LIMIT 5");
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($pages)) {
            echo "<p>No pages found.</p>";
        } else {
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            echo "<tr><th>ID</th><th>Name</th></tr>";
            foreach ($pages as $page) {
                $idType = is_numeric($page['id']) ? 'INTEGER' : 'UUID';
                $rowColor = is_numeric($page['id']) ? 'background-color: #ffcccc;' : 'background-color: #ccffcc;';
                echo "<tr style='$rowColor'>";
                echo "<td>{$page['id']} ($idType)</td>";
                echo "<td>{$page['name']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Check Notes table
        echo "<h3>Notes Table:</h3>";
        $stmt = $pdo->query("SELECT id, page_id, content FROM Notes LIMIT 5");
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($notes)) {
            echo "<p>No notes found.</p>";
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
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Database content check failed: " . $e->getMessage() . "</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
        echo htmlspecialchars($e->getTraceAsString());
        echo "</pre>";
    }
}

// Run all diagnostics
check_database_schema();
test_uuid_insertion();
check_database_content();
test_api_endpoints();

echo "<h2>5. Recommendations</h2>";
echo "<p>Based on the diagnostic results above:</p>";
echo "<ul>";
echo "<li>If any ID columns show as INTEGER instead of TEXT, the database needs to be recreated</li>";
echo "<li>If foreign key constraints are missing, the schema is incomplete</li>";
echo "<li>If UUID insertion fails, there's a fundamental schema issue</li>";
echo "<li>If API tests fail, there may be backend logic issues</li>";
echo "</ul>";

echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>If the database schema is incorrect, run the database recreation script</li>";
echo "<li>If API tests fail, check the backend UUID handling logic</li>";
echo "<li>If everything looks correct but the frontend still fails, check the frontend-backend communication</li>";
echo "</ol>";
?> 