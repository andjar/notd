<?php
// Minimal test of pages.php
error_log("DEBUG: pages_minimal.php is being executed");

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../config.php';
    error_log("DEBUG: config.php included");
    
    require_once __DIR__ . '/../db_connect.php';
    error_log("DEBUG: db_connect.php included");
    
    require_once __DIR__ . '/../DataManager.php';
    error_log("DEBUG: DataManager.php included");
    
    require_once __DIR__ . '/../response_utils.php';
    error_log("DEBUG: response_utils.php included");
    
    $pdo = get_db_connection();
    error_log("DEBUG: Database connection successful");
    
    $dataManager = new \App\DataManager($pdo);
    error_log("DEBUG: DataManager initialized");
    
    $pageName = $_GET['name'] ?? 'test';
    error_log("DEBUG: Looking for page: $pageName");
    
    $page = $dataManager->getPageByName($pageName);
    error_log("DEBUG: getPageByName completed");
    
    if ($page) {
        error_log("DEBUG: Page found, sending success response");
        \App\ApiResponse::success($page, 200);
    } else {
        error_log("DEBUG: Page not found, sending error response");
        \App\ApiResponse::error('Page not found', 404);
    }
    
} catch (Exception $e) {
    error_log("DEBUG: Exception caught: " . $e->getMessage());
    \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
} catch (Error $e) {
    error_log("DEBUG: Error caught: " . $e->getMessage());
    \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
} 