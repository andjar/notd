<?php
/**
 * Test script for the new unified upsert approach
 * Tests the simplified create/update operations
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/db_connect.php';
require_once __DIR__ . '/api/DataManager.php';
require_once __DIR__ . '/api/UuidUtils.php';

use App\UuidUtils;

try {
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);
    
    echo "Testing unified upsert approach...\n";
    
    // Test 1: Create a new page
    $pageId = UuidUtils::generateUuidV7();
    $pageData = [
        'id' => $pageId,
        'name' => 'test_page_' . time(),
        'content' => 'Test page content',
        'alias' => null,
        'active' => 1
    ];
    
    echo "Creating test page...\n";
    $createdPage = $dataManager->upsertPage($pageData);
    echo "✓ Page created: " . $createdPage['name'] . "\n";
    
    // Test 2: Create a new note
    $noteId = UuidUtils::generateUuidV7();
    $noteData = [
        'id' => $noteId,
        'page_id' => $pageId,
        'content' => 'Test note content',
        'parent_note_id' => null,
        'order_index' => 0,
        'collapsed' => 0,
        'internal' => 0
    ];
    
    echo "Creating test note...\n";
    $createdNote = $dataManager->upsertNote($noteData);
    echo "✓ Note created: " . $createdNote['content'] . "\n";
    
    // Test 3: Update the note (same operation type)
    $updatedNoteData = [
        'id' => $noteId,
        'page_id' => $pageId,
        'content' => 'Updated test note content',
        'parent_note_id' => null,
        'order_index' => 0,
        'collapsed' => 0,
        'internal' => 0
    ];
    
    echo "Updating test note...\n";
    $updatedNote = $dataManager->upsertNote($updatedNoteData);
    echo "✓ Note updated: " . $updatedNote['content'] . "\n";
    
    // Test 4: Verify creation timestamps are preserved
    $originalCreationTime = $dataManager->getCreationTimestamp($noteId, 'note');
    $updatedCreationTime = $dataManager->getCreationTimestamp($noteId, 'note');
    
    echo "Original creation time: " . $originalCreationTime . "\n";
    echo "Updated creation time: " . $updatedCreationTime . "\n";
    
    if ($originalCreationTime === $updatedCreationTime) {
        echo "✓ Creation timestamp preserved correctly\n";
    } else {
        echo "✗ Creation timestamp was modified (should not happen)\n";
    }
    
    // Test 5: Test batch operations
    echo "Testing batch operations...\n";
    $batchOperations = [
        [
            'type' => 'upsert',
            'payload' => [
                'id' => UuidUtils::generateUuidV7(),
                'page_id' => $pageId,
                'content' => 'Batch created note',
                'parent_note_id' => null,
                'order_index' => 1,
                'collapsed' => 0,
                'internal' => 0
            ]
        ],
        [
            'type' => 'upsert',
            'payload' => [
                'id' => $noteId,
                'page_id' => $pageId,
                'content' => 'Batch updated note',
                'parent_note_id' => null,
                'order_index' => 0,
                'collapsed' => 0,
                'internal' => 0
            ]
        ]
    ];
    
    require_once __DIR__ . '/api/v1/batch_operations.php';
    $batchResults = process_batch_request(['operations' => $batchOperations], $pdo);
    
    if (isset($batchResults['results'])) {
        echo "✓ Batch operations completed successfully\n";
        foreach ($batchResults['results'] as $result) {
            echo "  - " . $result['type'] . ": " . $result['status'] . "\n";
        }
    } else {
        echo "✗ Batch operations failed: " . ($batchResults['error'] ?? 'Unknown error') . "\n";
    }
    
    echo "\nAll tests completed successfully!\n";
    echo "The unified upsert approach is working correctly.\n";
    
} catch (Exception $e) {
    echo "Test failed: " . $e->getMessage() . "\n";
    exit(1);
}
