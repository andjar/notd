<?php
// Test DataManager directly
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/DataManager.php';

use App\DataManager;

echo "Testing DataManager Directly\n";
echo "===========================\n\n";

try {
    $pdo = get_db_connection();
    echo "✓ Database connection successful\n";
    
    $dataManager = new DataManager($pdo);
    echo "✓ DataManager initialized\n";
    
    $pageName = "2025-08-04";
    echo "\nTesting getPageByName('$pageName'):\n";
    
    $page = $dataManager->getPageByName($pageName);
    if ($page) {
        echo "✓ Page found:\n";
        echo "  ID: " . $page['id'] . "\n";
        echo "  Name: " . $page['name'] . "\n";
        echo "  Content: " . ($page['content'] ?? 'null') . "\n";
        echo "  Properties count: " . count($page['properties']) . "\n";
        
        // Test JSON encoding
        $json = json_encode($page);
        if ($json === false) {
            echo "✗ JSON encoding failed: " . json_last_error_msg() . "\n";
        } else {
            echo "✓ JSON encoding successful\n";
            echo "JSON length: " . strlen($json) . " characters\n";
        }
        
        // Test API response format
        echo "\nTesting API response format:\n";
        $response = [
            'status' => 'success',
            'data' => $page
        ];
        $jsonResponse = json_encode($response);
        if ($jsonResponse === false) {
            echo "✗ API response JSON encoding failed: " . json_last_error_msg() . "\n";
        } else {
            echo "✓ API response JSON encoding successful\n";
            echo "Response length: " . strlen($jsonResponse) . " characters\n";
        }
        
    } else {
        echo "✗ Page not found\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} 