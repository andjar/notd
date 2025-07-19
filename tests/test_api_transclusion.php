<?php
/**
 * Simple test script for the transclusion children feature
 */

require_once __DIR__ . '/../api/db_connect.php';
require_once __DIR__ . '/../api/DataManager.php';
require_once __DIR__ . '/../config.php';

echo "=== Testing Transclusion Children Feature ===\n";

// Create test database
$pdo = get_db_connection();

// Load schema
$schema = file_get_contents(__DIR__ . '/../db/schema.sql');
$pdo->exec($schema);

$dataManager = new \App\DataManager($pdo);

// Create test data
echo "Creating test data...\n";

// Create a test page
$uniquePageName = 'API Test Page ' . uniqid();
$pageStmt = $pdo->prepare("INSERT INTO Pages (name, content, active) VALUES (?, ?, 1)");
$pageStmt->execute([$uniquePageName, 'Test page content']);
$pageId = $pdo->lastInsertId();

// Create parent note with transclusion
$parentStmt = $pdo->prepare("INSERT INTO Notes (page_id, content, order_index, active) VALUES (?, ?, ?, 1)");
$parentStmt->execute([$pageId, 'Parent note with transclusion !{{123}} here', 1]);
$parentId = $pdo->lastInsertId();

// Create the note to be transcluded (note 123 won't exist, let's use the actual ID)
$transcludeStmt = $pdo->prepare("INSERT INTO Notes (page_id, content, order_index, active) VALUES (?, ?, ?, 1)");
$transcludeStmt->execute([$pageId, 'This is the transcluded note content', 2]);
$transcludeId = $pdo->lastInsertId();

// Create children for the transcluded note
$child1Stmt = $pdo->prepare("INSERT INTO Notes (page_id, parent_note_id, content, order_index, active) VALUES (?, ?, ?, ?, 1)");
$child1Stmt->execute([$pageId, $transcludeId, 'First child of transcluded note', 1]);

$child2Stmt = $pdo->prepare("INSERT INTO Notes (page_id, parent_note_id, content, order_index, active) VALUES (?, ?, ?, ?, 1)");
$child2Stmt->execute([$pageId, $transcludeId, 'Second child of transcluded note', 2]);

echo "Test data created.\n";
echo "Transcluded note ID: $transcludeId\n";

// Test 1: Get note without children (old behavior)
echo "\n--- Test 1: Note without children ---\n";
$noteOnly = $dataManager->getNoteById($transcludeId);
echo "Note content: " . $noteOnly['content'] . "\n";
echo "Has children property: " . (isset($noteOnly['children']) ? 'yes' : 'no') . "\n";

// Test 2: Get note with children (new behavior)
echo "\n--- Test 2: Note with children ---\n";
$noteWithChildren = $dataManager->getNoteWithChildren($transcludeId);
echo "Note content: " . $noteWithChildren['content'] . "\n";
echo "Children count: " . count($noteWithChildren['children']) . "\n";
foreach ($noteWithChildren['children'] as $i => $child) {
    echo "  Child " . ($i + 1) . ": " . $child['content'] . "\n";
}

// Test 3: Simulate API call
echo "\n--- Test 3: API simulation ---\n";
echo "Simulating API call: GET /api/v1/notes.php?id=$transcludeId&include_children=true\n";

// We'll test this by setting up the same parameters the API would use
$_GET['id'] = $transcludeId;
$_GET['include_children'] = 'true';

$includeChildren = filter_var($_GET['include_children'] ?? false, FILTER_VALIDATE_BOOLEAN);
echo "include_children parsed as: " . ($includeChildren ? 'true' : 'false') . "\n";

if ($includeChildren) {
    $apiResult = $dataManager->getNoteWithChildren($transcludeId);
    echo "API would return note with " . count($apiResult['children']) . " children\n";
} else {
    $apiResult = $dataManager->getNoteById($transcludeId);
    echo "API would return note without children\n";
}

echo "\n=== Test completed successfully! ===\n";