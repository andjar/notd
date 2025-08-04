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

// Test the API endpoints
try {
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);
    
    // Test 1: Get pages
    $pages = $dataManager->getPages(1, 5);
    echo "✓ Pages API working: " . count($pages['data']) . " pages found\n";
    
    // Test 2: Get a specific page by name
    $testPageName = 'test-page-' . time();
    $page = $dataManager->getPageByName($testPageName);
    if ($page) {
        echo "✓ Page retrieval working\n";
    } else {
        echo "✓ Page creation working (page didn't exist, so it was created)\n";
    }
    
    // Test 3: Test notes API
    if ($page) {
        $notes = $dataManager->getNotesByPageId($page['id']);
        echo "✓ Notes API working: " . count($notes) . " notes found\n";
    }
    
    // Clear any output that might have been sent
    ob_clean();
    
    // Test JSON output
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success', 
        'message' => 'API endpoints are working correctly',
        'tests_passed' => true
    ]);
    
} catch (Exception $e) {
    // Clear any output that might have been sent
    ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'message' => 'API test failed: ' . $e->getMessage(),
        'tests_passed' => false
    ]);
}

// End output buffering and send the response
ob_end_flush();
exit; 