<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    $db = new SQLite3('../db/notes.db');

    function getRecentPages() {
        global $db;
        
        $stmt = $db->prepare('
            SELECT r.page_id, r.last_opened, p.title
            FROM recent_pages r
            LEFT JOIN pages p ON r.page_id = p.id
            ORDER BY r.last_opened DESC
            LIMIT 7
        ');
        
        $result = $stmt->execute();
        $pages = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $pages[] = [
                'page_id' => $row['page_id'],
                'title' => $row['title'],
                'last_opened' => $row['last_opened']
            ];
        }
        
        return $pages;
    }

    function updateRecentPage($pageId) {
        global $db;
        
        // Delete existing entry if any
        $stmt = $db->prepare('DELETE FROM recent_pages WHERE page_id = :page_id');
        $stmt->bindValue(':page_id', $pageId, SQLITE3_TEXT);
        $stmt->execute();
        
        // Insert new entry
        $stmt = $db->prepare('
            INSERT INTO recent_pages (page_id, last_opened)
            VALUES (:page_id, CURRENT_TIMESTAMP)
        ');
        $stmt->bindValue(':page_id', $pageId, SQLITE3_TEXT);
        
        if (!$stmt->execute()) {
            return ['error' => 'Failed to update recent page'];
        }
        
        return ['success' => true];
    }

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            echo json_encode(getRecentPages());
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['page_id'])) {
                echo json_encode(['error' => 'Page ID required']);
                exit;
            }
            echo json_encode(updateRecentPage($data['page_id']));
            break;
            
        default:
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 