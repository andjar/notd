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

// Include notes API
require_once __DIR__ . '/api/v1/notes.php';

// Simulate the exact request that the frontend is sending
try {
    // Simulate the input that the frontend is sending
    $input = [
        'batch' => true,
        'operations' => [
            [
                'type' => 'create',
                'payload' => [
                    'id' => \App\UuidUtils::generateUuidV7(),
                    'page_id' => '019875a0-6544-7424-ac75-1f1632390134',
                    'content' => '',
                    'parent_note_id' => null,
                    'order_index' => 1
                ]
            ]
        ]
    ];
    
    echo "✓ Simulating frontend request\n";
    echo "✓ Input data: " . json_encode($input) . "\n";
    
    // Test the batch processing logic
    if (isset($input['batch']) && $input['batch'] === true) {
        echo "✓ Processing batch operations\n";
        $operations = $input['operations'] ?? [];
        echo "✓ Operations: " . json_encode($operations) . "\n";
        $includeParentProperties = (bool)($input['include_parent_properties'] ?? false);
        
        // Get database connection
        $pdo = get_db_connection();
        $dataManager = new \App\DataManager($pdo);
        
        // Process batch operations
        $results = _handleBatchOperations($pdo, $dataManager, $operations, $includeParentProperties);
        echo "✓ Results: " . json_encode($results) . "\n";
        
        // Clear any output that might have been sent
        ob_clean();
        
        // Test JSON output
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', 
            'message' => 'Batch operations simulation working',
            'results' => $results,
            'tests_passed' => true
        ]);
        
    } else {
        echo "✗ Not processing batch operations\n";
        echo "✗ batch field: " . (isset($input['batch']) ? 'set' : 'not set') . "\n";
        echo "✗ batch value: " . ($input['batch'] ?? 'null') . "\n";
        
        // Clear any output that might have been sent
        ob_clean();
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => 'Batch operations not recognized',
            'tests_passed' => false
        ]);
    }
    
} catch (Exception $e) {
    // Clear any output that might have been sent
    ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'message' => 'Batch operations simulation failed: ' . $e->getMessage(),
        'tests_passed' => false
    ]);
}

// End output buffering and send the response
ob_end_flush();
exit; 