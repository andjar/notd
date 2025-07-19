<?php

namespace App;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../PatternProcessor.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/batch_operations.php';

// Add debug logging
error_log("Notes API Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);
error_log("Input data: " . json_encode($input ?? []));

// Create a fresh database connection for this request to avoid locking issues
$pdo = get_db_connection();
$dataManager = new \App\DataManager($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST' && isset($input['_method'])) {
    $overrideMethod = strtoupper($input['_method']);
    if ($overrideMethod === 'PUT' || $overrideMethod === 'DELETE') {
        $method = $overrideMethod;
    }
}

// New "Smart Property Indexer"
// This function is the single source of truth for processing properties from content.
if (!function_exists('_indexPropertiesFromContent')) {
    function _indexPropertiesFromContent($pdo, $entityType, $entityId, $content) {
        // For notes, check if encrypted. If so, do not process properties from content.
        if ($entityType === 'note') {
            $encryptedStmt = $pdo->prepare("SELECT 1 FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND value = 'true' LIMIT 1");
            $encryptedStmt->execute([':note_id' => $entityId]);
            if ($encryptedStmt->fetch()) {
                // error_log("_indexPropertiesFromContent: Note {$entityId} is encrypted. Skipping property processing from content.");
                return; // Note is encrypted, do not parse/modify properties from its content.
            }
        }

        // Instantiate the pattern processor with the existing PDO connection to avoid database locks
        $patternProcessor = new \App\PatternProcessor($pdo);

        // Process the content to extract properties and potentially modified content
        // Pass $pdo in context for handlers that might need it directly.
        $processedData = $patternProcessor->processContent($content, $entityType, $entityId, ['pdo' => $pdo]);
        
        $parsedProperties = $processedData['properties'];

        // Save all extracted/generated properties using the processor's save method
        // This method should handle deleting old 'replaceable' properties and inserting/updating new ones.
        // It will also handle property triggers.
        if (!empty($parsedProperties)) {
            $patternProcessor->saveProperties($parsedProperties, $entityType, $entityId);
        } else {
            // If no properties are parsed from content, we might still need to clear existing replaceable ones.
            // The saveProperties method (or a new dedicated method) in PatternProcessor should handle this.
            // For now, we assume saveProperties with an empty array might be a no-op or needs enhancement.
            // Let's ensure existing replaceable properties are cleared if no new ones are found.
            // This is a conceptual point; actual implementation is in PatternProcessor.saveProperties.
            // For now, we rely on saveProperties to manage this. If it doesn't, this is a gap.
            // A simple way:
            // $patternProcessor->clearReplaceableProperties($entityType, $entityId);
            // For now, assuming saveProperties handles it.
        }
        
        // Update the note's 'internal' flag based on the final set of properties applied.
        $hasInternalTrue = false;
        if (!empty($parsedProperties)) {
            foreach ($parsedProperties as $prop) {
                if (isset($prop['name']) && strtolower($prop['name']) === 'internal' && 
                    isset($prop['value']) && strtolower((string)$prop['value']) === 'true') {
                    $hasInternalTrue = true;
                    break;
                }
            }
        }

        if ($entityType === 'note') {
             try {
                $updateStmt = $pdo->prepare("UPDATE Notes SET internal = ? WHERE id = ?");
                $updateStmt->execute([$hasInternalTrue ? 1 : 0, $entityId]);
            } catch (PDOException $e) {
                // Log error but don't let it break the entire process if just this update fails
                error_log("Could not update Notes.internal flag for note {$entityId}. Error: " . $e->getMessage());
            }
        }
    }
}


// Batch operation helper functions
if (!function_exists('_createNoteInBatch')) {
    function _createNoteInBatch($pdo, $dataManager, $payload, &$tempIdMap) {
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
            $newNote = $dataManager->getNoteById($noteId); 

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
    function _updateNoteInBatch($pdo, $dataManager, $payload, $tempIdMap) {
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



            // Fetch current order_index and page_id for the note
$metaStmt = $pdo->prepare("SELECT order_index, page_id FROM Notes WHERE id = ?");
$metaStmt->execute([$noteId]);
$meta = $metaStmt->fetch(PDO::FETCH_ASSOC);
$oldIndex = (int)$meta['order_index'];
$pageId = (int)$meta['page_id'];

            if (isset($payload['order_index'])) { $setClauses[] = "order_index = ?"; $executeParams[] = (int)$payload['order_index']; }
            if (isset($payload['collapsed'])) { $setClauses[] = "collapsed = ?"; $executeParams[] = (int)$payload['collapsed']; }
            if (isset($payload['page_id'])) { $setClauses[] = "page_id = ?"; $executeParams[] = (int)$payload['page_id']; }

            if (empty($setClauses) && !$contentWasUpdated) { // Check content update flag as well
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
            $updatedNote = $dataManager->getNoteById($noteId);

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
    function _handleBatchOperations($pdo, $dataManager, $operations, $includeParentProperties = false) {
        if (!is_array($operations)) {
            \App\ApiResponse::error('Batch request validation failed: "operations" must be an array.', 400);
            return;
        }

        $results = [];
        $tempIdMap = [];
        
        try {
            // Process operations in a safe order: Delete -> Create -> Update
            $deleteOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'delete');
            $createOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'create');
            $updateOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'update');

            foreach ($deleteOps as $op) {
                $result = _deleteNoteInBatch($pdo, $op['payload'] ?? [], $tempIdMap);
                $results[] = $result;
                if ($result['status'] === 'error') {
                    error_log("Batch delete operation failed: " . json_encode($result));
                }
            }
            
            foreach ($createOps as $op) {
                $result = _createNoteInBatch($pdo, $dataManager, $op['payload'] ?? [], $tempIdMap);
                $results[] = $result;
                if ($result['status'] === 'error') {
                    error_log("Batch create operation failed: " . json_encode($result));
                }
            }
            
            foreach ($updateOps as $op) {
                $result = _updateNoteInBatch($pdo, $dataManager, $op['payload'] ?? [], $tempIdMap);
                $results[] = $result;
                if ($result['status'] === 'error') {
                    error_log("Batch update operation failed: " . json_encode($result));
                }
            }
            
            return $results;
        } catch (Exception $e) {
            error_log("Batch operations failed with exception: " . $e->getMessage());
            throw $e;
        }
    }
}

if ($method === 'GET') {
    // **FIX**: Cast the result of filter_input to a boolean.
    // If 'include_internal' is not set, filter_input returns null, which (bool)null casts to false.
    // This prevents the TypeError in the DataManager.
    $includeInternal = (bool)filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
    $includeParentProperties = (bool)filter_input(INPUT_GET, 'include_parent_properties', FILTER_VALIDATE_BOOLEAN);
    $includeChildren = (bool)filter_input(INPUT_GET, 'include_children', FILTER_VALIDATE_BOOLEAN);
    
    try {
        if (isset($_GET['id'])) {
            $noteId = (int)$_GET['id'];
            
            if ($includeChildren) {
                // Use the new method to fetch note with children
                $note = $dataManager->getNoteWithChildren($noteId, $includeInternal, $includeParentProperties);
            } else {
                // Use the existing method for backward compatibility
                $note = $dataManager->getNoteById($noteId, $includeInternal, $includeParentProperties);
            }
            
            if ($note) {
                \App\ApiResponse::success($note);
            } else {
                \App\ApiResponse::error('Note not found', 404);
            }
        } elseif (isset($_GET['page_id'])) {
            $pageId = (int)$_GET['page_id'];
            $notes = $dataManager->getNotesByPageId($pageId, $includeInternal);
            \App\ApiResponse::success($notes);
        } else {
             \App\ApiResponse::error('Missing required parameter: id or page_id', 400);
        }
    } catch (Exception $e) {
        error_log("API Error in notes.php (GET): " . $e->getMessage());
        \App\ApiResponse::error('An error occurred while fetching data: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'POST') {
    if (isset($input['action']) && $input['action'] === 'batch') {
        $includeParentProperties = (bool)($input['include_parent_properties'] ?? false);
        
        // Add retry logic for database locking issues
        $maxRetries = 3;
        $retryDelay = 100; // milliseconds
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $results = _handleBatchOperations($pdo, $dataManager, $input['operations'] ?? [], $includeParentProperties);
                close_db_connection($pdo);
                \App\ApiResponse::success(['results' => $results]);
                return;
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                if (strpos($errorMessage, 'database is locked') !== false && $attempt < $maxRetries) {
                    error_log("Database locked, retrying batch operation (attempt $attempt/$maxRetries)");
                    usleep($retryDelay * 1000); // Convert to microseconds
                    $retryDelay *= 2; // Exponential backoff
                    continue;
                }
                // If it's not a locking issue or we've exhausted retries, throw the error
                error_log("Batch operation failed after $attempt attempts: " . $errorMessage);
                close_db_connection($pdo);
                \App\ApiResponse::error('Batch operation failed: ' . $errorMessage, 500);
                return;
            }
        }
    }
    \App\ApiResponse::error('This endpoint now primarily uses batch operations. Please use the batch action.', 400);

} elseif ($method === 'PUT') {
    \App\ApiResponse::error('PUT is deprecated. Please use POST with batch operations for updates.', 405);
} elseif ($method === 'DELETE') {
    \App\ApiResponse::error('DELETE is deprecated. Please use POST with batch operations for deletions.', 405);
} else {
    \App\ApiResponse::error('Method not allowed', 405);
}