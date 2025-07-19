<?php
/**
 * Create test data for transclusion children feature
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/db_connect.php';

echo "Creating test data for transclusion children feature...\n";

$pdo = get_db_connection();

// Create a test page
$pageStmt = $pdo->prepare("INSERT OR REPLACE INTO Pages (name, content, active) VALUES (?, ?, 1)");
$pageStmt->execute(['Transclusion Test', 'This page tests transclusion children feature']);
$pageId = $pdo->lastInsertId();

// If page already exists, get its ID
if (!$pageId) {
    $getPageStmt = $pdo->prepare("SELECT id FROM Pages WHERE name = ?");
    $getPageStmt->execute(['Transclusion Test']);
    $pageId = $getPageStmt->fetchColumn();
}

echo "Page ID: $pageId\n";

// Create the main note that will be transcluded
$mainNoteStmt = $pdo->prepare("INSERT INTO Notes (page_id, content, order_index, active) VALUES (?, ?, ?, 1)");
$mainNoteStmt->execute([$pageId, 'This is the main note that will be transcluded', 1]);
$mainNoteId = $pdo->lastInsertId();

echo "Main note ID: $mainNoteId\n";

// Create child notes
$child1Stmt = $pdo->prepare("INSERT INTO Notes (page_id, parent_note_id, content, order_index, active) VALUES (?, ?, ?, ?, 1)");
$child1Stmt->execute([$pageId, $mainNoteId, 'This is the first child note', 1]);
$child1Id = $pdo->lastInsertId();

$child2Stmt = $pdo->prepare("INSERT INTO Notes (page_id, parent_note_id, content, order_index, active) VALUES (?, ?, ?, ?, 1)");
$child2Stmt->execute([$pageId, $mainNoteId, 'This is the second child note', 2]);
$child2Id = $pdo->lastInsertId();

echo "Child 1 ID: $child1Id\n";
echo "Child 2 ID: $child2Id\n";

// Create a grandchild note
$grandchildStmt = $pdo->prepare("INSERT INTO Notes (page_id, parent_note_id, content, order_index, active) VALUES (?, ?, ?, ?, 1)");
$grandchildStmt->execute([$pageId, $child1Id, 'This is a grandchild note', 1]);
$grandchildId = $pdo->lastInsertId();

echo "Grandchild ID: $grandchildId\n";

// Create a note that contains the transclusion
$transclusionNoteStmt = $pdo->prepare("INSERT INTO Notes (page_id, content, order_index, active) VALUES (?, ?, ?, 1)");
$transclusionNoteStmt->execute([$pageId, "Here is a transclusion of note $mainNoteId: !{{$mainNoteId}}", 10]);
$transclusionNoteId = $pdo->lastInsertId();

echo "Transclusion note ID: $transclusionNoteId\n";

echo "\nTest data created successfully!\n";
echo "\nTo test:\n";
echo "1. Open the page: http://localhost:8000/page.php?page=Transclusion%20Test\n";
echo "2. Look for the transclusion in note $transclusionNoteId\n";
echo "3. It should show the main note ($mainNoteId) plus its children ($child1Id, $child2Id) and grandchild ($grandchildId)\n";

// Test the API directly
echo "\n--- Testing API directly ---\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost:8000/api/v1/notes.php?id=$mainNoteId&include_children=true");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($response) {
    $data = json_decode($response, true);
    if ($data && isset($data['data'])) {
        $note = $data['data'];
        echo "Note content: " . $note['content'] . "\n";
        echo "Children count: " . count($note['children'] ?? []) . "\n";
        foreach ($note['children'] ?? [] as $i => $child) {
            echo "  Child " . ($i + 1) . ": " . $child['content'] . "\n";
            foreach ($child['children'] ?? [] as $j => $grandchild) {
                echo "    Grandchild " . ($j + 1) . ": " . $grandchild['content'] . "\n";
            }
        }
    } else {
        echo "Response: $response\n";
    }
} else {
    echo "No response received\n";
}

echo "\nDone!\n";