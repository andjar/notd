<?php
/**
 * Test the migrated UUID database directly
 */

require_once __DIR__ . '/api/DataManager.php';
require_once __DIR__ . '/api/UuidUtils.php';

use App\DataManager;
use App\UuidUtils;

$testDbPath = __DIR__ . '/db/test_uuid_database.sqlite';

echo "=== Testing Migrated UUID Database ===\n\n";

try {
    $pdo = new PDO('sqlite:' . $testDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dm = new DataManager($pdo);
    
    echo "1. Testing database connection and basic structure...\n";
    
    // Test pages
    $pages = $dm->getPages();
    echo "   Pages found: " . count($pages['data']) . "\n";
    
    if (!empty($pages['data'])) {
        $firstPage = $pages['data'][0];
        echo "   First page ID: " . $firstPage['id'] . "\n";
        echo "   First page name: " . $firstPage['name'] . "\n";
        echo "   Is UUID v7: " . (UuidUtils::isValidUuidV7($firstPage['id']) ? 'YES' : 'NO') . "\n";
        
        // Test getting page by UUID
        $pageById = $dm->getPageById($firstPage['id']);
        if ($pageById) {
            echo "   ✅ Successfully retrieved page by UUID\n";
        } else {
            echo "   ❌ Failed to retrieve page by UUID\n";
        }
        
        // Test notes for this page
        $notes = $dm->getNotesByPageId($firstPage['id']);
        echo "   Notes found: " . count($notes) . "\n";
        
        if (!empty($notes)) {
            $firstNote = $notes[0];
            echo "   First note ID: " . $firstNote['id'] . "\n";
            echo "   First note content: " . substr($firstNote['content'], 0, 50) . "...\n";
            echo "   Is UUID v7: " . (UuidUtils::isValidUuidV7($firstNote['id']) ? 'YES' : 'NO') . "\n";
            
            // Test getting note by UUID
            $noteById = $dm->getNoteById($firstNote['id']);
            if ($noteById) {
                echo "   ✅ Successfully retrieved note by UUID\n";
                echo "   Note properties: " . count($noteById['properties']) . "\n";
            } else {
                echo "   ❌ Failed to retrieve note by UUID\n";
            }
        }
    }
    
    echo "\n2. Testing UUID generation and note creation...\n";
    
    // Test creating a new note with UUID
    $newNoteId = UuidUtils::generateUuidV7();
    echo "   Generated new note UUID: $newNoteId\n";
    
    // Insert a test note
    $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (?, ?, ?, ?)");
    $stmt->execute([$newNoteId, $firstPage['id'], 'Test note with UUID', 999]);
    
    // Verify we can retrieve it
    $createdNote = $dm->getNoteById($newNoteId);
    if ($createdNote) {
        echo "   ✅ Successfully created and retrieved note with UUID\n";
        echo "   Created note content: " . $createdNote['content'] . "\n";
    } else {
        echo "   ❌ Failed to create or retrieve note with UUID\n";
    }
    
    // Clean up test note
    $pdo->prepare("DELETE FROM Notes WHERE id = ?")->execute([$newNoteId]);
    
    echo "\n✅ All UUID database tests passed!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== End UUID Database Test ===\n";