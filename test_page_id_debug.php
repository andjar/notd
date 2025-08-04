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

// Test the page loading
try {
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);
    
    // Test with the known page name
    $pageName = '2025-08-04';
    
    // Test 1: Get page by name
    $page = $dataManager->getPageByName($pageName);
    if ($page) {
        echo "✓ Page found: " . $page['name'] . " (ID: " . $page['id'] . ")\n";
        
        // Test 2: Get notes for the page
        $notes = $dataManager->getNotesByPageId($page['id']);
        echo "✓ Notes found: " . count($notes) . " notes\n";
        
        // Test 3: Simulate the page data structure that should be sent to frontend
        $pageData = [
            'id' => $page['id'],
            'name' => $page['name'],
            'notes' => $notes,
            'properties' => $page['properties'] ?? []
        ];
        
        echo "✓ Page data structure:\n";
        echo "  - ID: " . $pageData['id'] . "\n";
        echo "  - Name: " . $pageData['name'] . "\n";
        echo "  - Notes count: " . count($pageData['notes']) . "\n";
        echo "  - Properties count: " . count($pageData['properties']) . "\n";
        
        // Clear any output that might have been sent
        ob_clean();
        
        // Test JSON output
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', 
            'message' => 'Page ID debug test working',
            'page_data' => $pageData,
            'tests_passed' => true
        ]);
        
    } else {
        echo "✗ Page not found: " . $pageName . "\n";
        
        // Clear any output that might have been sent
        ob_clean();
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => 'Page not found: ' . $pageName,
            'tests_passed' => false
        ]);
    }
    
} catch (Exception $e) {
    // Clear any output that might have been sent
    ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'message' => 'Page ID debug test failed: ' . $e->getMessage(),
        'tests_passed' => false
    ]);
}

// End output buffering and send the response
ob_end_flush();
exit; 