<?php
// Direct API test to identify the issue
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/DataManager.php';
require_once 'api/response_utils.php';

// Disable error display for this test
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

echo "Testing direct API calls...\n\n";

try {
    // Test database connection
    echo "1. Testing database connection...\n";
    $pdo = get_db_connection();
    echo "✓ Database connection successful\n\n";
    
    // Test DataManager
    echo "2. Testing DataManager...\n";
    $dataManager = new \App\DataManager($pdo);
    echo "✓ DataManager instantiated successfully\n\n";
    
    // Test getting a page
    echo "3. Testing page retrieval...\n";
    $pageName = '2025-08-04';
    $page = $dataManager->getPageByName($pageName);
    if ($page) {
        echo "✓ Page found: " . $page['name'] . "\n";
    } else {
        echo "✓ Page not found (will be created by API)\n";
    }
    echo "\n";
    
    // Test API response format
    echo "4. Testing API response format...\n";
    ob_start();
    \App\ApiResponse::success($page ?: ['name' => $pageName, 'content' => null]);
    $response = ob_get_clean();
    
    echo "Response headers should be set by ApiResponse\n";
    echo "Response body: " . substr($response, 0, 200) . "\n\n";
    
    // Test JSON parsing
    $decoded = json_decode($response, true);
    if ($decoded) {
        echo "✓ JSON response is valid\n";
        echo "Response structure: " . json_encode(array_keys($decoded)) . "\n\n";
    } else {
        echo "✗ JSON response is invalid\n";
        echo "JSON error: " . json_last_error_msg() . "\n\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?> 