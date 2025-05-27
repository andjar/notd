<?php
// Prevent any output before headers
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    $db = new SQLite3(__DIR__ . '/../db/notes.db');
    if (!$db) {
        throw new Exception('Failed to connect to database');
    }
    
    // Check if is_favorite column exists
    $result = $db->query("PRAGMA table_info(notes)");
    if (!$result) {
        throw new Exception('Failed to check table structure');
    }
    
    $hasFavoriteColumn = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === 'is_favorite') {
            $hasFavoriteColumn = true;
            break;
        }
    }
    
    // Add is_favorite column if it doesn't exist
    if (!$hasFavoriteColumn) {
        $result = $db->exec('ALTER TABLE notes ADD COLUMN is_favorite INTEGER DEFAULT 0');
        if (!$result) {
            throw new Exception('Failed to add is_favorite column: ' . $db->lastErrorMsg());
        }
    }
    
    // Get all favorite notes with their page information
    $stmt = $db->prepare('
        SELECT n.id, n.content, n.page_id, p.title as page_title
        FROM notes n
        JOIN pages p ON n.page_id = p.id
        WHERE n.is_favorite = 1
        ORDER BY n.created_at DESC
    ');
    
    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . $db->lastErrorMsg());
    }
    
    $result = $stmt->execute();
    if (!$result) {
        throw new Exception('Failed to execute query: ' . $db->lastErrorMsg());
    }
    
    $favorites = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $favorites[] = [
            'id' => $row['id'],
            'content' => $row['content'],
            'page_id' => $row['page_id'],
            'page_title' => $row['page_title']
        ];
    }
    
    echo json_encode($favorites);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 