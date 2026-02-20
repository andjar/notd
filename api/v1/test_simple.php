<?php

// Disable error handlers before including config.php
set_error_handler(null);
set_exception_handler(null);

// Test with all includes
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../PatternProcessor.php';
require_once __DIR__ . '/../response_utils.php';

// Test calling get_db_connection
try {
    $pdo = get_db_connection();
    
    // Test DataManager
    $dataManager = new \App\DataManager($pdo);
    
    // Test a simple query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM Pages");
    $result = $stmt->fetch();
    
    // Test ApiResponse
    \App\ApiResponse::success(['status' => 'success', 'message' => 'PatternProcessor test successful', 'pages_count' => $result['count']]);
} catch (Exception $e) {
    \App\ApiResponse::error('Test failed: ' . $e->getMessage());
} 