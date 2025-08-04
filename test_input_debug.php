<?php
// Start output buffering to prevent header issues
ob_start();

// Disable error handlers BEFORE including config.php
set_error_handler(null);
set_exception_handler(null);

// Include config.php
require_once __DIR__ . '/config.php';

// Test what input data is being received
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    $rawInput = file_get_contents('php://input');
    
    echo "✓ Method: " . $method . "\n";
    echo "✓ Raw input: " . $rawInput . "\n";
    echo "✓ Decoded input: " . json_encode($input) . "\n";
    echo "✓ Input type: " . gettype($input) . "\n";
    
    if ($input) {
        echo "✓ Input keys: " . implode(', ', array_keys($input)) . "\n";
        if (isset($input['batch'])) {
            echo "✓ Batch field: " . ($input['batch'] ? 'true' : 'false') . "\n";
        } else {
            echo "✗ Batch field not found\n";
        }
        if (isset($input['operations'])) {
            echo "✓ Operations count: " . count($input['operations']) . "\n";
        } else {
            echo "✗ Operations field not found\n";
        }
    } else {
        echo "✗ No input data received\n";
    }
    
    // Clear any output that might have been sent
    ob_clean();
    
    // Test JSON output
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success', 
        'message' => 'Input debug test completed',
        'method' => $method,
        'raw_input' => $rawInput,
        'decoded_input' => $input,
        'tests_passed' => true
    ]);
    
} catch (Exception $e) {
    // Clear any output that might have been sent
    ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error', 
        'message' => 'Input debug test failed: ' . $e->getMessage(),
        'tests_passed' => false
    ]);
}

// End output buffering and send the response
ob_end_flush();
exit; 