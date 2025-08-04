<?php
// Start output buffering to prevent header issues
ob_start();

// Disable error handlers BEFORE including config.php
set_error_handler(null);
set_exception_handler(null);

// Include config.php
require_once __DIR__ . '/config.php';

// Include setup_db_fixed.php
require_once __DIR__ . '/db/setup_db_fixed.php';

// Include db_helpers.php
require_once __DIR__ . '/api/db_helpers.php';

// Include db_connect.php
require_once __DIR__ . '/api/db_connect.php';

// Test calling get_db_connection()
try {
    $pdo = get_db_connection();
    
    // Test a simple query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM Pages");
    $result = $stmt->fetch();
    
    // Clear any output that might have been sent
    ob_clean();
    
    // Test JSON output
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Database connection successful', 'pages_count' => $result['count']]);
} catch (Exception $e) {
    // Clear any output that might have been sent
    ob_clean();
    
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
}

// End output buffering and send the response
ob_end_flush();
exit; 