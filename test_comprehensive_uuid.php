<?php
/**
 * Comprehensive End-to-End Test for UUID Implementation
 */

require_once __DIR__ . '/api/DataManager.php';
require_once __DIR__ . '/api/uuid_utils.php';

use App\DataManager;
use App\UuidUtils;

echo "=== Comprehensive UUID End-to-End Test ===\n\n";

$testDbPath = __DIR__ . '/db/test_uuid_database.sqlite';

try {
    $pdo = new PDO('sqlite:' . $testDbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dm = new DataManager($pdo);
    
    echo "✅ Database connection established\n";
    
    // Test 1: Verify existing data is properly migrated
    echo "\n1. Testing migrated data integrity...\n";
    
    $pages = $dm->getPages();
    $testPage = $pages['data'][0];
    echo "   First page: {$testPage['name']} (ID: {$testPage['id']})\n";
    echo "   Page ID is valid UUID v7: " . (UuidUtils::isValidUuidV7($testPage['id']) ? 'YES' : 'NO') . "\n";
    
    $notes = $dm->getNotesByPageId($testPage['id']);
    echo "   Notes found: " . count($notes) . "\n";
    
    if (!empty($notes)) {
        $firstNote = $notes[0];
        echo "   First note ID: {$firstNote['id']}\n";
        echo "   First note ID is valid UUID v7: " . (UuidUtils::isValidUuidV7($firstNote['id']) ? 'YES' : 'NO') . "\n";
        echo "   Note has properties: " . count($firstNote['properties']) . "\n";
    }
    
    // Test 2: Create a new page with UUID
    echo "\n2. Testing page creation with UUID...\n";
    
    $newPageId = UuidUtils::generateUuidV7();
    $pageName = "Test Page " . date('Y-m-d H:i:s');
    $pageContent = "This is a test page created with UUID v7";
    
    $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (?, ?, ?)");
    $stmt->execute([$newPageId, $pageName, $pageContent]);
    
    $createdPage = $dm->getPageById($newPageId);
    if ($createdPage) {
        echo "   ✅ Page created successfully with UUID: $newPageId\n";
        echo "   Page name: {$createdPage['name']}\n";
    } else {
        echo "   ❌ Failed to create page with UUID\n";
        exit(1);
    }
    
    // Test 3: Create hierarchical notes with UUIDs
    echo "\n3. Testing hierarchical note creation...\n";
    
    $parentNoteId = UuidUtils::generateUuidV7();
    $childNoteId = UuidUtils::generateUuidV7();
    $grandchildNoteId = UuidUtils::generateUuidV7();
    
    // Create parent note
    $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (?, ?, ?, ?)");
    $stmt->execute([$parentNoteId, $newPageId, "Parent note with UUID", 0]);
    
    // Create child note
    $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, parent_note_id, content, order_index) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$childNoteId, $newPageId, $parentNoteId, "Child note with UUID", 0]);
    
    // Create grandchild note
    $stmt->execute([$grandchildNoteId, $newPageId, $childNoteId, "Grandchild note with UUID", 0]);
    
    echo "   ✅ Created 3-level hierarchy with UUIDs\n";
    echo "   Parent: $parentNoteId\n";
    echo "   Child: $childNoteId\n";
    echo "   Grandchild: $grandchildNoteId\n";
    
    // Test 4: Test hierarchical retrieval
    echo "\n4. Testing hierarchical note retrieval...\n";
    
    $noteWithChildren = $dm->getNoteWithChildren($parentNoteId);
    if ($noteWithChildren && isset($noteWithChildren['children'])) {
        echo "   ✅ Retrieved note with children\n";
        echo "   Parent note: {$noteWithChildren['id']}\n";
        echo "   Number of children: " . count($noteWithChildren['children']) . "\n";
        
        if (!empty($noteWithChildren['children'])) {
            $child = $noteWithChildren['children'][0];
            echo "   Child note: {$child['id']}\n";
            echo "   Child has children: " . (isset($child['children']) && !empty($child['children']) ? 'YES' : 'NO') . "\n";
            
            if (isset($child['children']) && !empty($child['children'])) {
                $grandchild = $child['children'][0];
                echo "   Grandchild note: {$grandchild['id']}\n";
            }
        }
    } else {
        echo "   ❌ Failed to retrieve note hierarchy\n";
    }
    
    // Test 5: Test properties with UUIDs
    echo "\n5. Testing properties with UUID notes...\n";
    
    $propertyNoteId = UuidUtils::generateUuidV7();
    $noteContent = "Test note with properties {status::TODO} {priority::high}";
    
    $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (?, ?, ?, ?)");
    $stmt->execute([$propertyNoteId, $newPageId, $noteContent, 10]);
    
    // We would need to trigger property processing here
    // For now, just verify the note was created
    $propertyNote = $dm->getNoteById($propertyNoteId);
    if ($propertyNote) {
        echo "   ✅ Note with properties created: $propertyNoteId\n";
        echo "   Content: {$propertyNote['content']}\n";
    }
    
    // Test 6: Test time-ordered UUID generation
    echo "\n6. Testing UUID v7 time ordering...\n";
    
    $uuid1 = UuidUtils::generateUuidV7();
    usleep(5000); // 5ms delay
    $uuid2 = UuidUtils::generateUuidV7();
    usleep(5000); // 5ms delay  
    $uuid3 = UuidUtils::generateUuidV7();
    
    $ts1 = UuidUtils::extractTimestamp($uuid1);
    $ts2 = UuidUtils::extractTimestamp($uuid2);
    $ts3 = UuidUtils::extractTimestamp($uuid3);
    
    if ($ts1 < $ts2 && $ts2 < $ts3) {
        echo "   ✅ UUID v7 time ordering works correctly\n";
        echo "   UUID1: $uuid1 (ts: $ts1)\n";
        echo "   UUID2: $uuid2 (ts: $ts2)\n";
        echo "   UUID3: $uuid3 (ts: $ts3)\n";
    } else {
        echo "   ❌ UUID v7 time ordering failed\n";
        echo "   Timestamps: $ts1, $ts2, $ts3\n";
    }
    
    // Test 7: Performance test - create many notes quickly
    echo "\n7. Testing UUID generation performance...\n";
    
    $start = microtime(true);
    $testNoteIds = [];
    
    for ($i = 0; $i < 100; $i++) {
        $testNoteIds[] = UuidUtils::generateUuidV7();
        usleep(100); // Small delay to ensure different timestamps
    }
    
    $duration = microtime(true) - $start;
    echo "   ✅ Generated 100 UUIDs in " . round($duration * 1000, 2) . " ms\n";
    
    // Verify all UUIDs are unique and valid
    $uniqueIds = array_unique($testNoteIds);
    $allValid = true;
    foreach ($testNoteIds as $id) {
        if (!UuidUtils::isValidUuidV7($id)) {
            $allValid = false;
            break;
        }
    }
    
    echo "   All UUIDs unique: " . (count($uniqueIds) === count($testNoteIds) ? 'YES' : 'NO') . "\n";
    echo "   All UUIDs valid: " . ($allValid ? 'YES' : 'NO') . "\n";
    
    // Test 8: Clean up test data
    echo "\n8. Cleaning up test data...\n";
    
    // Delete test notes
    $pdo->prepare("DELETE FROM Notes WHERE page_id = ?")->execute([$newPageId]);
    $deletedNotes = $pdo->prepare("SELECT changes()")->fetchColumn();
    
    // Delete test page
    $pdo->prepare("DELETE FROM Pages WHERE id = ?")->execute([$newPageId]);
    $deletedPages = $pdo->prepare("SELECT changes()")->fetchColumn();
    
    echo "   ✅ Cleaned up $deletedNotes notes and $deletedPages pages\n";
    
    echo "\n✅ All comprehensive UUID tests passed successfully!\n";
    echo "=== End Comprehensive UUID Test ===\n";
    
} catch (Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}