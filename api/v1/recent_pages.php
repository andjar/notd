<?php
require_once '../db_connect.php';
require_once '../response_utils.php';

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = get_db_connection();
    
    // Get recent pages (last 7 updated pages)
    $stmt = $pdo->prepare("SELECT id, name, updated_at FROM Pages WHERE active = 1 ORDER BY updated_at DESC LIMIT 7");
    $stmt->execute();
    $recentPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare response
    $response = [
        'recent_pages' => $recentPages
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?> 