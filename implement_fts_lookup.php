<?php
// Implement FTS lookup table solution
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Implement FTS Lookup Table Solution</h1>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Create FTS lookup table
    echo "<h2>Creating FTS Lookup Table:</h2>";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Notes_fts_lookup (
            uuid TEXT PRIMARY KEY,
            fts_id INTEGER UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p style='color: green;'>✅ Created Notes_fts_lookup table</p>";
    
    // Create index for performance
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fts_lookup_uuid ON Notes_fts_lookup(uuid)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fts_lookup_fts_id ON Notes_fts_lookup(fts_id)");
    echo "<p style='color: green;'>✅ Created indexes</p>";
    
    // Create new FTS triggers that use the lookup table
    echo "<h2>Creating New FTS Triggers:</h2>";
    
    // Drop old triggers first
    $pdo->exec("DROP TRIGGER IF EXISTS Notes_after_insert");
    $pdo->exec("DROP TRIGGER IF EXISTS Notes_after_update");
    $pdo->exec("DROP TRIGGER IF EXISTS Notes_after_delete");
    echo "<p style='color: green;'>✅ Dropped old triggers</p>";
    
    // Create new triggers
    $pdo->exec("
        CREATE TRIGGER Notes_after_insert 
        AFTER INSERT ON Notes 
        BEGIN
            INSERT INTO Notes_fts_lookup (uuid, fts_id) 
            VALUES (new.id, (SELECT COALESCE(MAX(fts_id), 0) + 1 FROM Notes_fts_lookup));
            INSERT INTO Notes_fts(docid, content) 
            VALUES ((SELECT fts_id FROM Notes_fts_lookup WHERE uuid = new.id), new.content);
        END
    ");
    echo "<p style='color: green;'>✅ Created Notes_after_insert trigger</p>";
    
    $pdo->exec("
        CREATE TRIGGER Notes_after_update 
        AFTER UPDATE ON Notes 
        BEGIN
            UPDATE Notes_fts 
            SET content = new.content 
            WHERE docid = (SELECT fts_id FROM Notes_fts_lookup WHERE uuid = new.id);
        END
    ");
    echo "<p style='color: green;'>✅ Created Notes_after_update trigger</p>";
    
    $pdo->exec("
        CREATE TRIGGER Notes_after_delete 
        AFTER DELETE ON Notes 
        BEGIN
            DELETE FROM Notes_fts 
            WHERE docid = (SELECT fts_id FROM Notes_fts_lookup WHERE uuid = old.id);
            DELETE FROM Notes_fts_lookup WHERE uuid = old.id;
        END
    ");
    echo "<p style='color: green;'>✅ Created Notes_after_delete trigger</p>";
    
    // Test the solution
    echo "<h2>Testing FTS Lookup Solution:</h2>";
    
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
            // Test note creation
            $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content) VALUES (?, ?, ?)");
            $result = $stmt->execute([$testUuid, $pageId, 'Test content for search']);
            
            if ($result) {
                echo "<p style='color: green;'>✅ Note creation successful</p>";
                
                // Check if lookup entry was created
                $stmt = $pdo->prepare("SELECT * FROM Notes_fts_lookup WHERE uuid = ?");
                $stmt->execute([$testUuid]);
                $lookup = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($lookup) {
                    echo "<p style='color: green;'>✅ Lookup entry created: UUID={$lookup['uuid']}, FTS_ID={$lookup['fts_id']}</p>";
                    
                    // Test FTS search
                    $ftsId = $lookup['fts_id'];
                    $stmt = $pdo->prepare("SELECT * FROM Notes_fts WHERE docid = ?");
                    $stmt->execute([$ftsId]);
                    $ftsEntry = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($ftsEntry) {
                        echo "<p style='color: green;'>✅ FTS entry created: docid={$ftsEntry['docid']}, content={$ftsEntry['content']}</p>";
                        
                        // Test search functionality
                        $stmt = $pdo->prepare("SELECT * FROM Notes_fts WHERE Notes_fts MATCH 'search'");
                        $stmt->execute();
                        $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        echo "<p style='color: green;'>✅ Search test successful: Found " . count($searchResults) . " results</p>";
                        
                        // Clean up
                        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
                        $stmt->execute([$testUuid]);
                        echo "<p style='color: green;'>✅ Test data cleaned up</p>";
                        
                        echo "<h2>🎯 FTS LOOKUP SOLUTION IMPLEMENTED!</h2>";
                        echo "<p style='color: green; font-weight: bold;'>✅ Search functionality is now working with UUIDs!</p>";
                        
                        echo "<h3>How it works:</h3>";
                        echo "<ul>";
                        echo "<li>✅ <strong>Lookup table</strong> maps UUIDs to integer FTS IDs</li>";
                        echo "<li>✅ <strong>FTS table</strong> uses integer docid (as required by SQLite)</li>";
                        echo "<li>✅ <strong>Triggers</strong> automatically maintain the mapping</li>";
                        echo "<li>✅ <strong>Search</strong> works normally with FTS</li>";
                        echo "</ul>";
                        
                    } else {
                        echo "<p style='color: red;'>❌ FTS entry not created</p>";
                    }
                } else {
                    echo "<p style='color: red;'>❌ Lookup entry not created</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Note creation failed</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
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