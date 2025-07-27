<?php
/**
 * Database Migration Script: Convert Integer IDs to UUIDv7
 * 
 * This script migrates the database from integer auto-increment IDs to UUIDv7 for:
 * - Pages table
 * - Notes table
 * - All foreign key references
 * 
 * IMPORTANT: This script creates a backup before making changes.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/db_connect.php';
require_once __DIR__ . '/api/uuid_utils.php';

use App\UuidUtils;

echo "=== Database Migration: Integer IDs to UUIDv7 ===\n\n";

// Create backup
$backupPath = DB_PATH . '.backup.' . date('Y-m-d_H-i-s');
echo "1. Creating backup at: $backupPath\n";
if (!copy(DB_PATH, $backupPath)) {
    echo "❌ Failed to create backup!\n";
    exit(1);
}
echo "✅ Backup created successfully\n\n";

// Connect to database
try {
    $pdo = get_db_connection();
    $pdo->exec('PRAGMA foreign_keys = OFF'); // Disable foreign keys during migration
} catch (Exception $e) {
    echo "❌ Failed to connect to database: " . $e->getMessage() . "\n";
    exit(1);
}

echo "2. Starting migration process...\n";

try {
    $pdo->beginTransaction();
    
    // Step 1: Create mapping tables for old ID to new UUID
    echo "   Creating ID mapping tables...\n";
    $pdo->exec("CREATE TEMPORARY TABLE page_id_map (old_id INTEGER, new_id TEXT)");
    $pdo->exec("CREATE TEMPORARY TABLE note_id_map (old_id INTEGER, new_id TEXT)");
    
    // Step 2: Generate UUIDs for all existing pages
    echo "   Generating UUIDs for pages...\n";
    $pageStmt = $pdo->query("SELECT id FROM Pages ORDER BY id");
    $pages = $pageStmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($pages as $oldPageId) {
        $newUuid = UuidUtils::generateUuidV7();
        $pdo->prepare("INSERT INTO page_id_map (old_id, new_id) VALUES (?, ?)")
            ->execute([$oldPageId, $newUuid]);
        usleep(1000); // Ensure UUIDs have different timestamps
    }
    
    // Step 3: Generate UUIDs for all existing notes
    echo "   Generating UUIDs for notes...\n";
    $noteStmt = $pdo->query("SELECT id FROM Notes ORDER BY id");
    $notes = $noteStmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($notes as $oldNoteId) {
        $newUuid = UuidUtils::generateUuidV7();
        $pdo->prepare("INSERT INTO note_id_map (old_id, new_id) VALUES (?, ?)")
            ->execute([$oldNoteId, $newUuid]);
        usleep(1000); // Ensure UUIDs have different timestamps
    }
    
    // Step 4: Create new tables with UUID primary keys
    echo "   Creating new table structures...\n";
    
    // New Pages table
    $pdo->exec("
        CREATE TABLE Pages_new (
            id TEXT PRIMARY KEY,
            name TEXT UNIQUE NOT NULL,
            content TEXT,
            alias TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // New Notes table
    $pdo->exec("
        CREATE TABLE Notes_new (
            id TEXT PRIMARY KEY,
            page_id TEXT NOT NULL,
            parent_note_id TEXT,
            content TEXT,
            internal INTEGER NOT NULL DEFAULT 0,
            order_index INTEGER NOT NULL DEFAULT 0,
            collapsed INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (page_id) REFERENCES Pages_new(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_note_id) REFERENCES Notes_new(id) ON DELETE CASCADE
        )
    ");
    
    // New Attachments table
    $pdo->exec("
        CREATE TABLE Attachments_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            note_id TEXT,
            name TEXT NOT NULL,
            path TEXT NOT NULL UNIQUE,
            type TEXT,
            size INTEGER,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (note_id) REFERENCES Notes_new(id) ON DELETE SET NULL
        )
    ");
    
    // New Properties table
    $pdo->exec("
        CREATE TABLE Properties_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            note_id TEXT,
            page_id TEXT,
            name TEXT NOT NULL,
            value TEXT,
            weight INTEGER NOT NULL DEFAULT 2,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (note_id) REFERENCES Notes_new(id) ON DELETE CASCADE,
            FOREIGN KEY (page_id) REFERENCES Pages_new(id) ON DELETE CASCADE,
            CHECK (
                (note_id IS NOT NULL AND page_id IS NULL) OR
                (note_id IS NULL AND page_id IS NOT NULL)
            )
        )
    ");
    
    // Step 5: Migrate data to new tables
    echo "   Migrating pages data...\n";
    $pdo->exec("
        INSERT INTO Pages_new (id, name, content, alias, active, created_at, updated_at)
        SELECT m.new_id, p.name, p.content, p.alias, p.active, p.created_at, p.updated_at
        FROM Pages p
        JOIN page_id_map m ON p.id = m.old_id
    ");
    
    echo "   Migrating notes data...\n";
    $pdo->exec("
        INSERT INTO Notes_new (id, page_id, parent_note_id, content, internal, order_index, collapsed, active, created_at, updated_at)
        SELECT 
            nm.new_id, 
            pm.new_id,
            CASE WHEN n.parent_note_id IS NULL THEN NULL ELSE pnm.new_id END,
            n.content, 
            n.internal, 
            n.order_index, 
            n.collapsed, 
            n.active, 
            n.created_at, 
            n.updated_at
        FROM Notes n
        JOIN note_id_map nm ON n.id = nm.old_id
        JOIN page_id_map pm ON n.page_id = pm.old_id
        LEFT JOIN note_id_map pnm ON n.parent_note_id = pnm.old_id
    ");
    
    echo "   Migrating attachments data...\n";
    $pdo->exec("
        INSERT INTO Attachments_new (note_id, name, path, type, size, active, created_at)
        SELECT 
            CASE WHEN a.note_id IS NULL THEN NULL ELSE nm.new_id END,
            a.name, 
            a.path, 
            a.type, 
            a.size, 
            a.active, 
            a.created_at
        FROM Attachments a
        LEFT JOIN note_id_map nm ON a.note_id = nm.old_id
    ");
    
    echo "   Migrating properties data...\n";
    $pdo->exec("
        INSERT INTO Properties_new (note_id, page_id, name, value, weight, active, created_at, updated_at)
        SELECT 
            CASE WHEN p.note_id IS NULL THEN NULL ELSE nm.new_id END,
            CASE WHEN p.page_id IS NULL THEN NULL ELSE pm.new_id END,
            p.name, 
            p.value, 
            p.weight, 
            p.active, 
            p.created_at, 
            p.updated_at
        FROM Properties p
        LEFT JOIN note_id_map nm ON p.note_id = nm.old_id
        LEFT JOIN page_id_map pm ON p.page_id = pm.old_id
    ");
    
    // Step 6: Drop old tables and rename new ones
    echo "   Replacing old tables with new ones...\n";
    $pdo->exec("DROP TABLE Properties");
    $pdo->exec("DROP TABLE Attachments");
    $pdo->exec("DROP TABLE Notes");
    $pdo->exec("DROP TABLE Pages");
    
    $pdo->exec("ALTER TABLE Pages_new RENAME TO Pages");
    $pdo->exec("ALTER TABLE Notes_new RENAME TO Notes");
    $pdo->exec("ALTER TABLE Attachments_new RENAME TO Attachments");
    $pdo->exec("ALTER TABLE Properties_new RENAME TO Properties");
    
    // Step 7: Recreate indexes
    echo "   Recreating indexes...\n";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pages_name ON Pages(LOWER(name))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notes_page_id ON Notes(page_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notes_parent_note_id ON Notes(parent_note_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attachments_note_id ON Attachments(note_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_properties_note_id_name ON Properties(note_id, name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_properties_page_id_name ON Properties(page_id, name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_properties_name_value ON Properties(name, value)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_properties_weight ON Properties(weight)");
    
    // Step 8: Recreate triggers
    echo "   Recreating triggers...\n";
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS update_pages_updated_at
        AFTER UPDATE ON Pages FOR EACH ROW
        BEGIN
            UPDATE Pages SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
        END
    ");
    
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS update_properties_updated_at
        AFTER UPDATE ON Properties FOR EACH ROW
        BEGIN
            UPDATE Properties SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
        END
    ");
    
    // Step 9: Handle FTS table (recreate it)
    echo "   Recreating FTS table...\n";
    $pdo->exec("DROP TABLE IF EXISTS Notes_fts");
    $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS Notes_fts USING fts4(content)");
    
    // Populate FTS table with new data
    $pdo->exec("INSERT INTO Notes_fts(rowid, content) SELECT id, content FROM Notes WHERE content IS NOT NULL");
    
    // Recreate FTS triggers
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS Notes_after_insert AFTER INSERT ON Notes BEGIN
          INSERT INTO Notes_fts(rowid, content) VALUES (new.id, new.content);
        END
    ");
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS Notes_after_delete AFTER DELETE ON Notes BEGIN
          DELETE FROM Notes_fts WHERE docid=old.id;
        END
    ");
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS Notes_after_update AFTER UPDATE ON Notes BEGIN
          DELETE FROM Notes_fts WHERE docid=old.id;
          INSERT INTO Notes_fts(rowid, content) VALUES (new.id, new.content);
        END
    ");
    
    $pdo->commit();
    echo "✅ Migration completed successfully!\n\n";
    
    // Step 10: Verification
    echo "3. Verifying migration...\n";
    $pageCount = $pdo->query("SELECT COUNT(*) FROM Pages")->fetchColumn();
    $noteCount = $pdo->query("SELECT COUNT(*) FROM Notes")->fetchColumn();
    $propCount = $pdo->query("SELECT COUNT(*) FROM Properties")->fetchColumn();
    $attachCount = $pdo->query("SELECT COUNT(*) FROM Attachments")->fetchColumn();
    
    echo "   Pages: $pageCount\n";
    echo "   Notes: $noteCount\n";
    echo "   Properties: $propCount\n";
    echo "   Attachments: $attachCount\n";
    
    // Test that UUIDs are valid
    $samplePage = $pdo->query("SELECT id FROM Pages LIMIT 1")->fetchColumn();
    $sampleNote = $pdo->query("SELECT id FROM Notes LIMIT 1")->fetchColumn();
    
    if (UuidUtils::isValidUuidV7($samplePage) && UuidUtils::isValidUuidV7($sampleNote)) {
        echo "✅ Sample UUIDs are valid\n";
    } else {
        echo "❌ Sample UUIDs are invalid!\n";
        throw new Exception("Generated UUIDs are not valid");
    }
    
} catch (Exception $e) {
    $pdo->rollback();
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Database has been rolled back to original state.\n";
    echo "Backup is still available at: $backupPath\n";
    exit(1);
} finally {
    $pdo->exec('PRAGMA foreign_keys = ON'); // Re-enable foreign keys
}

echo "\n✅ Migration completed successfully!\n";
echo "Backup created at: $backupPath\n";
echo "=== End Migration ===\n";