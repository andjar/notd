<?php
// CLI SAPI specific adaptations
if (php_sapi_name() == 'cli') {
    // Allow REQUEST_METHOD to be set via environment variable for CLI testing
    if (getenv('REQUEST_METHOD')) {
        $_SERVER['REQUEST_METHOD'] = getenv('REQUEST_METHOD');
    } else {
        // Default to GET if not specified for CLI
        $_SERVER['REQUEST_METHOD'] = 'GET'; 
    }

    // For POST requests in CLI, capture payload from environment variable PHP_INPUT_PAYLOAD
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && getenv('PHP_INPUT_PAYLOAD')) {
        $GLOBALS['cliInputPayload'] = getenv('PHP_INPUT_PAYLOAD');
    }

    // Populate $_GET from command line arguments (e.g., for id=value)
    // This should run for any CLI request type if $argv is present.
    // This ensures $_GET['id'] is available for update/delete actions even in POST.
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg_idx => $arg_val) {
            if ($arg_idx == 0) continue; // skip script name itself
            if (strpos($arg_val, '=') !== false) {
                list($key, $value) = explode('=', $arg_val, 2);
                $_GET[$key] = $value; // Populate $_GET for CLI
            }
        }
    }
}

// Prevent any output before headers
ob_start();

// Set error handling
error_reporting(E_ALL);
ini_set('display_errors', 0); // Display errors are off, check log file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // Use absolute path for log

// Set JSON header
header('Content-Type: application/json');

try {
    $db = new SQLite3(__DIR__ . '/../db/notes.db'); // Use absolute path for database
    if (!$db) {
        throw new Exception('Failed to connect to database: ' . SQLite3::lastErrorMsg());
    }
    // Enable foreign key constraints for this connection
    if (!$db->exec('PRAGMA foreign_keys = ON;')) {
        // Log or handle error if PRAGMA command fails, though it usually doesn't throw on its own.
        // Check $db->lastErrorCode() or $db->lastErrorMsg() if needed, but for now, assume it works or rely on subsequent FK errors.
        error_log("Notice: Attempted to enable foreign_keys. Check SQLite logs if issues persist with FKs.");
    }

    // Helper function to parse content and update page_links table
    function _updatePageLinks($noteId, $sourcePageId, $content) {
        global $db;

        // Delete existing links for this note
        $stmt = $db->prepare('DELETE FROM page_links WHERE source_note_id = :note_id');
        if (!$stmt) {
            throw new Exception('Failed to prepare delete page_links statement: ' . $db->lastErrorMsg());
        }
        $stmt->bindValue(':note_id', $noteId, SQLITE3_INTEGER);
        if (!$stmt->execute()) {
            throw new Exception('Failed to delete page_links: ' . $db->lastErrorMsg());
        }

        // Parse content for new links
        // Regex allows alphanumeric, hyphens, underscores, and spaces within [[...]]
        if (preg_match_all('/\[\[([a-zA-Z0-9_\-\s]+)\]\]/', $content, $matches)) {
            $targetPageIds = array_unique($matches[1]); // Get unique target page IDs

            if (!empty($targetPageIds)) {
                $stmt = $db->prepare('
                    INSERT INTO page_links (source_page_id, target_page_id, source_note_id)
                    VALUES (:source_page_id, :target_page_id, :source_note_id)
                ');
                if (!$stmt) {
                    throw new Exception('Failed to prepare insert page_links statement: ' . $db->lastErrorMsg());
                }

                foreach ($targetPageIds as $targetPageId) {
                    // Future enhancement: Normalize targetPageId (e.g. trim, replace multiple spaces)
                    // For now, using it as extracted.
                    $stmt->bindValue(':source_page_id', $sourcePageId, SQLITE3_TEXT);
                    $stmt->bindValue(':target_page_id', trim($targetPageId), SQLITE3_TEXT); // Trim the target ID
                    $stmt->bindValue(':source_note_id', $noteId, SQLITE3_INTEGER);
                    
                    if (!$stmt->execute()) {
                        // If a target_page_id doesn't exist in 'pages', this will fail due to FK constraint.
                        // This is acceptable as per requirements.
                        throw new Exception('Failed to insert page_link for target "' . $targetPageId . '": ' . $db->lastErrorMsg());
                    }
                }
            }
        }
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

            // Update page links
            if (isset($data['content'])) {
                _updatePageLinks($noteId, $data['page_id'], $data['content']);
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
            // Fetch current page_id for the note, as it's needed for page_links
            // and might not be in $data or could be part of a move operation (not currently supported by this update)
            $pageIdStmt = $db->prepare('SELECT page_id FROM notes WHERE id = :note_id');
            if (!$pageIdStmt) {
                throw new Exception('Failed to prepare statement to fetch page_id for note: ' . $db->lastErrorMsg());
            }
            $pageIdStmt->bindValue(':note_id', $id, SQLITE3_INTEGER);
            $pageIdResult = $pageIdStmt->execute();
            if (!$pageIdResult) {
                throw new Exception('Failed to execute statement to fetch page_id for note: ' . $db->lastErrorMsg());
            }
            $noteDataRow = $pageIdResult->fetchArray(SQLITE3_ASSOC);
            if (!$noteDataRow) {
                throw new Exception('Note not found with ID: ' . $id);
            }
            $sourcePageId = $noteDataRow['page_id'];

            // Prepare fields to update
            $updateFields = [];
            if (isset($data['content'])) $updateFields[] = "content = :content";
            if (isset($data['level'])) $updateFields[] = "level = :level";
            if (isset($data['parent_id'])) $updateFields[] = "parent_id = :parent_id";

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
                $sql = 'UPDATE notes SET ' . implode(', ', $updateFields) . ' WHERE id = :id';
                
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare note update: ' . $db->lastErrorMsg());
                }

                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                if (isset($data['content'])) $stmt->bindValue(':content', $data['content'], SQLITE3_TEXT);
                if (isset($data['level'])) $stmt->bindValue(':level', $data['level'], SQLITE3_INTEGER);
                if (isset($data['parent_id'])) $stmt->bindValue(':parent_id', $data['parent_id'], SQLITE3_INTEGER); // parent_id can be null

                if (!$stmt->execute()) {
                    throw new Exception('Failed to update note: ' . $db->lastErrorMsg());
                }
            }
            
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

            // Update page links if content was part of the update
            // The _updatePageLinks function handles deletion of old links first
            if (isset($data['content'])) {
                _updatePageLinks($id, $sourcePageId, $data['content']);
            }
            
            $db->exec('COMMIT');
            error_log("Note updated successfully with ID: " . $id);
            return ['success' => true];
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            error_log("Error updating note " . $id . ": " . $e->getMessage());
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
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete note(s): ' . $db->lastErrorMsg());
            }

            // Also delete links originating from these notes
            // This is important because ON DELETE CASCADE for notes(id) in page_links
            // will only trigger if the note itself is deleted.
            // If a parent note is deleted, its children are deleted by the CTE,
            // so we need to explicitly clean up their links.
            $stmt = $db->prepare('
                WITH RECURSIVE note_tree AS (
                    SELECT id FROM notes WHERE id = :id_for_links_cleanup_initial -- Should be the already deleted notes
                    UNION ALL
                    SELECT n.id FROM notes n
                    JOIN note_tree nt ON n.parent_id = nt.id
                )
                DELETE FROM page_links WHERE source_note_id IN (SELECT id FROM note_tree)
            ');
            // The above CTE in deleteNote might be problematic if notes are already gone.
            // A simpler approach for deleteNote:
            // Delete links first, then delete notes.
            // However, the prompt is for create/update. Let's ensure deleteNote is robust.
            // The current setup: page_links has ON DELETE CASCADE on source_note_id.
            // So, when a note is deleted, its corresponding links should be automatically deleted.
            // The CTE for deleting notes recursively is fine. The FK should handle it.
            // No explicit deletion from page_links needed here if FK is set up correctly
            // and the DB supports it for recursive deletes (SQLite does).
            
            $db->exec('COMMIT');
            error_log("Note(s) deleted successfully starting with ID: " . $id);
            return ['success' => true];
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            error_log("Error deleting note " . $id . ": " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    // Handle the request
    $method = $_SERVER['REQUEST_METHOD'];
    $id = $_GET['id'] ?? null;

    error_log("Request received - Method: $method, ID: $id");

    if ($method === 'POST') {
        $input = '';
        if (php_sapi_name() == 'cli' && isset($GLOBALS['cliInputPayload'])) {
            $input = $GLOBALS['cliInputPayload'];
            error_log("Using PHP_INPUT_PAYLOAD (CLI mode) for POST data.");
        } else {
            $input = file_get_contents('php://input');
        }
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