<?php
/**
 * API Endpoint for Note Management (v1) - phpdesktop Compatible
 *
 * This script handles all CRUD (Create, Retrieve, Update, Delete) operations for notes
 * using only GET and POST methods to ensure compatibility with phpdesktop.
 * - Create: POST request without an `_method` override.
 * - Retrieve: GET request.
 * - Update: POST request with `_method: "PUT"` in the JSON body.
 * - Delete: POST request with `_method: "DELETE"` in the JSON body.
 * - Batch: POST request with `action: "batch"` in the JSON body.
 *
 * It relies on the PropertyParser class to manage properties from note content and the
 * DataManager class for consistent data retrieval.
 *
 * @see /api/property_parser.php The PropertyParser class handles all logic for syncing properties from content.
 * @see /api/data_manager.php The DataManager class handles all direct database fetching.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../property_parser.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../response_utils.php';

$pdo = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// --- Helper Functions ---

if (!function_exists('_filterPropertiesByVisibility')) {
    function _filterPropertiesByVisibility($properties) {
        if (empty($properties) || !defined('PROPERTY_BEHAVIORS_BY_COLON_COUNT')) return $properties;
        $visibleProperties = [];
        foreach ($properties as $name => $propertyValues) {
            $retainedValues = [];
            foreach ($propertyValues as $propEntry) {
                $colonCount = $propEntry['colon_count'] ?? 2;
                $behavior = PROPERTY_BEHAVIORS_BY_COLON_COUNT[$colonCount] ?? PROPERTY_BEHAVIORS_BY_COLON_COUNT[2] ?? ['visible_view' => true];
                if ($behavior['visible_view']) {
                    $retainedValues[] = $propEntry;
                }
            }
            if (!empty($retainedValues)) $visibleProperties[$name] = $retainedValues;
        }
        return $visibleProperties;
    }
}

// --- Batch Operation Helpers ---

if (!function_exists('_createNoteInBatch')) {
    function _createNoteInBatch($pdo, $payload, &$tempIdMap) {
        if (!isset($payload['page_id']) || !is_numeric($payload['page_id'])) {
            return ['type' => 'create', 'status' => 'error', 'message' => 'Missing or invalid page_id.', 'client_temp_id' => $payload['client_temp_id'] ?? null];
        }
        try {
            $pdo->beginTransaction();
            $pageId = (int)$payload['page_id'];
            $content = $payload['content'] ?? '';
            $parentNoteId = isset($payload['parent_note_id']) ? ($payload['parent_note_id'] === null ? null : (int)$payload['parent_note_id']) : null;
            $orderIndex = $payload['order_index'] ?? null;
            $collapsed = $payload['collapsed'] ?? 0;
            $clientTempId = $payload['client_temp_id'] ?? null;
            $sqlFields = ['page_id', 'content', 'parent_note_id', 'collapsed'];
            $sqlParams = [':page_id' => $pageId, ':content' => $content, ':parent_note_id' => $parentNoteId, ':collapsed' => (int)$collapsed];
            if ($orderIndex !== null) { $sqlFields[] = 'order_index'; $sqlParams[':order_index'] = (int)$orderIndex; }
            $sql = "INSERT INTO Notes (" . implode(', ', $sqlFields) . ") VALUES (:" . implode(', :', $sqlFields) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($sqlParams);
            $noteId = $pdo->lastInsertId();
            if (!$noteId) { throw new Exception('Failed to create note record in database.'); }
            $propertyParser = new PropertyParser($pdo);
            $propertyParser->syncNotePropertiesFromContent($noteId, $content);
            $pdo->commit();
            if ($clientTempId) { $tempIdMap[$clientTempId] = $noteId; }
            $dataManager = new DataManager($pdo);
            $newNote = $dataManager->getNoteById($noteId, true);
            $result = ['type' => 'create', 'status' => 'success', 'note' => $newNote];
            if ($clientTempId) $result['client_temp_id'] = $clientTempId;
            return $result;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Error in _createNoteInBatch: " . $e->getMessage());
            return ['type' => 'create', 'status' => 'error', 'message' => 'Failed to create note: ' . $e->getMessage(), 'client_temp_id' => $payload['client_temp_id'] ?? null];
        }
    }
}
if (!function_exists('_updateNoteInBatch')) {
    function _updateNoteInBatch($pdo, $payload, $tempIdMap) {
        $originalId = $payload['id'] ?? null;
        if (!$originalId) return ['type' => 'update', 'status' => 'error', 'message' => 'Missing id for update operation.'];
        $noteId = (is_string($originalId) && isset($tempIdMap[$originalId])) ? $tempIdMap[$originalId] : $originalId;
        $noteId = (int)$noteId;
        try {
            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT content FROM Notes WHERE id = ?");
            $stmtCheck->execute([$noteId]);
            $existingNote = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existingNote) { $pdo->rollBack(); return ['type' => 'update', 'status' => 'error', 'message' => 'Note not found for update.', 'id' => $noteId]; }
            $setClauses = []; $params = []; $contentChanged = false;
            if (isset($payload['content'])) { $setClauses[] = "content = ?"; $params[] = $payload['content']; $contentChanged = true; }
            if (array_key_exists('parent_note_id', $payload)) {
                $newParentId = $payload['parent_note_id'];
                if (is_string($newParentId) && isset($tempIdMap[$newParentId])) $newParentId = $tempIdMap[$newParentId];
                $setClauses[] = "parent_note_id = ?"; $params[] = $newParentId === null ? null : (int)$newParentId;
            }
            if (isset($payload['order_index'])) { $setClauses[] = "order_index = ?"; $params[] = (int)$payload['order_index']; }
            if (isset($payload['collapsed'])) { $setClauses[] = "collapsed = ?"; $params[] = (int)$payload['collapsed']; }
            if (isset($payload['page_id'])) { $setClauses[] = "page_id = ?"; $params[] = (int)$payload['page_id']; }
            if (empty($setClauses)) { $pdo->rollBack(); return ['type' => 'update', 'status' => 'warning', 'message' => 'No fields to update for note.', 'id' => $noteId]; }
            $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
            $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
            $params[] = $noteId;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($contentChanged) {
                $propertyParser = new PropertyParser($pdo);
                $propertyParser->syncNotePropertiesFromContent($noteId, $payload['content']);
            }
            $pdo->commit();
            $dataManager = new DataManager($pdo);
            $updatedNote = $dataManager->getNoteById($noteId, true);
            return ['type' => 'update', 'status' => 'success', 'note' => $updatedNote];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Error in _updateNoteInBatch: " . $e->getMessage());
            return ['type' => 'update', 'status' => 'error', 'message' => 'Failed to update note: ' . $e->getMessage(), 'id' => $noteId];
        }
    }
}
if (!function_exists('_deleteNoteInBatch')) {
    function _deleteNoteInBatch($pdo, $payload, $tempIdMap) {
        $noteId = $payload['id'] ?? null;
        if (!$noteId) return ['type' => 'delete', 'status' => 'error', 'message' => 'Missing id for delete operation.'];
        if (is_string($noteId) && isset($tempIdMap[$noteId])) $noteId = $tempIdMap[$noteId];
        $noteId = (int)$noteId;
        try {
            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT 1 FROM Notes WHERE id = ?");
            $stmtCheck->execute([$noteId]);
            if (!$stmtCheck->fetch()) { $pdo->rollBack(); return ['type' => 'delete', 'status' => 'error', 'message' => 'Note not found for delete.', 'id' => $noteId]; }
            $stmtDeleteNote = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
            $stmtDeleteNote->execute([$noteId]);
            $pdo->commit();
            return ['type' => 'delete', 'status' => 'success', 'deleted_note_id' => $noteId];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (str_contains($e->getMessage(), 'FOREIGN KEY constraint failed')) return ['type' => 'delete', 'status' => 'error', 'message' => 'Cannot delete note because other notes depend on it (e.g., it has children).', 'id' => $noteId];
            error_log("Error in _deleteNoteInBatch: " . $e->getMessage());
            return ['type' => 'delete', 'status' => 'error', 'message' => 'Failed to delete note: ' . $e->getMessage(), 'id' => $noteId];
        }
    }
}
if (!function_exists('_handleBatchOperations')) {
    function _handleBatchOperations($pdo, $operations) {
        if (!is_array($operations)) { ApiResponse::error('Invalid batch request: "operations" must be an array.', 400); return; }
        foreach ($operations as $index => $op) {
            if (!isset($op['type']) || !in_array($op['type'], ['create', 'update', 'delete'])) { ApiResponse::error('Batch validation failed: Invalid type at index ' . $index, 400); return; }
            if (!isset($op['payload']) || !is_array($op['payload'])) { ApiResponse::error('Batch validation failed: Invalid payload at index ' . $index, 400); return; }
        }
        $deleteOps = array_filter($operations, fn($op) => $op['type'] === 'delete');
        $createOps = array_filter($operations, fn($op) => $op['type'] === 'create');
        $updateOps = array_filter($operations, fn($op) => $op['type'] === 'update');
        $results = []; $tempIdMap = [];
        foreach ($deleteOps as $op) { $results[] = _deleteNoteInBatch($pdo, $op['payload'], $tempIdMap); }
        foreach ($createOps as $op) { $results[] = _createNoteInBatch($pdo, $op['payload'], $tempIdMap); }
        foreach ($updateOps as $op) { $results[] = _updateNoteInBatch($pdo, $op['payload'], $tempIdMap); }
        ApiResponse::success(['message' => 'Batch operations processed.', 'results' => $results]);
    }
}


// --- Main Request Router ---

switch ($method) {
    case 'GET':
        $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
        $dataManager = new DataManager($pdo);
        try {
            if (isset($_GET['id'])) {
                $note = $dataManager->getNoteById((int)$_GET['id'], $includeInternal);
                if ($note) {
                    if (!$includeInternal) $note['properties'] = _filterPropertiesByVisibility($note['properties']);
                    ApiResponse::success($note);
                } else {
                    ApiResponse::error('Note not found or is internal.', 404);
                }
            } elseif (isset($_GET['page_id'])) {
                $pageData = $dataManager->getNotesByPageId((int)$_GET['page_id'], $includeInternal);
                if ($pageData !== null) {
                    foreach ($pageData as &$note) {
                        if (!$includeInternal) $note['properties'] = _filterPropertiesByVisibility($note['properties']);
                    }
                    ApiResponse::success($pageData);
                } else {
                    ApiResponse::error('Page not found or is internal.', 404);
                }
            } else {
                ApiResponse::error('Either a note "id" or "page_id" must be provided.', 400);
            }
        } catch (Exception $e) {
            ApiResponse::error('An error occurred while fetching data: ' . $e->getMessage(), 500);
        }
        break;

    case 'POST':
        // Determine the action based on `_method` or `action` property in the JSON payload
        $action = $input['_method'] ?? ($input['action'] ?? 'CREATE');
        $action = strtoupper($action);

        switch ($action) {
            case 'BATCH':
                _handleBatchOperations($pdo, $input['operations'] ?? []);
                break;
            
            case 'PUT': // Handle Update
                $noteId = (int)($_GET['id'] ?? $input['id'] ?? 0);
                if (!$noteId) { ApiResponse::error('Note ID is required for update.', 400); exit; }
                $payload = $input;
                $payload['id'] = $noteId;
                $tempIdMap = [];
                $result = _updateNoteInBatch($pdo, $payload, $tempIdMap);
                if ($result['status'] === 'success') {
                    ApiResponse::success($result['note']);
                } else {
                    ApiResponse::error($result['message'], $result['message'] === 'Note not found for update.' ? 404 : 500);
                }
                break;

            case 'DELETE': // Handle Delete
                $noteId = (int)($_GET['id'] ?? $input['id'] ?? 0);
                if (!$noteId) { ApiResponse::error('Note ID is required for deletion.', 400); exit; }
                $payload = ['id' => $noteId];
                $tempIdMap = [];
                $result = _deleteNoteInBatch($pdo, $payload, $tempIdMap);
                if ($result['status'] === 'success') {
                    ApiResponse::success(['deleted_note_id' => $result['deleted_note_id']]);
                } else {
                    ApiResponse::error($result['message'], $result['message'] === 'Note not found for delete.' ? 404 : 500);
                }
                break;

            default: // Default to Create
                $tempIdMap = [];
                $result = _createNoteInBatch($pdo, $input, $tempIdMap);
                if ($result['status'] === 'success') {
                    ApiResponse::success($result['note'], 201);
                } else {
                    ApiResponse::error($result['message'], 500);
                }
                break;
        }
        break;

    default:
        // This case should not be reachable in a phpdesktop environment, but is good practice.
        ApiResponse::error('Method not allowed', 405);
        break;
}