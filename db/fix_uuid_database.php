<?php
require_once __DIR__ . '/../config.php';

/**
 * Fix the database for UUID migration by recreating tables in the correct order.
 * This script will drop existing tables and recreate them with UUID support.
 */
function fix_database_for_uuid_migration() {
    try {
        echo "=== Fixing Database for UUID Migration ===\n";
        
        $pdo = get_db_connection();
        
        // Disable foreign key constraints temporarily
        $pdo->exec('PRAGMA foreign_keys = OFF');
        
        // Drop existing tables in reverse dependency order
        echo "Dropping existing tables...\n";
        $tablesToDrop = [
            'WebhookEvents',
            'Webhooks', 
            'Notes_fts',
            'Properties',
            'Attachments',
            'Notes',
            'Pages'
        ];
        
        foreach ($tablesToDrop as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS $table");
                echo "✅ Dropped table: $table\n";
            } catch (Exception $e) {
                echo "⚠️  Could not drop table $table: " . $e->getMessage() . "\n";
            }
        }
        
        // Drop triggers
        $triggersToDrop = [
            'Notes_after_insert',
            'Notes_after_delete', 
            'Notes_after_update',
            'update_pages_updated_at',
            'update_notes_updated_at',
            'update_properties_updated_at'
        ];
        
        foreach ($triggersToDrop as $trigger) {
            try {
                $pdo->exec("DROP TRIGGER IF EXISTS $trigger");
                echo "✅ Dropped trigger: $trigger\n";
            } catch (Exception $e) {
                echo "⚠️  Could not drop trigger $trigger: " . $e->getMessage() . "\n";
            }
        }
        
        // Re-enable foreign key constraints
        $pdo->exec('PRAGMA foreign_keys = ON');
        
        // Apply the new schema
        echo "\nApplying new UUID-based schema...\n";
        run_database_setup($pdo);
        
        echo "\n=== Database Fix Complete ===\n";
        echo "✅ Database has been successfully updated with UUID support.\n";
        
        // Test the database
        echo "\nTesting database...\n";
        test_database_after_fix($pdo);
        
    } catch (Exception $e) {
        echo "❌ Error during database fix: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

/**
 * Test the database after the fix
 */
function test_database_after_fix(PDO $pdo) {
    try {
        // Test 1: Check if tables exist
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "✅ Tables found: " . implode(', ', $tables) . "\n";
        
        // Test 2: Create a test page with UUID
        require_once __DIR__ . '/../api/uuid_utils.php';
        use App\UuidUtils;
        
        $testPageId = UuidUtils::generateUuidV7();
        $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
        $stmt->execute([$testPageId, 'Test Page', 'This is a test page']);
        echo "✅ Test page created with UUID: $testPageId\n";
        
        // Test 3: Create a test note with UUID
        $testNoteId = UuidUtils::generateUuidV7();
        $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
        $stmt->execute([$testNoteId, $testPageId, 'This is a test note']);
        echo "✅ Test note created with UUID: $testNoteId\n";
        
        // Test 4: Verify foreign key relationships work
        $stmt = $pdo->prepare("SELECT n.id, n.content, p.name FROM Notes n JOIN Pages p ON n.page_id = p.id WHERE n.id = ?");
        $stmt->execute([$testNoteId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            echo "✅ Foreign key relationship verified\n";
        } else {
            throw new Exception("Foreign key relationship test failed");
        }
        
        // Clean up test data
        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
        $stmt->execute([$testNoteId]);
        $stmt = $pdo->prepare("DELETE FROM Pages WHERE id = ?");
        $stmt->execute([$testPageId]);
        echo "✅ Test data cleaned up\n";
        
        echo "\n🎉 All tests passed! The database is ready for UUID-based operations.\n";
        
    } catch (Exception $e) {
        echo "❌ Database test failed: " . $e->getMessage() . "\n";
        throw $e;
    }
}

// Run the fix if this script is called directly
if (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    fix_database_for_uuid_migration();
} 