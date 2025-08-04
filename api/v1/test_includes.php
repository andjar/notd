<?php
// Test includes step by step
echo "Step 1: Starting\n";

try {
    echo "Step 2: Including config.php\n";
    require_once __DIR__ . '/../../config.php';
    echo "✓ config.php included successfully\n";
    
    echo "Step 3: Including db_connect.php\n";
    require_once __DIR__ . '/../db_connect.php';
    echo "✓ db_connect.php included successfully\n";
    
    echo "Step 4: Including DataManager.php\n";
    require_once __DIR__ . '/../DataManager.php';
    echo "✓ DataManager.php included successfully\n";
    
    echo "Step 5: Including response_utils.php\n";
    require_once __DIR__ . '/../response_utils.php';
    echo "✓ response_utils.php included successfully\n";
    
    echo "Step 6: Testing database connection\n";
    $pdo = get_db_connection();
    echo "✓ Database connection successful\n";
    
    echo "Step 7: Testing DataManager\n";
    $dataManager = new \App\DataManager($pdo);
    echo "✓ DataManager initialized successfully\n";
    
    echo "Step 8: Testing getPageByName\n";
    $page = $dataManager->getPageByName("2025-08-04");
    if ($page) {
        echo "✓ Page found: " . $page['name'] . "\n";
    } else {
        echo "✗ Page not found\n";
    }
    
    echo "Step 9: Testing ApiResponse\n";
    \App\ApiResponse::success(['test' => 'data'], 200);
    echo "✓ ApiResponse::success() completed\n";
    
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} 