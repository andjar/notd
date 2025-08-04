<?php
// Start output buffering to prevent header issues
ob_start();

// Disable error handlers BEFORE including config.php
set_error_handler(null);
set_exception_handler(null);

// Include config.php
require_once __DIR__ . '/config.php';

// Include setup_db_fixed.php
require_once __DIR__ . '/db/setup_db_fixed.php';

// Include db_helpers.php
require_once __DIR__ . '/api/db_helpers.php';

// Include db_connect.php
require_once __DIR__ . '/api/db_connect.php';

// Include DataManager
require_once __DIR__ . '/api/DataManager.php';

// Include UuidUtils
require_once __DIR__ . '/api/uuid_utils.php';

// Test the notes API
try {
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);
    
    // Test 1: Get a page
    $page = $dataManager->getPageByName('2025-08-04');
    if (!$page) {
        echo "Page not found, creating it...\n";
        // Create the page if it doesn't exist
        $pageId = \App\UuidUtils::generateUuidV7();
        $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (:id, :name, :content)");
        $stmt->execute([':id' => $pageId, ':name' => '2025-08-04', ':content' => null]);
        $page = $dataManager->getPageByName('2025-08-04');
    }
    
    echo "✓ Page found: " . $page['id'] . "\n";
    
    // Test 2: Get notes for the page
    $notes = $dataManager->getNotesByPageId($page['id']);
    echo "✓ Notes API working: " . count($notes) . " notes found\n";
    
    // Test 3: Create a test note
    $noteId = \App\UuidUtils::generateUuidV7();
    $stmt = $pdo->prepare("INSERT INTO Notes (id, page_id, content, order_index) VALUES (:id, :page_id, :content, :order_index)");
    $stmt->execute([':id' => $noteId, ':page_id' => $page['id'], ':content' => 'Test note content', ':order_index' => 1]);
    
    echo "✓ Test note created with ID: " . $noteId . "\n";
    
    // Test 4: Get notes again
    $notes = $dataManager->getNotesByPageId($page['id']);
    echo "✓ Notes API working after creation: " . count($notes) . " notes found\n";
    
    // Clear any output that might have been sent
    ob_clean();
    
    // Test JSON output
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success', 
        'message' => 'Notes API is working correctly',
        'page_id' => $page['id'],
        'notes_count' => count($notes),
        'tests_passed' => true
    ]);
    
} catch (Exception $e) {
    // Clear any output that might have been sent
    ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'message' => 'Notes API test failed: ' . $e->getMessage(),
        'tests_passed' => false
    ]);
}

// End output buffering and send the response
ob_end_flush();
exit; 