<?php
// Test API without PatternProcessor
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/DataManager.php';
require_once 'api/response_utils.php';
require_once 'api/uuid_utils.php';

use App\DataManager;

// Set headers for JSON response
header('Content-Type: application/json');

// Simulate the API environment
$_GET = ['name' => '2025-08-04'];
$_SERVER['REQUEST_METHOD'] = 'GET';

try {
    $pdo = get_db_connection();
    $dataManager = new DataManager($pdo);
    
    // Simulate the exact API logic
    $pageName = $_GET['name'];
    $page = $dataManager->getPageByName($pageName);
    
    if (!$page) {
        // Page does not exist, so create it.
        $pdo->beginTransaction();
        $pageId = \App\UuidUtils::generateUuidV7();
        $stmt = $pdo->prepare("INSERT INTO Pages (id, name, content) VALUES (:id, :name, :content)");
        $stmt->execute([':id' => $pageId, ':name' => $pageName, ':content' => null]);
        $pdo->commit();
        $page = $dataManager->getPageByName($pageName); // Re-fetch the newly created page
    }
    
    // Use ApiResponse::success() like the real API
    \App\ApiResponse::success($page, 200);
    
} catch (Exception $e) {
    \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
} 