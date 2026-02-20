<?php
require_once __DIR__ . '/../config.php';

/**
 * Forcefully recreate the database by deleting the file
 */
function force_recreate_database() {
    try {
        echo "=== Force Database Recreation ===\n";
        
        $dbPath = DB_PATH;
        echo "Database path: $dbPath\n";
        
        // Check if database file exists
        if (file_exists($dbPath)) {
            echo "Existing database found.\n";
            
            // Create backup
            $backupPath = $dbPath . '.backup.' . date('Y-m-d_H-i-s');
            if (copy($dbPath, $backupPath)) {
                echo "✅ Backup created: $backupPath\n";
            } else {
                echo "⚠️  Failed to create backup\n";
            }
            
            // Delete the database file
            if (unlink($dbPath)) {
                echo "✅ Database file deleted.\n";
            } else {
                echo "❌ Failed to delete database file.\n";
                return;
            }
        } else {
            echo "No existing database found.\n";
        }
        
        echo "\nDatabase will be recreated when you next access the application.\n";
        echo "Please refresh your browser or restart the application.\n";
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Run the recreation if this script is called directly
if (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    force_recreate_database();
} 