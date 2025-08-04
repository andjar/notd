<?php
// Debug script to test the pages API
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/DataManager.php';
require_once 'api/response_utils.php';

use App\DataManager;

echo "Testing Pages API Debug\n";
echo "=======================\n\n";

try {
    $pdo = get_db_connection();
    echo "✓ Database connection successful\n";
    
    $dataManager = new DataManager($pdo);
    echo "✓ DataManager initialized\n";
    
    // Test getting page by name
    $pageName = "2025-08-04";
    echo "\nTesting getPageByName('$pageName'):\n";
    
    $page = $dataManager->getPageByName($pageName);
    if ($page) {
        echo "✓ Page found:\n";
        echo "  ID: " . $page['id'] . "\n";
        echo "  Name: " . $page['name'] . "\n";
        echo "  Content: " . ($page['content'] ?? 'null') . "\n";
        echo "  Properties count: " . count($page['properties']) . "\n";
    } else {
        echo "✗ Page not found\n";
        
        // Check if page exists at all
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Pages WHERE LOWER(name) = LOWER(?)");
        $stmt->execute([$pageName]);
        $count = $stmt->fetchColumn();
        echo "  Total pages with this name: $count\n";
        
        // List all pages
        $stmt = $pdo->prepare("SELECT id, name, active FROM Pages WHERE LOWER(name) LIKE LOWER(?)");
        $stmt->execute([$pageName . '%']);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "  Similar pages:\n";
        foreach ($pages as $p) {
            echo "    - ID: {$p['id']}, Name: {$p['name']}, Active: {$p['active']}\n";
        }
    }
    
    // Test API response format
    echo "\nTesting API response format:\n";
    if ($page) {
        \App\ApiResponse::success($page, 200);
        echo "✓ API response sent successfully\n";
    } else {
        \App\ApiResponse::error('Page not found', 404);
        echo "✓ API error response sent successfully\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} 