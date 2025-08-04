<?php

namespace App;

// api/v1/child_pages.php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../validator_utils.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    \App\ApiResponse::error('Only GET method is accepted.', 405);
    exit;
}

$namespace = $_GET['namespace'] ?? null;

if (!$namespace || !Validator::isNotEmpty($namespace)) {
    \App\ApiResponse::error('Namespace parameter is required.', 400);
    exit;
}

try {
    $pdo = get_db_connection();
    $dataManager = new \App\DataManager($pdo);
    
    $childPages = $dataManager->getChildPages($namespace);
    
    \App\ApiResponse::success($childPages);
} catch (PDOException $e) {
    \App\ApiResponse::error('Database error.', 500);
} catch (Exception $e) {
    \App\ApiResponse::error('An unexpected error occurred.', 500);
} 