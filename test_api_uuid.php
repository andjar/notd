<?php
/**
 * Test the Notes API with UUID database
 */

require_once __DIR__ . '/api/DataManager.php';
require_once __DIR__ . '/api/uuid_utils.php';
require_once __DIR__ . '/api/v1/notes.php';

use App\DataManager;
use App\UuidUtils;

$testDbPath = __DIR__ . '/db/test_uuid_database.sqlite';

echo "=== Testing Notes API with UUIDs ===\n\n";

// Test setup
$pdo = new PDO('sqlite:' . $testDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dataManager = new DataManager($pdo);

// Get the test page
$pages = $dataManager->getPages();
$testPage = $pages['data'][0];
$pageId = $testPage['id'];

echo "Using test page: {$testPage['name']} (ID: $pageId)\n\n";

// Test 1: Create a new note with UUID
echo "1. Testing note creation with UUID...\n";

$newNoteId = UuidUtils::generateUuidV7();
$testContent = "Test note content created via API";

// Simulate the create note batch operation
$createOperation = [
    'type' => 'create',
    'payload' => [
        'id' => $newNoteId,
        'page_id' => $pageId,
        'content' => $testContent,
        'parent_note_id' => null,
        'order_index' => 100
    ]
];

$tempIdMap = [];
$result = _createNoteInBatch($pdo, $dataManager, $createOperation['payload'], $tempIdMap);

if ($result['status'] === 'success') {
    echo "   ✅ Note created successfully\n";
    echo "   Created note ID: {$result['note']['id']}\n";
    echo "   Created note content: {$result['note']['content']}\n";
} else {
    echo "   ❌ Note creation failed: {$result['message']}\n";
    exit(1);
}

// Test 2: Update the note
echo "\n2. Testing note update...\n";

$updatedContent = "Updated test note content";
$updateOperation = [
    'type' => 'update', 
    'payload' => [
        'id' => $newNoteId,
        'content' => $updatedContent
    ]
];

$updateResult = _updateNoteInBatch($pdo, $dataManager, $updateOperation['payload'], $tempIdMap);

if ($updateResult['status'] === 'success') {
    echo "   ✅ Note updated successfully\n";
    echo "   Updated note content: {$updateResult['note']['content']}\n";
} else {
    echo "   ❌ Note update failed: {$updateResult['message']}\n";
}

// Test 3: Create a child note
echo "\n3. Testing child note creation...\n";

$childNoteId = UuidUtils::generateUuidV7();
$childContent = "Child note content";

$createChildOperation = [
    'type' => 'create',
    'payload' => [
        'id' => $childNoteId,
        'page_id' => $pageId,
        'content' => $childContent,
        'parent_note_id' => $newNoteId,
        'order_index' => 0
    ]
];

$childResult = _createNoteInBatch($pdo, $dataManager, $createChildOperation['payload'], $tempIdMap);

if ($childResult['status'] === 'success') {
    echo "   ✅ Child note created successfully\n";
    echo "   Child note ID: {$childResult['note']['id']}\n";
    echo "   Parent note ID: {$childResult['note']['parent_note_id']}\n";
} else {
    echo "   ❌ Child note creation failed: {$childResult['message']}\n";
}

// Test 4: Get notes for the page
echo "\n4. Testing note retrieval...\n";

$pageNotes = $dataManager->getNotesByPageId($pageId);
echo "   Notes found for page: " . count($pageNotes) . "\n";

$ourNotes = array_filter($pageNotes, function($note) use ($newNoteId, $childNoteId) {
    return $note['id'] === $newNoteId || $note['id'] === $childNoteId;
});

echo "   Our test notes found: " . count($ourNotes) . "\n";

// Test 5: Delete the child note first
echo "\n5. Testing note deletion...\n";

$deleteChildOperation = [
    'type' => 'delete',
    'payload' => [
        'id' => $childNoteId
    ]
];

$deleteChildResult = _deleteNoteInBatch($pdo, $deleteChildOperation['payload'], $tempIdMap);

if ($deleteChildResult['status'] === 'success') {
    echo "   ✅ Child note deleted successfully\n";
} else {
    echo "   ❌ Child note deletion failed: {$deleteChildResult['message']}\n";
}

// Test 6: Delete the parent note
$deleteParentOperation = [
    'type' => 'delete',
    'payload' => [
        'id' => $newNoteId
    ]
];

$deleteParentResult = _deleteNoteInBatch($pdo, $deleteParentOperation['payload'], $tempIdMap);

if ($deleteParentResult['status'] === 'success') {
    echo "   ✅ Parent note deleted successfully\n";
} else {
    echo "   ❌ Parent note deletion failed: {$deleteParentResult['message']}\n";
}

// Test 7: Verify notes are gone
echo "\n6. Verifying cleanup...\n";

$pageNotesAfter = $dataManager->getNotesByPageId($pageId);
$remainingOurNotes = array_filter($pageNotesAfter, function($note) use ($newNoteId, $childNoteId) {
    return $note['id'] === $newNoteId || $note['id'] === $childNoteId;
});

if (count($remainingOurNotes) === 0) {
    echo "   ✅ All test notes successfully removed\n";
} else {
    echo "   ❌ Some test notes still remain: " . count($remainingOurNotes) . "\n";
}

echo "\n✅ All Notes API UUID tests completed!\n";
echo "=== End Notes API UUID Test ===\n";