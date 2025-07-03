<?php
require_once 'api/db_connect.php';

try {
    $pdo = get_db_connection();
    
    // Check if Attachments table exists
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='Attachments'");
    $tableExists = $stmt->fetch();
    
    if (!$tableExists) {
        echo "Attachments table does not exist!\n";
        exit;
    }
    
    // Count attachments
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM Attachments");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total attachments in database: " . $result['count'] . "\n";
    
    // Show some sample attachments if any exist
    if ($result['count'] > 0) {
        $stmt = $pdo->query("SELECT id, name, type, size, created_at FROM Attachments LIMIT 5");
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nSample attachments:\n";
        foreach ($attachments as $att) {
            echo "- ID: {$att['id']}, Name: {$att['name']}, Type: {$att['type']}, Size: {$att['size']}\n";
        }
    } else {
        echo "No attachments found in database.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 