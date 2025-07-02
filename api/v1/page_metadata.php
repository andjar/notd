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

$pageName = isset($_GET['page']) ? trim($_GET['page']) : null;

if (empty($pageName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Page name is required']);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    
    // Get page data
    $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?) AND active = 1");
    $stmt->execute([$pageName]);
    $pageData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pageData) {
        http_response_code(404);
        echo json_encode(['error' => 'Page not found']);
        exit;
    }
    
    // Get page properties
    $stmt = $pdo->prepare("SELECT name, value, weight FROM Properties WHERE page_id = ? AND active = 1 ORDER BY created_at ASC");
    $stmt->execute([$pageData['id']]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pageProperties = [];
    foreach ($properties as $prop) {
        $key = $prop['name'];
        if (!isset($pageProperties[$key])) {
            $pageProperties[$key] = [];
        }
        $pageProperties[$key][] = [
            'value' => $prop['value'],
            'internal' => (int)($prop['weight'] ?? 2) > 2
        ];
    }
    
    // Get recent pages (last 7 updated pages)
    $stmt = $pdo->prepare("SELECT id, name, updated_at FROM Pages WHERE active = 1 ORDER BY updated_at DESC LIMIT 7");
    $stmt->execute();
    $recentPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get favorites
    $stmt = $pdo->prepare("SELECT DISTINCT P.id, P.name, P.updated_at FROM Pages P JOIN Properties Prop ON P.id = Prop.page_id WHERE Prop.name = 'favorite' AND Prop.value = 'true' AND P.active = 1 ORDER BY P.updated_at DESC");
    $stmt->execute();
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get child pages
    $prefix = rtrim($pageName, '/') . '/';
    $stmt = $pdo->prepare("SELECT id, name, updated_at FROM Pages WHERE LOWER(name) LIKE LOWER(?) || '%' AND SUBSTR(LOWER(name), LENGTH(LOWER(?)) + 1) NOT LIKE '%/%' AND active = 1 ORDER BY name ASC");
    $stmt->execute([$prefix, $prefix]);
    $childPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get backlinks
    $stmt = $pdo->prepare("SELECT N.id as note_id, N.content, N.page_id, P.name as page_name FROM Properties Prop JOIN Notes N ON Prop.note_id = N.id JOIN Pages P ON N.page_id = P.id WHERE Prop.name = 'links_to_page' AND Prop.value = ? GROUP BY N.id ORDER BY N.updated_at DESC LIMIT 10");
    $stmt->execute([$pageName]);
    $backlinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare response
    $response = [
        'page_name' => $pageData['name'],
        'page_id' => $pageData['id'],
        'properties' => $pageProperties,
        'recent_pages' => $recentPages,
        'favorites' => $favorites,
        'child_pages' => $childPages,
        'backlinks' => $backlinks
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Database error in page_metadata.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    error_log("Error in page_metadata.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?> 