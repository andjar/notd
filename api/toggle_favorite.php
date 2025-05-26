<?php
// Prevent any output before headers
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['note_id'])) {
        throw new Exception('Note ID is required');
    }

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
    
    // First get current state
    $stmt = $db->prepare('SELECT is_favorite FROM notes WHERE id = :note_id');
    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . $db->lastErrorMsg());
    }
    
    $stmt->bindValue(':note_id', $input['note_id'], SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) {
        throw new Exception('Failed to execute query: ' . $db->lastErrorMsg());
    }
    
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        throw new Exception('Note not found');
    }
    
    // Toggle the favorite state
    $newState = !$row['is_favorite'];
    $stmt = $db->prepare('UPDATE notes SET is_favorite = :state WHERE id = :note_id');
    if (!$stmt) {
        throw new Exception('Failed to prepare update query: ' . $db->lastErrorMsg());
    }
    
    $stmt->bindValue(':state', $newState ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':note_id', $input['note_id'], SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) {
        throw new Exception('Failed to update favorite state: ' . $db->lastErrorMsg());
    }
    
    echo json_encode(['is_favorite' => $newState]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 