<?php
require_once __DIR__ . '/../config.php';

/**
 * Completely recreates the database for UUID migration.
 * This will DELETE ALL EXISTING DATA and create a fresh database.
 * 
 * WARNING: This is a destructive operation that will delete all data!
 */
function recreate_database_for_uuid_migration() {
    try {
        echo "=== Database Recreation for UUID Migration ===\n";
        echo "WARNING: This will delete ALL existing data!\n\n";
        
        // Get database path from config
        $dbPath = DB_PATH;
        echo "Database path: $dbPath\n";
        
        // Check if database file exists
        if (file_exists($dbPath)) {
            echo "Existing database found. Backing up...\n";
            $backupPath = $dbPath . '.backup.' . date('Y-m-d_H-i-s');
            if (!copy($dbPath, $backupPath)) {
                throw new Exception("Failed to create backup of existing database");
            }
            echo "Backup created: $backupPath\n";
            
            // Delete the existing database file
            if (!unlink($dbPath)) {
                throw new Exception("Failed to delete existing database file");
            }
            echo "Existing database deleted.\n";
        }
        
        // Create new database connection (this will create the file)
        echo "Creating new database...\n";
        $pdo = get_db_connection();
        
        // Apply the new schema
        echo "Applying new UUID-based schema...\n";
        run_database_setup($pdo);
        
        echo "\n=== Database Recreation Complete ===\n";
        echo "✅ Database has been successfully recreated with UUID support.\n";
        echo "📁 Backup of old database: " . ($backupPath ?? 'N/A') . "\n";
        echo "🔗 You can now use the application with UUID-based IDs.\n\n";
        
        // Test the new database
        echo "Testing new database...\n";
        test_new_database($pdo);
        
    } catch (Exception $e) {
        echo "❌ Error during database recreation: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

/**
 * Test the newly created database
 */
function test_new_database(PDO $pdo) {
    try {
        // Test 1: Check if tables exist
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "✅ Tables found: " . implode(', ', $tables) . "\n";
        
        // Test 2: Create a test page with UUID
        require_once __DIR__ . '/../api/UuidUtils.php';
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

// Run the recreation if this script is called directly
if (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    recreate_database_for_uuid_migration();
} 