<?php
require_once 'config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Force Database Recreation for UUID Migration</h1>";

function force_recreate_database() {
    try {
        echo "<h2>Step 1: Database Information</h2>";
        
        $dbPath = DB_PATH;
        echo "<p><strong>Database path:</strong> $dbPath</p>";
        
        // Check if database file exists
        if (file_exists($dbPath)) {
            echo "<p><strong>Current database size:</strong> " . filesize($dbPath) . " bytes</p>";
            
            // Create backup
            $backupPath = $dbPath . '.backup.' . date('Y-m-d_H-i-s');
            if (copy($dbPath, $backupPath)) {
                echo "<p style='color: green;'>✅ Backup created: $backupPath</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Failed to create backup</p>";
            }
            
            // Delete the database file
            if (unlink($dbPath)) {
                echo "<p style='color: green;'>✅ Database file deleted successfully</p>";
            } else {
                echo "<p style='color: red;'>❌ Failed to delete database file</p>";
                return false;
            }
        } else {
            echo "<p>No existing database found.</p>";
        }
        
        echo "<h2>Step 2: Creating New Database</h2>";
        
        // Create new PDO connection (this will create the database file)
        $pdo = new PDO("sqlite:$dbPath");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "<p style='color: green;'>✅ New database file created</p>";
        
        echo "<h2>Step 3: Applying UUID Schema</h2>";
        
        // Read and execute the schema
        $schemaFile = __DIR__ . '/db/schema.sql';
        if (!file_exists($schemaFile)) {
            echo "<p style='color: red;'>❌ Schema file not found: $schemaFile</p>";
            return false;
        }
        
        $schema = file_get_contents($schemaFile);
        echo "<p><strong>Schema file size:</strong> " . strlen($schema) . " bytes</p>";
        
        // Parse schema into individual statements properly
        $statements = [];
        $currentStatement = '';
        $inTrigger = false;
        $lines = explode("\n", $schema);
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Skip comments and empty lines
            if (empty($trimmedLine) || preg_match('/^--/', $trimmedLine)) {
                continue;
            }
            
            $currentStatement .= $trimmedLine . "\n";
            
            // Check if we're in a trigger block
            if (stripos($trimmedLine, 'CREATE TRIGGER') !== false) {
                $inTrigger = true;
            }
            
            // End of statement detection
            if (!$inTrigger && substr($trimmedLine, -1) === ';') {
                $statements[] = trim($currentStatement);
                $currentStatement = '';
            } elseif ($inTrigger && stripos($trimmedLine, 'END') !== false && substr($trimmedLine, -1) === ';') {
                $statements[] = trim($currentStatement);
                $currentStatement = '';
                $inTrigger = false;
            }
        }
        
        // Add any remaining statement
        if (!empty(trim($currentStatement))) {
            $statements[] = trim($currentStatement);
        }
        
        // Filter out empty statements
        $statements = array_filter($statements, function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        });
        
        echo "<p><strong>Executing schema statements:</strong></p>";
        $successCount = 0;
        $totalCount = count($statements);
        
        echo "<p><strong>Total statements to execute:</strong> $totalCount</p>";
        
        foreach ($statements as $i => $statement) {
            echo "<details style='margin: 10px 0;'>";
            echo "<summary style='cursor: pointer;'>Statement " . ($i + 1) . "/$totalCount</summary>";
            echo "<pre style='background: #f5f5f5; padding: 5px; margin: 5px; font-size: 12px;'>" . htmlspecialchars($statement) . "</pre>";
            echo "</details>";
            
            try {
                $pdo->exec($statement);
                $successCount++;
                echo "<p style='color: green;'>✅ Statement " . ($i + 1) . "/$totalCount executed successfully</p>";
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Statement " . ($i + 1) . "/$totalCount failed: " . $e->getMessage() . "</p>";
            }
        }
        
        echo "<p><strong>Schema execution:</strong> $successCount/$totalCount statements successful</p>";
        
        if ($successCount < $totalCount) {
            echo "<p style='color: red;'>❌ Some schema statements failed. Database may be incomplete.</p>";
            return false;
        }
        
        echo "<h2>Step 4: Verifying Schema</h2>";
        
        // Verify the schema was applied correctly
        $stmt = $pdo->query("PRAGMA table_info(Pages)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $idColumn = null;
        foreach ($columns as $column) {
            if ($column['name'] === 'id') {
                $idColumn = $column;
                break;
            }
        }
        
        if ($idColumn && $idColumn['type'] === 'TEXT') {
            echo "<p style='color: green;'>✅ Pages.id column is correctly TEXT</p>";
        } else {
            echo "<p style='color: red;'>❌ Pages.id column is not TEXT: " . ($idColumn ? $idColumn['type'] : 'NOT FOUND') . "</p>";
            return false;
        }
        
        // Test UUID insertion
        echo "<h2>Step 5: Testing UUID Insertion</h2>";
        
        require_once 'api/uuid_utils.php';
        
        $testPageId = \App\UuidUtils::generateUuidV7();
        echo "<p><strong>Generated test page UUID:</strong> $testPageId</p>";
        
        $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
        $result = $stmt->execute([$testPageId, 'Test Page', 'Testing UUID insertion']);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Successfully inserted page with UUID</p>";
            
            // Clean up
            $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = ?");
            $stmt->execute([$testPageId]);
            echo "<p style='color: green;'>✅ Test data cleaned up</p>";
            
            echo "<h2>Step 6: Database Recreation Complete</h2>";
            echo "<p style='color: green; font-weight: bold;'>✅ Database has been successfully recreated with UUID schema!</p>";
            echo "<p>The application should now work correctly with UUIDs.</p>";
            echo "<p><a href='index.php'>Click here to test the application</a></p>";
            
            return true;
        } else {
            echo "<p style='color: red;'>❌ Failed to insert test page with UUID</p>";
            return false;
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Error during database recreation: " . $e->getMessage() . "</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
        echo htmlspecialchars($e->getTraceAsString());
        echo "</pre>";
        return false;
    }
}

// Check if user wants to proceed
if (isset($_POST['confirm_recreation'])) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
    echo "<h3>⚠️ WARNING: This will completely delete your current database!</h3>";
    echo "<p>This action will:</p>";
    echo "<ul>";
    echo "<li>Create a backup of your current database</li>";
    echo "<li>Delete the current database file</li>";
    echo "<li>Create a new database with UUID schema</li>";
    echo "<li>All existing data will be lost!</li>";
    echo "</ul>";
    echo "<p>Are you sure you want to proceed?</p>";
    echo "<form method='post'>";
    echo "<input type='submit' name='proceed' value='Yes, Recreate Database' style='background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>";
    echo "<a href='diagnose_uuid_migration.php' style='margin-left: 10px; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px;'>Cancel</a>";
    echo "</form>";
    echo "</div>";
} elseif (isset($_POST['proceed'])) {
    $success = force_recreate_database();
    if ($success) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
        echo "<h3>✅ Database Recreation Successful!</h3>";
        echo "<p>The database has been recreated with the UUID schema. You can now test the application.</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
        echo "<h3>❌ Database Recreation Failed!</h3>";
        echo "<p>There was an error during the database recreation process. Please check the error messages above.</p>";
        echo "</div>";
    }
} else {
    echo "<div style='background: #e2e3e5; border: 1px solid #d6d8db; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
    echo "<h3>Database Recreation Tool</h3>";
    echo "<p>This tool will completely recreate your database with the UUID schema.</p>";
    echo "<p><strong>Current database path:</strong> " . DB_PATH . "</p>";
    echo "<form method='post'>";
    echo "<input type='submit' name='confirm_recreation' value='Start Database Recreation' style='background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>";
    echo "</form>";
    echo "</div>";
}
?> 