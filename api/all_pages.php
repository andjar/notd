<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

try {
    $db = new SQLite3('../db/notes.db');
    if (!$db) {
        throw new Exception('Failed to connect to database: ' . SQLite3::lastErrorMsg());
    }

    // Get all pages with their properties
    $stmt = $db->prepare('
        SELECT 
            p.id,
            p.title,
            p.type,
            p.created_at,
            p.updated_at,
            GROUP_CONCAT(DISTINCT prop.property_key || ":" || prop.property_value) as properties
        FROM pages p
        LEFT JOIN properties prop ON p.id = prop.page_id
        GROUP BY p.id
        ORDER BY p.updated_at DESC
    ');
    
    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . $db->lastErrorMsg());
    }
    
    $result = $stmt->execute();
    if (!$result) {
        throw new Exception('Failed to execute query: ' . $db->lastErrorMsg());
    }
    
    $pages = [];
    while ($page = $result->fetchArray(SQLITE3_ASSOC)) {
        // Parse properties
        $properties = [];
        if ($page['properties']) {
            foreach (explode(',', $page['properties']) as $prop) {
                list($key, $value) = explode(':', $prop, 2);
                $properties[$key] = $value;
            }
        }
        $page['properties'] = $properties;
        
        // Add to pages array
        $pages[] = [
            'id' => $page['id'],
            'title' => $page['title'],
            'type' => $page['type'],
            'created_at' => $page['created_at'],
            'updated_at' => $page['updated_at'],
            'properties' => $properties
        ];
    }
    
    echo json_encode($pages);
} catch (Exception $e) {
    error_log("Error in all_pages.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 