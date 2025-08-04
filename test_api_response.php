<?php
// Test the exact API response format
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/DataManager.php';
require_once 'api/response_utils.php';

use App\DataManager;

// Simulate the API request
$pdo = get_db_connection();
$dataManager = new DataManager($pdo);

$pageName = "2025-08-04";
$page = $dataManager->getPageByName($pageName);

if ($page) {
    // This is what the API should return
    \App\ApiResponse::success($page, 200);
} else {
    \App\ApiResponse::error('Page not found', 404);
} 