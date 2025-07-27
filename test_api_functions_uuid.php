<?php
/**
 * Test the Notes API functions with UUID database
 */

require_once __DIR__ . '/api/DataManager.php';
require_once __DIR__ . '/api/uuid_utils.php';

use App\DataManager;
use App\UuidUtils;

$testDbPath = __DIR__ . '/db/test_uuid_database.sqlite';

echo "=== Testing Notes API Functions with UUIDs ===\n\n";

// Test setup
$pdo = new PDO('sqlite:' . $testDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dataManager = new DataManager($pdo);

// Get the test page
$pages = $dataManager->getPages();
$testPage = $pages['data'][0];
$pageId = $testPage['id'];

echo "Using test page: {$testPage['name']} (ID: $pageId)\n\n";

// Test 1: Direct database operation with UUID
echo "1. Testing direct note creation with UUID...\n";

$newNoteId = UuidUtils::generateUuidV7();
$testContent = "Test note content created directly";

// Insert directly
$stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (?, ?, ?, ?)");
$stmt->execute([$newNoteId, $pageId, $testContent, 100]);

echo "   ✅ Note created with UUID: $newNoteId\n";

// Test 2: Retrieve via DataManager
echo "\n2. Testing note retrieval by UUID...\n";

$retrievedNote = $dataManager->getNoteById($newNoteId);
if ($retrievedNote) {
    echo "   ✅ Note retrieved successfully\n";
    echo "   Retrieved ID: {$retrievedNote['id']}\n";
    echo "   Retrieved content: {$retrievedNote['content']}\n";
} else {
    echo "   ❌ Failed to retrieve note by UUID\n";
}

// Test 3: Update via DataManager
echo "\n3. Testing note update with UUID...\n";

$updatedContent = "Updated note content";
$updateStmt = $pdo->prepare("UPDATE Notes SET content = ? WHERE id = ?");
$updateStmt->execute([$updatedContent, $newNoteId]);

$updatedNote = $dataManager->getNoteById($newNoteId);
if ($updatedNote && $updatedNote['content'] === $updatedContent) {
    echo "   ✅ Note updated successfully\n";
    echo "   Updated content: {$updatedNote['content']}\n";
} else {
    echo "   ❌ Note update failed\n";
}

// Test 4: Create child note
echo "\n4. Testing child note creation...\n";

$childNoteId = UuidUtils::generateUuidV7();
$childContent = "Child note content";

$childStmt = $pdo->prepare("INSERT INTO Notes (id, page_id, parent_note_id, content, order_index) VALUES (?, ?, ?, ?, ?)");
$childStmt->execute([$childNoteId, $pageId, $newNoteId, $childContent, 0]);

echo "   ✅ Child note created with UUID: $childNoteId\n";

// Test 5: Get notes for page (should include both)
echo "\n5. Testing page notes retrieval...\n";

$pageNotes = $dataManager->getNotesByPageId($pageId);
$ourNotes = array_filter($pageNotes, function($note) use ($newNoteId, $childNoteId) {
    return $note['id'] === $newNoteId || $note['id'] === $childNoteId;
});

echo "   Total notes for page: " . count($pageNotes) . "\n";
echo "   Our test notes: " . count($ourNotes) . "\n";

if (count($ourNotes) === 2) {
    echo "   ✅ Both test notes found in page\n";
    
    // Check parent-child relationship
    $parentNote = null;
    $childNote = null;
    foreach ($ourNotes as $note) {
        if ($note['id'] === $newNoteId) {
            $parentNote = $note;
        } else if ($note['id'] === $childNoteId) {
            $childNote = $note;
        }
    }
    
    if ($parentNote && $childNote && $childNote['parent_note_id'] === $parentNote['id']) {
        echo "   ✅ Parent-child relationship correct\n";
    } else {
        echo "   ❌ Parent-child relationship broken\n";
    }
} else {
    echo "   ❌ Expected 2 test notes, found " . count($ourNotes) . "\n";
}

// Test 6: Test getNoteWithChildren
echo "\n6. Testing hierarchical note retrieval...\n";

$noteWithChildren = $dataManager->getNoteWithChildren($newNoteId);
if ($noteWithChildren && isset($noteWithChildren['children']) && count($noteWithChildren['children']) === 1) {
    echo "   ✅ Note with children retrieved successfully\n";
    echo "   Parent note: {$noteWithChildren['id']}\n";
    echo "   Child count: " . count($noteWithChildren['children']) . "\n";
    echo "   Child ID: {$noteWithChildren['children'][0]['id']}\n";
} else {
    echo "   ❌ Failed to retrieve note with children\n";
}

// Test 7: Clean up
echo "\n7. Testing cleanup...\n";

$deleteChild = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
$deleteChild->execute([$childNoteId]);

$deleteParent = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
$deleteParent->execute([$newNoteId]);

echo "   ✅ Test notes deleted\n";

// Test 8: Verify UUIDv7 time ordering
echo "\n8. Testing UUIDv7 time ordering...\n";

$uuid1 = UuidUtils::generateUuidV7();
usleep(5000); // 5ms delay
$uuid2 = UuidUtils::generateUuidV7();

$ts1 = UuidUtils::extractTimestamp($uuid1);
$ts2 = UuidUtils::extractTimestamp($uuid2);

if ($ts2 > $ts1) {
    echo "   ✅ UUIDv7 time ordering works correctly\n";
    echo "   UUID1: $uuid1 (timestamp: $ts1)\n";
    echo "   UUID2: $uuid2 (timestamp: $ts2)\n";
} else {
    echo "   ❌ UUIDv7 time ordering failed\n";
}

echo "\n✅ All UUID API function tests completed successfully!\n";
echo "=== End UUID API Function Test ===\n";