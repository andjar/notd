<?php
// Prevent any output before headers
ob_start();

// Set error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

// Set JSON header
header('Content-Type: application/json');

try {
    $db = new SQLite3('../db/notes.db');
    if (!$db) {
        throw new Exception('Failed to connect to database: ' . SQLite3::lastErrorMsg());
    }

    function createNote($data) {
        global $db;
        
        error_log("Creating note with data: " . json_encode($data));
        
        $db->exec('BEGIN TRANSACTION');
        
        try {
            // Generate a unique block ID
            $blockId = uniqid('block_');
            
            // Insert the note
            $stmt = $db->prepare('
                INSERT INTO notes (page_id, content, level, parent_id, block_id)
                VALUES (:page_id, :content, :level, :parent_id, :block_id)
            ');
            
            if (!$stmt) {
                throw new Exception('Failed to prepare note insert: ' . $db->lastErrorMsg());
            }
            
            $stmt->bindValue(':page_id', $data['page_id'], SQLITE3_TEXT);
            $stmt->bindValue(':content', $data['content'], SQLITE3_TEXT);
            $stmt->bindValue(':level', $data['level'], SQLITE3_INTEGER);
            $stmt->bindValue(':parent_id', $data['parent_id'], SQLITE3_INTEGER);
            $stmt->bindValue(':block_id', $blockId, SQLITE3_TEXT);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to insert note: ' . $db->lastErrorMsg());
            }
            
            $noteId = $db->lastInsertRowID();
            
            // Insert properties if any
            if (!empty($data['properties'])) {
                $stmt = $db->prepare('
                    INSERT INTO properties (note_id, property_key, property_value)
                    VALUES (:note_id, :key, :value)
                ');
                
                if (!$stmt) {
                    throw new Exception('Failed to prepare properties insert: ' . $db->lastErrorMsg());
                }
                
                foreach ($data['properties'] as $key => $value) {
                    $stmt->bindValue(':note_id', $noteId, SQLITE3_INTEGER);
                    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
                    
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to insert property: ' . $db->lastErrorMsg());
                    }
                }
            }
            
            $db->exec('COMMIT');
            error_log("Note created successfully with ID: " . $noteId);
            return ['id' => $noteId, 'block_id' => $blockId];
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            error_log("Error creating note: " . $e->getMessage());
            throw $e;
        }
    }

    function updateNote($id, $data) {
        global $db;
        
        $db->exec('BEGIN TRANSACTION');
        
        try {
            // Update the note
            $stmt = $db->prepare('
                UPDATE notes
                SET content = :content,
                    level = :level,
                    parent_id = :parent_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ');
            
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->bindValue(':content', $data['content'], SQLITE3_TEXT);
            $stmt->bindValue(':level', $data['level'], SQLITE3_INTEGER);
            $stmt->bindValue(':parent_id', $data['parent_id'], SQLITE3_INTEGER);
            
            $stmt->execute();
            
            // Update properties
            if (isset($data['properties'])) {
                // Delete existing properties
                $stmt = $db->prepare('DELETE FROM properties WHERE note_id = :note_id');
                $stmt->bindValue(':note_id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                
                // Insert new properties
                if (!empty($data['properties'])) {
                    $stmt = $db->prepare('
                        INSERT INTO properties (note_id, property_key, property_value)
                        VALUES (:note_id, :key, :value)
                    ');
                    
                    foreach ($data['properties'] as $key => $value) {
                        $stmt->bindValue(':note_id', $id, SQLITE3_INTEGER);
                        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
                        $stmt->execute();
                    }
                }
            }
            
            $db->exec('COMMIT');
            return ['success' => true];
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            return ['error' => $e->getMessage()];
        }
    }

    function deleteNote($id) {
        global $db;
        
        $db->exec('BEGIN TRANSACTION');
        
        try {
            // Delete the note and all its children
            $stmt = $db->prepare('
                WITH RECURSIVE note_tree AS (
                    SELECT id FROM notes WHERE id = :id
                    UNION ALL
                    SELECT n.id FROM notes n
                    JOIN note_tree nt ON n.parent_id = nt.id
                )
                DELETE FROM notes WHERE id IN note_tree
            ');
            
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            $db->exec('COMMIT');
            return ['success' => true];
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            return ['error' => $e->getMessage()];
        }
    }

    // Handle the request
    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    error_log("Request received - Method: $method, ID: $id");

    if ($method === 'POST') {
        $input = file_get_contents('php://input');
        error_log("Received input: " . $input);
        
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }
        
        if (!$data) {
            throw new Exception('Invalid data');
        }

        $action = $data['action'] ?? 'create';
        
        switch ($action) {
            case 'create':
                $result = createNote($data);
                break;
                
            case 'update':
                if (!$id) {
                    throw new Exception('Note ID required');
                }
                $result = updateNote($id, $data);
                break;
                
            case 'delete':
                if (!$id) {
                    throw new Exception('Note ID required');
                }
                $result = deleteNote($id);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
        error_log("Sending response: " . json_encode($result));
        echo json_encode($result);
    } else {
        throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    error_log("Error in note.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $error = ['error' => $e->getMessage()];
    error_log("Sending error response: " . json_encode($error));
    echo json_encode($error);
} finally {
    if (isset($db)) {
        $db->close();
    }
    // End output buffering and send the response
    ob_end_flush();
}
?> 