<?php
// Simulate the exact API call
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/DataManager.php';
require_once 'api/response_utils.php';
require_once 'api/uuid_utils.php';

use App\DataManager;

// Simulate the API environment
$_GET = ['name' => '2025-08-04'];
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "Simulating API call for pages.php?name=2025-08-04\n";
echo "================================================\n\n";

try {
    $pdo = get_db_connection();
    echo "✓ Database connection successful\n";
    
    $dataManager = new DataManager($pdo);
    echo "✓ DataManager initialized\n";
    
    // Simulate the exact API logic
    $pageName = $_GET['name'];
    echo "Page name: $pageName\n";
    
    $page = $dataManager->getPageByName($pageName);
    if (!$page) {
        echo "Page not found, creating it...\n";
        // Page does not exist, so create it.
        $pdo->beginTransaction();
        $pageId = \App\UuidUtils::generateUuidV7();
        $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (:id, :name, :content)");
        $stmt->execute([':id' => $pageId, ':name' => $pageName, ':content' => null]);
        $pdo->commit();
        $page = $dataManager->getPageByName($pageName); // Re-fetch the newly created page
        echo "Page created with ID: $pageId\n";
    }
    
    echo "✓ Page data retrieved successfully\n";
    echo "Page ID: " . $page['id'] . "\n";
    echo "Page Name: " . $page['name'] . "\n";
    
    // Test the API response
    echo "\nTesting ApiResponse::success():\n";
    \App\ApiResponse::success($page, 200);
    echo "✓ ApiResponse::success() completed\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} 