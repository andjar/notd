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

    // Helper function to ensure a page exists, creating it if not.
    function _ensurePageExists($pageId, $db) {
        // Check for existing page
        $stmt = $db->prepare('SELECT id FROM pages WHERE id = :pageId');
        if (!$stmt) {
            error_log("Error preparing statement to check page '{$pageId}': " . $db->lastErrorMsg());
            return false;
        }
        $stmt->bindValue(':pageId', $pageId, SQLITE3_TEXT);
        $result = $stmt->execute();

        if (!$result) {
            error_log("Error checking existence of page '{$pageId}': " . $db->lastErrorMsg());
            $stmt->close();
            return false;
        }

        if ($result->fetchArray(SQLITE3_ASSOC)) {
            error_log("Page '{$pageId}' already exists.");
            $stmt->close();
            return true;
        }
        $stmt->close(); // Close select statement before preparing insert

        // Create new page if not found
        error_log("Page '{$pageId}' not found, creating it.");
        $insertStmt = $db->prepare('INSERT INTO pages (id, title, type) VALUES (:id, :title, :type)');
        if (!$insertStmt) {
            error_log("Error preparing statement to create page '{$pageId}': " . $db->lastErrorMsg());
            return false;
        }
        $insertStmt->bindValue(':id', $pageId, SQLITE3_TEXT);
        $insertStmt->bindValue(':title', $pageId, SQLITE3_TEXT); // Use ID as initial title
        $insertStmt->bindValue(':type', 'note', SQLITE3_TEXT);   // Default type

        if ($insertStmt->execute()) {
            error_log("Page '{$pageId}' created successfully.");
            $insertStmt->close();
            return true;
        } else {
            error_log("Error creating page '{$pageId}': " . $db->lastErrorMsg());
            $insertStmt->close();
            return false;
        }
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

                foreach ($targetPageIds as $targetPageIdRaw) {
                    $targetPageId = trim($targetPageIdRaw); // Trim the target ID

                    // Ensure the target page exists or is created before adding the link
                    if (!_ensurePageExists($targetPageId, $db)) {
                        error_log("Could not ensure page '{$targetPageId}' exists or be created. Skipping link from Note ID {$noteId}.");
                        continue; // Skip this link
                    }
                    
                    // Reset and bind values for inserting the link
                    $stmt->reset(); // Important to reset after potential use by _ensurePageExists or previous loop
                    $stmt->bindValue(':source_page_id', $sourcePageId, SQLITE3_TEXT);
                    $stmt->bindValue(':target_page_id', $targetPageId, SQLITE3_TEXT);
                    $stmt->bindValue(':source_note_id', $noteId, SQLITE3_INTEGER);
                    
                    if (!$stmt->execute()) {
                        // This should ideally not happen now if FKs are on and _ensurePageExists worked,
                        // but other errors could occur (e.g., unique constraint if re-adding same link, though unlikely here)
                        error_log('Failed to insert page_link for Note ID {$noteId} to target "' . $targetPageId . '": ' . $db->lastErrorMsg());
                        // Decide if this should throw an exception or just log and continue
                        // For now, consistent with previous behavior of throwing on insert failure:
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

            // Determine the order for the new note
            $orderQuery = 'SELECT MAX("order") AS max_order FROM notes WHERE page_id = :page_id';
            if (!empty($data['parent_id'])) {
                $orderQuery .= ' AND parent_id = :parent_id';
            } else {
                $orderQuery .= ' AND parent_id IS NULL';
            }
            $orderStmt = $db->prepare($orderQuery);
            if (!$orderStmt) {
                throw new Exception('Failed to prepare order query: ' . $db->lastErrorMsg());
            }
            $orderStmt->bindValue(':page_id', $data['page_id'], SQLITE3_TEXT);
            if (!empty($data['parent_id'])) {
                $orderStmt->bindValue(':parent_id', $data['parent_id'], SQLITE3_INTEGER);
            }
            $orderResult = $orderStmt->execute();
            $maxOrderRow = $orderResult->fetchArray(SQLITE3_ASSOC);
            $newOrder = ($maxOrderRow && isset($maxOrderRow['max_order'])) ? $maxOrderRow['max_order'] + 1 : 0;
            
            // Insert the note
            $stmt = $db->prepare('
                INSERT INTO notes (page_id, content, parent_id, block_id, "order")
                VALUES (:page_id, :content, :parent_id, :block_id, :order_val)
            ');
            
            if (!$stmt) {
                throw new Exception('Failed to prepare note insert: ' . $db->lastErrorMsg());
            }
            
            $stmt->bindValue(':page_id', $data['page_id'], SQLITE3_TEXT);
            $stmt->bindValue(':content', $data['content'], SQLITE3_TEXT);
            // Level is no longer directly set here; it's calculated dynamically in page.php
            $stmt->bindValue(':parent_id', $data['parent_id'] ?? null, $data['parent_id'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmt->bindValue(':block_id', $blockId, SQLITE3_TEXT);
            $stmt->bindValue(':order_val', $newOrder, SQLITE3_INTEGER);
            
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
            // Level is no longer directly updated here
            // parent_id can be explicitly set to null, so check with array_key_exists
            if (array_key_exists('parent_id', $data)) $updateFields[] = "parent_id = :parent_id";
            if (isset($data['order'])) $updateFields[] = "\"order\" = :order_val";


            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
                $sql = 'UPDATE notes SET ' . implode(', ', $updateFields) . ' WHERE id = :id';
                
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare note update: ' . $db->lastErrorMsg());
                }

                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                if (isset($data['content'])) $stmt->bindValue(':content', $data['content'], SQLITE3_TEXT);
                // Level is no longer directly updated here
                if (array_key_exists('parent_id', $data)) $stmt->bindValue(':parent_id', $data['parent_id'], $data['parent_id'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
                if (isset($data['order'])) $stmt->bindValue(':order_val', $data['order'], SQLITE3_INTEGER);


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

            case 'reorder_note':
                // new_level is no longer required from the client for reorder_note
                if (!isset($data['note_id'], $data['new_order'], $data['page_id'])) { // new_parent_id can be null
                    throw new Exception('Missing required fields for reorder_note (note_id, new_order, page_id)');
                }
                // Ensure new_parent_id is explicitly handled if missing, defaulting to null
                $data['new_parent_id'] = $data['new_parent_id'] ?? null;
                $result = reorderNote($data);
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

function reorderNote($data) {
    global $db;
    error_log("Reordering note with data: " . json_encode($data));

    $note_id = $data['note_id'];
    $new_parent_id = $data['new_parent_id'];
    // $new_level = $data['new_level']; // Level is no longer managed here
    $new_order = $data['new_order'];
    $page_id = $data['page_id'];

    $db->exec('BEGIN TRANSACTION');
    try {
        // Fetch current state (old_parent_id, old_order)
        $stmt_fetch = $db->prepare('SELECT parent_id, "order" FROM notes WHERE id = :note_id AND page_id = :page_id');
        if (!$stmt_fetch) throw new Exception('Failed to prepare fetch statement: ' . $db->lastErrorMsg());
        $stmt_fetch->bindValue(':note_id', $note_id, SQLITE3_INTEGER);
        $stmt_fetch->bindValue(':page_id', $page_id, SQLITE3_TEXT);
        $current_state = $stmt_fetch->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$current_state) throw new Exception('Note not found or page_id mismatch.');
        $old_parent_id = $current_state['parent_id']; // This can be NULL
        $old_order = $current_state['order'];

        // 1. Decrement orders in the old list
        $sql_decrement = 'UPDATE notes SET "order" = "order" - 1 WHERE page_id = :page_id AND ';
        $old_parent_check_sql = ($old_parent_id === null) ? "parent_id IS NULL" : "parent_id = :old_parent_id";
        $sql_decrement .= $old_parent_check_sql . ' AND "order" > :old_order';
        
        $stmt_decrement = $db->prepare($sql_decrement);
        if (!$stmt_decrement) throw new Exception('Failed to prepare decrement statement: ' . $db->lastErrorMsg());
        $stmt_decrement->bindValue(':page_id', $page_id, SQLITE3_TEXT);
        if ($old_parent_id !== null) {
            $stmt_decrement->bindValue(':old_parent_id', $old_parent_id, SQLITE3_INTEGER);
        }
        $stmt_decrement->bindValue(':old_order', $old_order, SQLITE3_INTEGER);
        if (!$stmt_decrement->execute()) throw new Exception('Failed to execute decrement: ' . $db->lastErrorMsg());

        // 2. Increment orders in the new list
        $sql_increment = 'UPDATE notes SET "order" = "order" + 1 WHERE page_id = :page_id AND ';
        $new_parent_check_sql = ($new_parent_id === null) ? "parent_id IS NULL" : "parent_id = :new_parent_id";
        $sql_increment .= $new_parent_check_sql . ' AND "order" >= :new_order AND id != :note_id';
        
        $stmt_increment = $db->prepare($sql_increment);
        if (!$stmt_increment) throw new Exception('Failed to prepare increment statement: ' . $db->lastErrorMsg());
        $stmt_increment->bindValue(':page_id', $page_id, SQLITE3_TEXT);
        if ($new_parent_id !== null) {
            $stmt_increment->bindValue(':new_parent_id', $new_parent_id, SQLITE3_INTEGER);
        }
        $stmt_increment->bindValue(':new_order', $new_order, SQLITE3_INTEGER);
        $stmt_increment->bindValue(':note_id', $note_id, SQLITE3_INTEGER);
        if (!$stmt_increment->execute()) throw new Exception('Failed to execute increment: ' . $db->lastErrorMsg());

        // 3. Update the target note
        // Level is no longer updated here
        $stmt_update = $db->prepare('UPDATE notes SET parent_id = :new_parent_id, "order" = :new_order, updated_at = CURRENT_TIMESTAMP WHERE id = :note_id AND page_id = :page_id');
        if (!$stmt_update) throw new Exception('Failed to prepare update statement: ' . $db->lastErrorMsg());
        $stmt_update->bindValue(':new_parent_id', $new_parent_id, $new_parent_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        // $stmt_update->bindValue(':new_level', $new_level, SQLITE3_INTEGER); // Level removed
        $stmt_update->bindValue(':new_order', $new_order, SQLITE3_INTEGER);
        $stmt_update->bindValue(':note_id', $note_id, SQLITE3_INTEGER);
        $stmt_update->bindValue(':page_id', $page_id, SQLITE3_TEXT);
        if (!$stmt_update->execute()) throw new Exception('Failed to execute update: ' . $db->lastErrorMsg());

        $db->exec('COMMIT');
        error_log("Note reordered successfully for note ID: " . $note_id);
        return ['success' => true];
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        error_log("Error reordering note " . ($note_id ?? 'unknown') . ": " . $e->getMessage());
        return ['error' => $e->getMessage()];
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