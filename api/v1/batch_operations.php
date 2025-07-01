<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../pattern_processor.php';

// Batch operation helper functions
if (!function_exists('_createNoteInBatch')) {
    function _createNoteInBatch($pdo, $dataManager, $payload, &$tempIdMap, $includeParentProperties) {
        if (!isset($payload['page_id']) || !is_numeric($payload['page_id'])) {
            return ['type' => 'create', 'status' => 'error', 'message' => 'Missing or invalid page_id for create operation', 'client_temp_id' => $payload['client_temp_id'] ?? null];
        }

        $pageId = (int)$payload['page_id'];
        $content = $payload['content'] ?? '';
        $parentNoteId = null;
        if (array_key_exists('parent_note_id', $payload)) {
            $parentNoteId = ($payload['parent_note_id'] === null || $payload['parent_note_id'] === '') ? null : (int)$payload['parent_note_id'];
        }
        $orderIndex = $payload['order_index'] ?? null;
        $collapsed = $payload['collapsed'] ?? 0;
        $clientTempId = $payload['client_temp_id'] ?? null;

        try {
            // 1. Create the note record
            $sqlFields = ['page_id', 'content', 'parent_note_id', 'collapsed'];
            $sqlParams = [':page_id' => $pageId, ':content' => $content, ':parent_note_id' => $parentNoteId, ':collapsed' => (int)$collapsed];

            if ($orderIndex !== null && is_numeric($orderIndex)) {
                $sqlFields[] = 'order_index';
                $sqlParams[':order_index'] = (int)$orderIndex;
            }
            
            $sql = "INSERT INTO Notes (" . implode(', ', $sqlFields) . ") VALUES (:" . implode(', :', $sqlFields) . ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($sqlParams);
            $noteId = $pdo->lastInsertId();

            if (!$noteId) {
                 return ['type' => 'create', 'status' => 'error', 'message' => 'Failed to create note record in database.', 'client_temp_id' => $clientTempId];
            }

            // 2. Index properties from its content
            if (trim($content) !== '') {
                _indexPropertiesFromContent($pdo, 'note', $noteId, $content);
            }

            // 3. Fetch the newly created note using DataManager for a consistent response
            $newNote = $dataManager->getNoteById($noteId, false, $includeParentProperties);

            if ($clientTempId !== null) {
                $tempIdMap[$clientTempId] = $noteId;
            }
            
            $result = ['type' => 'create', 'status' => 'success', 'note' => $newNote];
            if ($clientTempId !== null) {
                $result['client_temp_id'] = $clientTempId;
            }
            return $result;

        } catch (Exception $e) {
            return ['type' => 'create', 'status' => 'error', 'message' => 'Failed to create note: ' . $e->getMessage(), 'client_temp_id' => $clientTempId];
        }
    }
}

if (!function_exists('_updateNoteInBatch')) {

    function reorderNotes(PDO $pdo, int $pageId, int $noteId, int $oldIndex, int $newIndex): void {
    if ($newIndex === $oldIndex) return;

    if ($newIndex < $oldIndex) {
        $stmt = $pdo->prepare("
            UPDATE Notes
            SET order_index = order_index + 1
            WHERE page_id = ? AND order_index >= ? AND order_index < ? AND id != ?
        ");
        $stmt->execute([$pageId, $newIndex, $oldIndex, $noteId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE Notes
            SET order_index = order_index - 1
            WHERE page_id = ? AND order_index > ? AND order_index <= ? AND id != ?
        ");
        $stmt->execute([$pageId, $oldIndex, $newIndex, $noteId]);
    }
}


    function _updateNoteInBatch($pdo, $dataManager, $payload, $tempIdMap, $includeParentProperties) {
        $noteId = $payload['id'] ?? null;
        if ($noteId === null) return ['type' => 'update', 'status' => 'error', 'message' => 'Missing id for update operation'];

        // Resolve temporary IDs
        if (is_string($noteId) && isset($tempIdMap[$noteId])) {
            $noteId = $tempIdMap[$noteId];
        } elseif (!is_numeric($noteId)) {
             return ['type' => 'update', 'status' => 'error', 'message' => "Invalid or unresolved ID for update: {$payload['id']}", 'id' => $payload['id']];
        }
        $noteId = (int)$noteId;

        try {
            $checkStmt = $pdo->prepare("SELECT id FROM Notes WHERE id = ?");
            $checkStmt->execute([$noteId]);
            if (!$checkStmt->fetch()) {
                return ['type' => 'update', 'status' => 'error', 'message' => 'Note not found for update.', 'id' => $noteId];
            }

            $setClauses = [];
            $executeParams = [];
            $contentWasUpdated = false;

   if (isset($payload['content'])) {
    $setClauses[] = "content = ?";
    $executeParams[] = $payload['content'];
    $contentWasUpdated = true;
}
if (array_key_exists('parent_note_id', $payload)) {
    $newParentNoteId = $payload['parent_note_id'];
    if (is_string($newParentNoteId) && isset($tempIdMap[$newParentNoteId])) {
        $newParentNoteId = $tempIdMap[$newParentNoteId];
    }
    $setClauses[] = "parent_note_id = ?";
    $executeParams[] = $newParentNoteId === null ? null : (int)$newParentNoteId;
}

if (isset($payload['order_index'])) {
    $newIndex = (int)$payload['order_index'];

    // Fetch current order_index and page_id
    $metaStmt = $pdo->prepare("SELECT order_index, page_id FROM Notes WHERE id = ?");
    $metaStmt->execute([$noteId]);
    $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);
    $oldIndex = (int)$meta['order_index'];
    $pageId = (int)$meta['page_id'];

    reorderNotes($pdo, $pageId, $noteId, $oldIndex, $newIndex);

    // Update this note's order_index
    $setClauses[] = "order_index = ?";
    $executeParams[] = $newIndex;
}

if (isset($payload['collapsed'])) {
    $setClauses[] = "collapsed = ?";
    $executeParams[] = (int)$payload['collapsed'];
}

if (isset($payload['page_id'])) {
    $setClauses[] = "page_id = ?";
    $executeParams[] = (int)$payload['page_id'];
}

            if (empty($setClauses) && !$contentWasUpdated) {
                return ['type' => 'update', 'status' => 'warning', 'message' => 'No updatable fields provided for note.', 'id' => $noteId];
            }
            
            if (!empty($setClauses)) {
                $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
                $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
                $executeParams[] = $noteId;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($executeParams);
            }
            
            // Re-index all properties from content if it was provided.
            if ($contentWasUpdated) {
                _indexPropertiesFromContent($pdo, 'note', $noteId, $payload['content']);
            }

            // Fetch updated note using DataManager for a consistent response
            $updatedNote = $dataManager->getNoteById($noteId, false, $includeParentProperties);

            return ['type' => 'update', 'status' => 'success', 'note' => $updatedNote];

        } catch (Exception $e) {
            return ['type' => 'update', 'status' => 'error', 'message' => 'Failed to update note: ' . $e->getMessage(), 'id' => $noteId];
        }
    }
}

if (!function_exists('_deleteNoteInBatch')) {
    function _deleteNoteInBatch($pdo, $payload, $tempIdMap) {
        $noteId = $payload['id'] ?? null;
        if ($noteId === null) return ['type' => 'delete', 'status' => 'error', 'message' => 'Missing id for delete operation'];

        // Resolve temporary IDs
        if (is_string($noteId) && isset($tempIdMap[$noteId])) {
            $noteId = $tempIdMap[$noteId];
        } elseif (!is_numeric($noteId)) {
            return ['type' => 'delete', 'status' => 'error', 'message' => "Invalid or unresolved ID for delete: {$payload['id']}", 'id' => $payload['id']];
        }
        $noteId = (int)$noteId;

        try {
            // CASCADE DELETE is on, so deleting a note will delete its properties.
            $stmtDeleteNote = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
            $stmtDeleteNote->execute([$noteId]);

            if ($stmtDeleteNote->rowCount() > 0) {
                return ['type' => 'delete', 'status' => 'success', 'deleted_note_id' => $noteId];
            } else {
                return ['type' => 'delete', 'status' => 'error', 'message' => 'Note not found for delete.', 'id' => $noteId];
            }

        } catch (Exception $e) {
            // Catch foreign key constraint violation if a child note exists
            if (strpos($e->getMessage(), 'FOREIGN KEY constraint failed') !== false) {
                 return ['type' => 'delete', 'status' => 'error', 'message' => 'Cannot delete note because it has child notes. Delete children first.', 'id' => $noteId];
            }
            return ['type' => 'delete', 'status' => 'error', 'message' => 'Failed to delete note: ' . $e->getMessage(), 'id' => $noteId];
        }
    }
}

if (!function_exists('_handleBatchOperations')) {
    function _handleBatchOperations($pdo, $dataManager, $operations, $includeParentProperties) {
        if (!is_array($operations)) {
            throw new Exception('Batch request validation failed: "operations" must be an array.');
        }

        $results = [];
        $tempIdMap = [];
        
        // Process operations in a safe order: Delete -> Create -> Update
        $deleteOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'delete');
        $createOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'create');
        $updateOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'update');

        foreach ($deleteOps as $op) $results[] = _deleteNoteInBatch($pdo, $op['payload'] ?? [], $tempIdMap);
        foreach ($createOps as $op) $results[] = _createNoteInBatch($pdo, $dataManager, $op['payload'] ?? [], $tempIdMap, $includeParentProperties);
        foreach ($updateOps as $op) $results[] = _updateNoteInBatch($pdo, $dataManager, $op['payload'] ?? [], $tempIdMap, $includeParentProperties);
        
        return $results;
    }
}

function process_batch_request(array $requestData, PDO $existingPdo = null): array {
    $operations = $requestData['operations'] ?? null;
    $includeParentProperties = $requestData['include_parent_properties'] ?? false;

    if ($operations === null) {
        // For testing context, we might throw an exception or return an error structure
        // For direct HTTP context, send_json_error_response would have been called before this.
        // To make it behave consistently for tests, let's return an error structure.
        return ['error' => "Request validation failed: 'operations' key is missing or null.", 'status_code' => 400];
    }
    if (!is_array($operations)) {
         return ['error' => 'Request validation failed: "operations" must be an array.', 'status_code' => 400];
    }

    $pdo = $existingPdo;
    $ownsPdo = false;
    $maxRetries = 5; // Max number of retries
    $retryCount = 0;
    $baseWaitTime = 100; // milliseconds

    while ($retryCount <= $maxRetries) {
        try {
            if ($pdo === null) {
                $pdo = get_db_connection();
                $ownsPdo = true; // This function instance created and owns the PDO connection
            }

            $dataManager = new DataManager($pdo);

            if ($ownsPdo) { // Only manage transactions if this function created the PDO connection
                $pdo->beginTransaction();
            }

            $results = _handleBatchOperations($pdo, $dataManager, $operations, $includeParentProperties);

            if ($ownsPdo && $pdo->inTransaction()) { // Check inTransaction before commit
                $pdo->commit();
            }

            return ['results' => $results];

        } catch (Exception $e) {
            if ($ownsPdo && $pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Check if it's a SQLite busy/locked error (codes 5 or 6)
            $errorCode = ($e instanceof PDOException) ? $e->getCode() : null;
            // Some drivers/versions might return error code as string in $e->errorInfo[1]
            if ($e instanceof PDOException && isset($e->errorInfo[1]) && ($e->errorInfo[1] == 5 || $e->errorInfo[1] == 6)) {
                $errorCode = (int)$e->errorInfo[1];
            }
            
            // SQLITE_BUSY is 5, SQLITE_LOCKED is 6
            if (($errorCode === 5 || $errorCode === 6 || strpos($e->getMessage(), 'database is locked') !== false) && $retryCount < $maxRetries) {
                $retryCount++;
                $waitTime = $baseWaitTime * pow(2, $retryCount - 1);
                error_log("Batch operation: DB lock detected. Attempt $retryCount/$maxRetries. Waiting {$waitTime}ms. Error: " . $e->getMessage());
                usleep($waitTime * 1000); // usleep expects microseconds
                
                // If PDO was established and then failed, it might need to be reset or reconnected for the next attempt
                // For SQLite, often just retrying the transaction is enough if the connection object itself is fine.
                // If $ownsPdo is true, we might consider nullifying $pdo here so it's recreated in the next loop iteration.
                // However, get_db_connection() already handles creating a new connection if $pdo is null.
                // Let's ensure $pdo is nullified if this attempt owned it, so a fresh one is fetched.
                if ($ownsPdo && $pdo) {
                    $pdo = null; 
                }
                continue; // Retry the loop
            }
            
            // For other errors, or if max retries exceeded, return/rethrow the error
            // For testing, return error structure
            // To avoid echoing JSON directly from here in a test context:
            return ['error' => "An error occurred: " . $e->getMessage(), 'status_code' => 500, 'exception' => $e];
        } finally {
            // Close the PDO connection only if this function instance created it and it's the end of all retries.
            // If we are in a retry loop, we might want to keep it open or nullify it to be reopened.
            // This finally block is primarily for any cleanup needed *per attempt* if necessary,
            // but connection closing for owned connections is handled when the function exits or before a retry.
        }
    } // End of while loop

    // If loop finished because retries were exhausted
    if ($retryCount > $maxRetries) {
        // Ensure PDO is cleaned up if it was owned and loop exhausted.
        if ($ownsPdo && $pdo) {
            $pdo = null; 
        }
        return ['error' => 'Max retries exceeded for batch operation after ' . $maxRetries . ' attempts.', 'status_code' => 503]; // Service Unavailable
    }

    // Fallback for any unexpected exit from the loop without a return statement (should ideally not happen)
    // If $pdo was owned and is still set, clean it up.
    if ($ownsPdo && $pdo) {
        $pdo = null;
    }
    // This path should ideally not be reached if the loop logic is correct.
    // It implies the loop exited without returning results or a specific error from within.
    return ['error' => 'An unexpected error occurred in batch processing.', 'status_code' => 500];
}


// --- Main Request Handling (for HTTP requests) ---
// This part will only execute when the script is called directly via HTTP
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $requestData = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            send_json_error_response("Invalid JSON payload: " . json_last_error_msg(), 400);
            // No exit here, allow script to terminate naturally for tests if json_decode fails
        } elseif ($requestData === null && $input !== 'null') { // json_decode can return null for "null" input
            send_json_error_response("Invalid JSON payload: Not decodable or empty.", 400);
        } else {
            // If $requestData is null here, it means json_decode failed for non-"null" input or input was "null"
            // process_batch_request handles null $requestData['operations']
            $response = process_batch_request($requestData ?? []); // Pass empty array if $requestData is null

            if (isset($response['error'])) {
                send_json_error_response($response['error'], $response['status_code'] ?? 500);
            } else {
                send_json_response($response);
            }
        }
    } else {
        send_json_error_response("Invalid request method. Only POST is supported.", 405);
    }
}