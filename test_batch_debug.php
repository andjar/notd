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

// Include batch_operations
require_once __DIR__ . '/api/v1/batch_operations.php';

// Test the batch operations
try {
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);
    
    // Test with the known page ID
    $pageId = '019875a0-6544-7424-ac75-1f1632390134';
    
    // Create a test batch operation
    $testOperations = [
        [
            'type' => 'create',
            'payload' => [
                'id' => \App\UuidUtils::generateUuidV7(),
                'page_id' => $pageId,
                'content' => 'Test note from batch',
                'parent_note_id' => null,
                'order_index' => 1
            ]
        ]
    ];
    
    // Test the batch operations using the process_batch_request function
    $requestData = [
        'batch' => true,
        'operations' => $testOperations
    ];
    
    $results = process_batch_request($requestData, $pdo);
    
    echo "✓ Batch operations test completed\n";
    echo "✓ Results: " . json_encode($results) . "\n";
    
    // Clear any output that might have been sent
    ob_clean();
    
    // Test JSON output
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success', 
        'message' => 'Batch operations test working',
        'results' => $results,
        'tests_passed' => true
    ]);
    
} catch (Exception $e) {
    // Clear any output that might have been sent
    ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'message' => 'Batch operations test failed: ' . $e->getMessage(),
        'tests_passed' => false
    ]);
}

// End output buffering and send the response
ob_end_flush();
exit; 