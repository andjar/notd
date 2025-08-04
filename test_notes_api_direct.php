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

// Test the notes API directly
try {
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);
    
    // Test with the known page ID
    $pageId = '019875a0-6544-7424-ac75-1f1632390134';
    
    // Test 1: Get notes for the page
    $notes = $dataManager->getNotesByPageId($pageId);
    echo "✓ Notes API working: " . count($notes) . " notes found\n";
    
    // Test 2: Check if the page exists
    $page = $dataManager->getPageById($pageId);
    if ($page) {
        echo "✓ Page exists: " . $page['name'] . "\n";
    } else {
        echo "✗ Page not found\n";
    }
    
    // Clear any output that might have been sent
    ob_clean();
    
    // Test JSON output
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success', 
        'message' => 'Notes API direct test working',
        'notes_count' => count($notes),
        'page_exists' => $page ? true : false,
        'tests_passed' => true
    ]);
    
} catch (Exception $e) {
    // Clear any output that might have been sent
    ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'message' => 'Notes API direct test failed: ' . $e->getMessage(),
        'tests_passed' => false
    ]);
}

// End output buffering and send the response
ob_end_flush();
exit; 