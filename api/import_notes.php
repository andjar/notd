<?php
require_once __DIR__ . '/../db/init.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['notes']) || !is_array($input['notes'])) {
        throw new Exception('Notes array is required');
    }
    
    $db = new SQLite3(__DIR__ . '/../db/notes.db');
    $db->exec('BEGIN TRANSACTION');
    
    $results = [];
    $errors = [];
    
    foreach ($input['notes'] as $note) {
        try {
            // Validate required fields
            if (!isset($note['content']) || !isset($note['page_id'])) {
                throw new Exception('Content and page_id are required for each note');
            }
            
            // Check if page exists, create if it doesn't
            $stmt = $db->prepare('SELECT id FROM pages WHERE id = :page_id');
            $stmt->bindValue(':page_id', $note['page_id'], SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if (!$result->fetchArray()) {
                // Create the page
                $stmt = $db->prepare('INSERT INTO pages (id, title, date_created) VALUES (:id, :title, :date)');
                $stmt->bindValue(':id', $note['page_id'], SQLITE3_TEXT);
                $stmt->bindValue(':title', $note['page_title'] ?? $note['page_id'], SQLITE3_TEXT);
                $stmt->bindValue(':date', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $stmt->execute();
            }
            
            // Insert the note
            $stmt = $db->prepare('
                INSERT INTO notes (id, page_id, content, date_created, favorite)
                VALUES (:id, :page_id, :content, :date, :favorite)
            ');
            
            $stmt->bindValue(':id', uniqid(), SQLITE3_TEXT);
            $stmt->bindValue(':page_id', $note['page_id'], SQLITE3_TEXT);
            $stmt->bindValue(':content', $note['content'], SQLITE3_TEXT);
            $stmt->bindValue(':date', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            $stmt->bindValue(':favorite', isset($note['favorite']) ? ($note['favorite'] ? 1 : 0) : 0, SQLITE3_INTEGER);
            
            $stmt->execute();
            
            $results[] = [
                'status' => 'success',
                'page_id' => $note['page_id']
            ];
            
        } catch (Exception $e) {
            $errors[] = [
                'error' => $e->getMessage(),
                'note' => $note
            ];
        }
    }
    
    if (empty($errors)) {
        $db->exec('COMMIT');
    } else {
        $db->exec('ROLLBACK');
    }
    
    echo json_encode([
        'success' => empty($errors),
        'results' => $results,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->exec('ROLLBACK');
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 