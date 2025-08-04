<?php
// Test ApiResponse directly
require_once 'config.php';
require_once 'api/response_utils.php';

echo "Testing ApiResponse directly\n";
echo "==========================\n\n";

try {
    echo "Testing ApiResponse::success():\n";
    \App\ApiResponse::success(['test' => 'data'], 200);
    echo "✓ ApiResponse::success() completed\n";
    
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} 