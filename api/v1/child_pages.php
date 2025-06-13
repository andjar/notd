<?php
// api/v1/child_pages.php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../api/db_connect.php';
require_once __DIR__ . '/../../api/response_utils.php';
require_once __DIR__ . '/../../api/data_manager.php';
require_once __DIR__ . '/../../api/validator_utils.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Only GET method is accepted.', 405);
    exit;
}

$namespace = $_GET['namespace'] ?? null;

if (!$namespace || !Validator::isNotEmpty($namespace)) {
    ApiResponse::error('Namespace parameter is required.', 400);
    exit;
}

try {
    $pdo = get_db_connection();
    $dataManager = new DataManager($pdo);
    
    $childPages = $dataManager->getChildPages($namespace);
    
    ApiResponse::success($childPages);
} catch (PDOException $e) {
    // Log the error for debugging
    error_log("Database error in child_pages.php: " . $e->getMessage());
    ApiResponse::error('Database error.', 500);
} catch (Exception $e) {
    error_log("Error in child_pages.php: " . $e->getMessage());
    ApiResponse::error('An unexpected error occurred.', 500);
} 