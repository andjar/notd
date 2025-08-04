<?php
// Minimal API test - bypass all includes
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/DataManager.php';
require_once 'api/response_utils.php';

use App\DataManager;

// Set headers for JSON response
header('Content-Type: application/json');

try {
    $pdo = get_db_connection();
    $dataManager = new DataManager($pdo);
    
    $pageName = "2025-08-04";
    $page = $dataManager->getPageByName($pageName);
    
    if ($page) {
        $response = [
            'status' => 'success',
            'data' => $page
        ];
    } else {
        $response = [
            'status' => 'error',
            'message' => 'Page not found'
        ];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $response = [
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ];
    echo json_encode($response);
} 