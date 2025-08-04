<?php
require_once __DIR__ . '/../config.php';

/**
 * Check the current database schema to verify UUID migration status
 */
function check_database_schema() {
    try {
        echo "=== Database Schema Check ===\n";
        
        $pdo = get_db_connection();
        
        // Check if database file exists
        $dbPath = DB_PATH;
        echo "Database path: $dbPath\n";
        echo "Database exists: " . (file_exists($dbPath) ? "YES" : "NO") . "\n";
        echo "Database size: " . (file_exists($dbPath) ? filesize($dbPath) . " bytes" : "N/A") . "\n\n";
        
        // Get all tables
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables found: " . implode(', ', $tables) . "\n\n";
        
        // Check schema for each table
        foreach ($tables as $table) {
            echo "=== Table: $table ===\n";
            
            // Get table schema
            $stmt = $pdo->query("PRAGMA table_info($table)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($columns as $column) {
                $name = $column['name'];
                $type = $column['type'];
                $notNull = $column['notnull'];
                $default = $column['dflt_value'];
                $pk = $column['pk'];
                
                echo "  $name: $type";
                if ($notNull) echo " NOT NULL";
                if ($default !== null) echo " DEFAULT $default";
                if ($pk) echo " PRIMARY KEY";
                echo "\n";
            }
            echo "\n";
        }
        
        // Check foreign key constraints
        echo "=== Foreign Key Constraints ===\n";
        $stmt = $pdo->query("PRAGMA foreign_key_list");
        $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($foreignKeys)) {
            echo "No foreign key constraints found.\n";
        } else {
            foreach ($foreignKeys as $fk) {
                echo "  {$fk['table']}.{$fk['from']} -> {$fk['table']}.{$fk['to']}\n";
            }
        }
        echo "\n";
        
        // Test UUID insertion
        echo "=== Testing UUID Insertion ===\n";
        try {
            require_once __DIR__ . '/../api/uuid_utils.php';
            // Note: use statements must be at the top level, not inside functions
            // We'll use the full class name instead
            
            $testPageId = \App\UuidUtils::generateUuidV7();
            echo "Generated test page UUID: $testPageId\n";
            
            $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
            $stmt->execute([$testPageId, 'Schema Test Page', 'Testing UUID insertion']);
            echo "✅ Successfully inserted page with UUID\n";
            
            $testNoteId = \App\UuidUtils::generateUuidV7();
            echo "Generated test note UUID: $testNoteId\n";
            
            $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
            $stmt->execute([$testNoteId, $testPageId, 'Testing UUID insertion']);
            echo "✅ Successfully inserted note with UUID\n";
            
            // Clean up
            $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
            $stmt->execute([$testNoteId]);
            $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = ?");
            $stmt->execute([$testPageId]);
            echo "✅ Test data cleaned up\n";
            
        } catch (Exception $e) {
            echo "❌ UUID insertion test failed: " . $e->getMessage() . "\n";
        }
        
        echo "\n=== Schema Check Complete ===\n";
        
    } catch (Exception $e) {
        echo "❌ Error during schema check: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}

// Run the check if this script is called directly
if (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    check_database_schema();
} 