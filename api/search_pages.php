<?php
header('Content-Type: application/json');

try {
    $db = new SQLite3(__DIR__ . '/../db/notes.db');
    
    $query = isset($_GET['q']) ? $_GET['q'] : '';
    if (empty($query)) {
        echo json_encode([]);
        exit;
    }

    $searchQuery = '%' . SQLite3::escapeString($query) . '%';
    
    $stmt = $db->prepare('
        SELECT DISTINCT p.id, p.title, p.created_at as date,
               CASE 
                   WHEN p.title LIKE :query THEN 1
                   WHEN n.content LIKE :query THEN 2
                   ELSE 3
               END as match_priority
        FROM pages p
        LEFT JOIN notes n ON p.id = n.page_id
        WHERE p.title LIKE :query 
        OR n.content LIKE :query
        ORDER BY match_priority, p.created_at DESC
        LIMIT 20
    ');
    
    $stmt->bindValue(':query', $searchQuery, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $pages = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $pages[] = [
            'id' => $row['id'],
            'title' => $row['title'],
            'date' => $row['date']
        ];
    }
    
    echo json_encode($pages);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 