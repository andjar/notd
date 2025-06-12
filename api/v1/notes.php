<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../property_parser.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../response_utils.php';

$pdo = get_db_connection();
$dataManager = new DataManager($pdo);
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
        if ($entityType === 'note') {
            $encryptedStmt = $pdo->prepare("SELECT 1 FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND value = 'true' LIMIT 1");
            $encryptedStmt->execute([':note_id' => $entityId]);
            if ($encryptedStmt->fetch()) {
                return; 
            }
        }

        $replaceableWeights = [];
        if (defined('PROPERTY_WEIGHTS')) {
            foreach (PROPERTY_WEIGHTS as $weight => $config) {
                if (isset($config['update_behavior']) && $config['update_behavior'] === 'replace') {
                    $replaceableWeights[] = (int)$weight;
                }
            }
        }
        
        if (!empty($replaceableWeights)) {
            $placeholders = str_repeat('?,', count($replaceableWeights) - 1) . '?';
            $idColumn = $entityType . '_id';
            $deleteSql = "DELETE FROM Properties WHERE {$idColumn} = ? AND weight IN ($placeholders)";
            $stmtDelete = $pdo->prepare($deleteSql);
            $stmtDelete->execute(array_merge([$entityId], $replaceableWeights));
        }

        $propertyParser = new PropertyParser($pdo);
        $parsedProperties = $propertyParser->parsePropertiesFromContent($content);

        if (!empty($parsedProperties)) {
            $idColumn = $entityType . '_id';
            $insertSql = "INSERT INTO Properties ({$idColumn}, name, value, weight) VALUES (?, ?, ?, ?)";
            $stmtInsert = $pdo->prepare($insertSql);
            $hasInternalTrue = false;

            foreach ($parsedProperties as $prop) {
                $stmtInsert->execute([$entityId, $prop['name'], (string)$prop['value'], $prop['weight']]);
                if (strtolower($prop['name']) === 'internal' && strtolower((string)$prop['value']) === 'true') {
                    $hasInternalTrue = true;
                }
            }

            // **FIX**: Update the note's internal flag based on parsed properties.
            if ($entityType === 'note') {
                $updateStmt = $pdo->prepare("UPDATE Notes SET internal = ? WHERE id = ?");
                $updateStmt->execute([$hasInternalTrue ? 1 : 0, $entityId]);
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
    function _handleBatchOperations($pdo, $dataManager, $operations) {
        if (!is_array($operations)) {
            ApiResponse::error('Batch request validation failed: "operations" must be an array.', 400);
            return;
        }

        $results = [];
        $tempIdMap = [];
        
        $pdo->beginTransaction();
        try {
            // Process operations in a safe order: Delete -> Create -> Update
            $deleteOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'delete');
            $createOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'create');
            $updateOps = array_filter($operations, fn($op) => ($op['type'] ?? '') === 'update');

            foreach ($deleteOps as $op) $results[] = _deleteNoteInBatch($pdo, $op['payload'] ?? [], $tempIdMap);
            foreach ($createOps as $op) $results[] = _createNoteInBatch($pdo, $dataManager, $op['payload'] ?? [], $tempIdMap);
            foreach ($updateOps as $op) $results[] = _updateNoteInBatch($pdo, $dataManager, $op['payload'] ?? [], $tempIdMap);
            
            $pdo->commit();

            ApiResponse::success([
                'message' => 'Batch operations completed. Check individual results for status.',
                'results' => $results
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Batch operation critical error: " . $e->getMessage());
            ApiResponse::error('An internal server error occurred during batch processing.', 500, ['details' => $e->getMessage()]);
        }
    }
}

if ($method === 'GET') {
    // **FIX**: Cast the result of filter_input to a boolean.
    // If 'include_internal' is not set, filter_input returns null, which (bool)null casts to false.
    // This prevents the TypeError in the DataManager.
    $includeInternal = (bool)filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
    
    try {
        if (isset($_GET['id'])) {
            $noteId = (int)$_GET['id'];
            $note = $dataManager->getNoteById($noteId, $includeInternal);
            if ($note) {
                ApiResponse::success($note);
            } else {
                ApiResponse::error('Note not found', 404);
            }
        } elseif (isset($_GET['page_id'])) {
            $pageId = (int)$_GET['page_id'];
            $notes = $dataManager->getNotesByPageId($pageId, $includeInternal);
            ApiResponse::success($notes);
        } else {
             ApiResponse::error('Missing required parameter: id or page_id', 400);
        }
    } catch (Exception $e) {
        error_log("API Error in notes.php (GET): " . $e->getMessage());
        ApiResponse::error('An error occurred while fetching data.', 500);
    }
} elseif ($method === 'POST') {
    if (isset($input['action']) && $input['action'] === 'batch') {
        _handleBatchOperations($pdo, $dataManager, $input['operations'] ?? []);
        return;
    }
    ApiResponse::error('This endpoint now primarily uses batch operations. Please use the batch action.', 400);

} elseif ($method === 'PUT') {
    ApiResponse::error('PUT is deprecated. Please use POST with batch operations for updates.', 405);
} elseif ($method === 'DELETE') {
    ApiResponse::error('DELETE is deprecated. Please use POST with batch operations for deletions.', 405);
} else {
    ApiResponse::error('Method not allowed', 405);
}