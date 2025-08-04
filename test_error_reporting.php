<?php
// Test with error reporting enabled
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing with error reporting enabled\n";
echo "==================================\n\n";

try {
    echo "Step 1: Including config.php\n";
    require_once 'config.php';
    echo "✓ config.php included successfully\n";
    
    echo "Step 2: Including db_connect.php\n";
    require_once 'api/db_connect.php';
    echo "✓ db_connect.php included successfully\n";
    
    echo "Step 3: Including DataManager.php\n";
    require_once 'api/DataManager.php';
    echo "✓ DataManager.php included successfully\n";
    
    echo "Step 4: Including response_utils.php\n";
    require_once 'api/response_utils.php';
    echo "✓ response_utils.php included successfully\n";
    
    echo "Step 5: Testing database connection\n";
    $pdo = get_db_connection();
    echo "✓ Database connection successful\n";
    
    echo "Step 6: Testing DataManager\n";
    $dataManager = new \App\DataManager($pdo);
    echo "✓ DataManager initialized successfully\n";
    
    echo "Step 7: Testing getPageByName\n";
    $page = $dataManager->getPageByName("2025-08-04");
    if ($page) {
        echo "✓ Page found: " . $page['name'] . "\n";
    } else {
        echo "✗ Page not found\n";
    }
    
    echo "Step 8: Testing JSON encoding\n";
    $json = json_encode($page);
    if ($json === false) {
        echo "✗ JSON encoding failed: " . json_last_error_msg() . "\n";
    } else {
        echo "✓ JSON encoding successful\n";
    }
    
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} 