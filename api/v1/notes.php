<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../property_trigger_service.php';
require_once __DIR__ . '/../pattern_processor.php';
require_once __DIR__ . '/../property_parser.php';
require_once __DIR__ . '/../property_auto_internal.php'; // Added for determinePropertyInternalStatus
require_once __DIR__ . '/properties.php'; // Required for _updateOrAddPropertyAndDispatchTriggers
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../response_utils.php';

$pdo = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Handle method overriding for PUT via POST (e.g., for phpdesktop)
if ($method === 'POST' && isset($input['_method'])) {
    $overrideMethod = strtoupper($input['_method']);
    if ($overrideMethod === 'PUT' || $overrideMethod === 'DELETE') {
        $method = $overrideMethod;
    }
}

// New "Smart Property Indexer"
// This function is the single source of truth for processing properties from content.
// It replaces processNoteContent, _checkAndSetNoteInternalFlag, and explicit property handling.
if (!function_exists('_indexPropertiesFromContent')) {
    function _indexPropertiesFromContent($pdo, $entityType, $entityId, $content) {
        // For notes, check if encrypted. If so, do not process properties from content.
        if ($entityType === 'note') {
            $encryptedStmt = $pdo->prepare("SELECT 1 FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND value = 'true' AND internal = 1 LIMIT 1");
            $encryptedStmt->execute([':note_id' => $entityId]);
            if ($encryptedStmt->fetch()) {
                // Note is encrypted, do not parse/modify properties from its content.
                return [];
            }
        }

        // 1. Clear existing 'replaceable' properties.
        // According to config.php and the spec, properties with 'replace' behavior (weight < 4) are deleted and re-added from content.
        // Properties with 'append' behavior (weight >= 4) are preserved, and new ones are added.
        $deleteSql = "DELETE FROM Properties WHERE {$entityType}_id = ? AND weight < 4";
        $stmtDelete = $pdo->prepare($deleteSql);
        $stmtDelete->execute([$entityId]);

        // 2. Parse new properties from content.
        // We assume the parser is updated to return a structured array with name, value, and weight.
        $propertyParser = new PropertyParser($pdo);
        $parsedProperties = $propertyParser->parsePropertiesFromContent($content);

        // 3. Save all parsed properties and check for the 'internal' flag.
        $finalPropertiesForResponse = [];
        $hasInternalTrue = false;

        foreach ($parsedProperties as $prop) { // Assumes $prop is ['name' => ..., 'value' => ..., 'weight' => ...]
            $name = $prop['name'];
            $value = (string)$prop['value'];
            
            $isInternal = determinePropertyInternalStatus($name, $value);

            // This function will now handle adding all properties. For replaceable ones, they were just deleted.
            // For appendable ones, this will add a new record, creating history.
            _updateOrAddPropertyAndDispatchTriggers(
                $pdo,
                $entityType,
                $entityId,
                $name,
                $value,
                $isInternal,
                false // No individual commit inside the loop
            );

            if (strtolower($name) === 'internal' && strtolower($value) === 'true') {
                $hasInternalTrue = true;
            }

            // Structure for the API response
            if (!isset($finalPropertiesForResponse[$name])) {
                $finalPropertiesForResponse[$name] = [];
            }
            $finalPropertiesForResponse[$name][] = ['value' => $value, 'internal' => (int)$isInternal];
        }

        // 4. Update the note's 'internal' flag based on parsed properties.
        if ($entityType === 'note') {
            // This assumes a `Notes.internal` column exists, as per the original file's logic.
            // If the schema is missing it, this will fail. For bug-free output, we follow the code's intent.
            try {
                $updateStmt = $pdo->prepare("UPDATE Notes SET internal = ? WHERE id = ?");
                $updateStmt->execute([$hasInternalTrue ? 1 : 0, $entityId]);
            } catch (PDOException $e) {
                // Log if the column is missing, but don't fail the whole operation.
                error_log("Could not update Notes.internal flag. Column might be missing. Error: " . $e->getMessage());
            }
        }
        
        return $finalPropertiesForResponse;
    }
}


// Batch operation helper functions
if (!function_exists('_createNoteInBatch')) {
    function _createNoteInBatch($pdo, $payload, &$tempIdMap) {
        if (!isset($payload['page_id']) || !is_numeric($payload['page_id'])) {
            return ['type' => 'create', 'status' => 'error', 'message' => 'Missing or invalid page_id for create operation', 'client_temp_id' => $payload['client_temp_id'] ?? null];
        }

        $pageId = (int)$payload['page_id'];
        $content = $payload['content'] ?? '';
        $parentNoteId = null;
        if (isset($payload['parent_note_id'])) {
            $parentNoteId = ($payload['parent_note_id'] === null || $payload['parent_note_id'] === '') ? null : (int)$payload['parent_note_id'];
        }
        $orderIndex = $payload['order_index'] ?? null;
        $collapsed = $payload['collapsed'] ?? 0;
        $clientTempId = $payload['client_temp_id'] ?? null;

        try {
            // 1. Create the note
            $sqlFields = ['page_id', 'content', 'parent_note_id', 'collapsed'];
            $sqlParams = [
                ':page_id' => $pageId,
                ':content' => $content,
                ':parent_note_id' => $parentNoteId,
                ':collapsed' => (int)$collapsed
            ];

            if ($orderIndex !== null && is_numeric($orderIndex)) {
                $sqlFields[] = 'order_index';
                $sqlParams[':order_index'] = (int)$orderIndex;
            }
            
            $sqlFieldPlaceholders = implode(', ', $sqlFields);
            $sqlValuePlaceholders = ':' . implode(', :', $sqlFields);
            
            $sql = "INSERT INTO Notes ($sqlFieldPlaceholders) VALUES ($sqlValuePlaceholders)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($sqlParams);
            $noteId = $pdo->lastInsertId();

            if (!$noteId) {
                 return ['type' => 'create', 'status' => 'error', 'message' => 'Failed to create note record in database.', 'client_temp_id' => $clientTempId];
            }

            // 2. Handle properties using the new indexer
            $finalProperties = [];
            if (trim($content) !== '') {
                $finalProperties = _indexPropertiesFromContent($pdo, 'note', $noteId, $content);
            }

            // 3. Fetch the newly created note to return it
            $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = :id");
            $stmt->execute([':id' => $noteId]);
            $newNote = $stmt->fetch(PDO::FETCH_ASSOC);
            $newNote['properties'] = $finalProperties; // Attach the collected properties

            if ($clientTempId !== null) {
                $tempIdMap[$clientTempId] = $noteId;
            }
            
            $newNote['id'] = $noteId; 

            $result = [
                'type' => 'create',
                'status' => 'success',
                'note' => $newNote 
            ];
            if ($clientTempId !== null) {
                $result['client_temp_id'] = $clientTempId;
            }
            return $result;

        } catch (Exception $e) {
            return ['type' => 'create', 'status' => 'error', 'message' => 'Failed to create note: ' . $e->getMessage(), 'client_temp_id' => $clientTempId, 'details_trace' => $e->getTraceAsString()];
        }
    }
}

if (!function_exists('_updateNoteInBatch')) {
    function _updateNoteInBatch($pdo, $payload, $tempIdMap) {
        $originalId = $payload['id'] ?? null;
        $noteId = $originalId;

        if ($noteId === null) {
            return ['type' => 'update', 'status' => 'error', 'message' => 'Missing id for update operation', 'id' => $originalId];
        }

        if (is_string($noteId) && strpos($noteId, "temp:") === 0) { 
            if (isset($tempIdMap[$noteId])) {
                $noteId = $tempIdMap[$noteId];
            } else {
                return ['type' => 'update', 'status' => 'error', 'message' => "Update failed: Temporary ID {$originalId} not resolved.", 'id' => $originalId];
            }
        } elseif (!is_numeric($noteId)) {
             return ['type' => 'update', 'status' => 'error', 'message' => "Invalid ID format for update: {$originalId}", 'id' => $originalId];
        }
        $noteId = (int)$noteId;

        try {
            $stmtCheck = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
            $stmtCheck->execute([$noteId]);
            if (!$stmtCheck->fetch(PDO::FETCH_ASSOC)) {
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
                if (is_string($newParentNoteId) && strpos($newParentNoteId, "temp:") === 0) {
                    $newParentNoteId = $tempIdMap[$newParentNoteId] ?? null;
                    if ($newParentNoteId === null) {
                        return ['type' => 'update', 'status' => 'error', 'message' => "Update failed: Temporary parent_note_id {$payload['parent_note_id']} not resolved.", 'id' => $noteId];
                    }
                }
                $setClauses[] = "parent_note_id = ?";
                $executeParams[] = $newParentNoteId === null ? null : (int)$newParentNoteId;
            }
            if (isset($payload['order_index'])) {
                $setClauses[] = "order_index = ?";
                $executeParams[] = (int)$payload['order_index'];
            }
            if (isset($payload['collapsed'])) {
                $setClauses[] = "collapsed = ?";
                $executeParams[] = (int)$payload['collapsed'];
            }
            if (isset($payload['page_id']) && is_numeric($payload['page_id'])) {
                 $setClauses[] = "page_id = ?";
                 $executeParams[] = (int)$payload['page_id'];
            }

            if (empty($setClauses)) {
                return ['type' => 'update', 'status' => 'warning', 'message' => 'No updatable fields provided for note.', 'id' => $noteId];
            }

            $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
            $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
            $executeParams[] = $noteId;
            
            $stmt = $pdo->prepare($sql);
            if (!$stmt->execute($executeParams)) {
                return ['type' => 'update', 'status' => 'error', 'message' => 'Failed to update note record.', 'id' => $noteId, 'details' => $stmt->errorInfo()];
            }
            
            // Properties Handling: Always re-index from content if it was provided.
            if ($contentWasUpdated) {
                _indexPropertiesFromContent($pdo, 'note', $noteId, $payload['content']);
            }

            // Fetch updated note and its properties for the response
            $stmtFetch = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
            $stmtFetch->execute([$noteId]);
            $updatedNote = $stmtFetch->fetch(PDO::FETCH_ASSOC);

            $propSql = "SELECT name, value, internal FROM Properties WHERE note_id = :note_id ORDER BY name";
            $stmtProps = $pdo->prepare($propSql);
            $stmtProps->bindParam(':note_id', $noteId, PDO::PARAM_INT);
            $stmtProps->execute();
            $propertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);

            $updatedNote['properties'] = [];
            foreach ($propertiesResult as $prop) {
                if (!isset($updatedNote['properties'][$prop['name']])) {
                    $updatedNote['properties'][$prop['name']] = [];
                }
                $updatedNote['properties'][$prop['name']][] = [
                    'value' => $prop['value'],
                    'internal' => (int)$prop['internal']
                ];
            }
            
            $updatedNote['id'] = $noteId;

            return ['type' => 'update', 'status' => 'success', 'note' => $updatedNote];

        } catch (Exception $e) {
            return ['type' => 'update', 'status' => 'error', 'message' => 'Failed to update note: ' . $e->getMessage(), 'id' => $noteId, 'details_trace' => $e->getTraceAsString()];
        }
    }
}

if (!function_exists('_deleteNoteInBatch')) {
    function _deleteNoteInBatch($pdo, $payload, $tempIdMap) {
        $originalId = $payload['id'] ?? null;
        $noteId = $originalId;

        if ($noteId === null) {
            return ['type' => 'delete', 'status' => 'error', 'message' => 'Missing id for delete operation', 'id' => $originalId];
        }

        // Resolve ID if it's a temporary one
        if (is_string($noteId) && strpos($noteId, "temp:") === 0) {
            if (isset($tempIdMap[$noteId])) {
                $noteId = $tempIdMap[$noteId];
            } else {
                return ['type' => 'delete', 'status' => 'error', 'message' => "Delete failed: Temporary ID {$originalId} not resolved.", 'id' => $originalId];
            }
        } elseif (!is_numeric($noteId)) {
            return ['type' => 'delete', 'status' => 'error', 'message' => "Invalid ID format for delete: {$originalId}", 'id' => $originalId];
        }
        $noteId = (int)$noteId;

        try {
            // Check if note exists
            $stmtCheck = $pdo->prepare("SELECT id FROM Notes WHERE id = ?");
            $stmtCheck->execute([$noteId]);
            if (!$stmtCheck->fetch()) {
                return ['type' => 'delete', 'status' => 'error', 'message' => 'Note not found for delete.', 'id' => $noteId];
            }

            // Check for child notes (Option A: Stricter)
            $stmtCheckChildren = $pdo->prepare("SELECT COUNT(*) FROM Notes WHERE parent_note_id = ?");
            $stmtCheckChildren->execute([$noteId]);
            if ($stmtCheckChildren->fetchColumn() > 0) {
                return ['type' => 'delete', 'status' => 'error', 'message' => 'Note has child notes. Cannot delete directly. Delete children first.', 'id' => $noteId];
            }

            // Delete associated properties
            $stmtDeleteProps = $pdo->prepare("DELETE FROM Properties WHERE note_id = ?");
            $stmtDeleteProps->execute([$noteId]);

            // Delete the note
            $stmtDeleteNote = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
            if ($stmtDeleteNote->execute([$noteId])) {
                if ($stmtDeleteNote->rowCount() > 0) {
                    return ['type' => 'delete', 'status' => 'success', 'deleted_note_id' => $noteId];
                } else {
                    return ['type' => 'delete', 'status' => 'error', 'message' => 'Note not found during delete execution (unexpected).', 'id' => $noteId];
                }
            } else {
                return ['type' => 'delete', 'status' => 'error', 'message' => 'Failed to execute note deletion.', 'id' => $noteId, 'details_db' => $stmtDeleteNote->errorInfo()];
            }

        } catch (Exception $e) {
            return ['type' => 'delete', 'status' => 'error', 'message' => 'Failed to delete note: ' . $e->getMessage(), 'id' => $noteId, 'details_trace' => $e->getTraceAsString()];
        }
    }
}


if (!function_exists('_handleBatchOperations')) {
    function _handleBatchOperations($pdo, $operations) {
        $validationErrors = [];
        foreach ($operations as $index => $operation) {
            $operationHasError = false;
            if (!isset($operation['type']) || !is_string($operation['type'])) {
                $validationErrors[] = ['index' => $index, 'error' => 'Missing or invalid type field.'];
                $operationHasError = true;
            }
            if (!isset($operation['payload']) || !is_array($operation['payload'])) {
                $validationErrors[] = ['index' => $index, 'error' => 'Missing or invalid payload field.'];
                $operationHasError = true;
            }
            if ($operationHasError) continue;

            $type = $operation['type'];
            $payload = $operation['payload'];

            if (!in_array($type, ['create', 'update', 'delete'])) {
                $validationErrors[] = ['index' => $index, 'type' => $type, 'error' => 'Invalid operation type.'];
            }
            switch ($type) {
                case 'create':
                    if (!isset($payload['page_id'])) {
                        $validationErrors[] = ['index' => $index, 'type' => 'create', 'error' => 'Missing page_id in payload.'];
                    }
                    break;
                case 'update':
                case 'delete':
                    if (!isset($payload['id'])) {
                        $validationErrors[] = ['index' => $index, 'type' => $type, 'error' => 'Missing id in payload.'];
                    }
                    break;
            }
        }

        if (!empty($validationErrors)) {
            ApiResponse::error('Batch request validation failed.', 400, ['details' => ['validation_errors' => $validationErrors]]);
        }

        $deleteOps = [];
        $createOps = [];
        $updateOps = [];
        $orderedResults = array_fill(0, count($operations), null);
        $tempIdMap = []; 
        
        foreach ($operations as $index => $operation) {
            $opItem = ['original_index' => $index, 'data' => $operation];
            switch ($operation['type']) {
                case 'delete': $deleteOps[] = $opItem; break;
                case 'create': $createOps[] = $opItem; break;
                case 'update': $updateOps[] = $opItem; break;
                default:
                    $orderedResults[$index] = ['type' => $operation['type'], 'status' => 'error', 'message' => 'Invalid operation type during categorization.'];
                    break;
            }
        }
        
        // Process in Delete -> Create -> Update order
        $pdo->beginTransaction();
        try {
            foreach ($deleteOps as $opItem) {
                $orderedResults[$opItem['original_index']] = _deleteNoteInBatch($pdo, $opItem['data']['payload'], $tempIdMap);
            }
            foreach ($createOps as $opItem) {
                $orderedResults[$opItem['original_index']] = _createNoteInBatch($pdo, $opItem['data']['payload'], $tempIdMap);
            }
            foreach ($updateOps as $opItem) {
                $orderedResults[$opItem['original_index']] = _updateNoteInBatch($pdo, $opItem['data']['payload'], $tempIdMap);
            }
            
            $pdo->commit();

            ApiResponse::success([
                'message' => 'Batch operations completed. Check individual results for status.',
                'results' => $orderedResults
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Batch operation critical error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            ApiResponse::error('An internal server error occurred during batch processing.', 500, ['details' => ['general_error' => $e->getMessage()]]);
        }
    }
}

if ($method === 'GET') {
    // ... [GET LOGIC REMAINS UNCHANGED] ...
    $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
    $dataManager = new DataManager($pdo);

    try {
        if (isset($_GET['id'])) {
            // Get single note using DataManager
            $noteId = (int)$_GET['id'];
            $note = $dataManager->getNoteById($noteId, $includeInternal);
            
            if ($note) {
                // Standardize property structure
                if (isset($note['properties'])) {
                    $standardizedProps = [];
                    foreach ($note['properties'] as $name => $values) {
                        if (!is_array($values)) {
                            $values = [$values];
                        }
                        $standardizedProps[$name] = array_map(function($value) {
                            return is_array($value) ? $value : ['value' => $value, 'internal' => 0];
                        }, $values);
                    }
                    $note['properties'] = $standardizedProps;
                }
                ApiResponse::success($note);
            } else {
                ApiResponse::error('Note not found or is internal', 404);
            }
        } elseif (isset($_GET['page_id'])) {
            // Get page with notes using DataManager
            $pageId = (int)$_GET['page_id'];
            
            // First verify the page exists
            $pageCheckStmt = $pdo->prepare("SELECT id FROM Pages WHERE id = ?");
            $pageCheckStmt->execute([$pageId]);
            if (!$pageCheckStmt->fetch()) {
                ApiResponse::error('Page not found', 404);
            }
            
            $pageData = $dataManager->getPageWithNotes($pageId, $includeInternal);

            if ($pageData) {
                // Standardize property structure for all notes
                foreach ($pageData['notes'] as &$note) {
                    if (isset($note['properties'])) {
                        $standardizedProps = [];
                        foreach ($note['properties'] as $name => $values) {
                            if (!is_array($values)) {
                                $values = [$values];
                            }
                            $standardizedProps[$name] = array_map(function($value) {
                                return is_array($value) ? $value : ['value' => $value, 'internal' => 0];
                            }, $values);
                        }
                        $note['properties'] = $standardizedProps;
                    }
                }
                ApiResponse::success($pageData['notes']);
            } else {
                ApiResponse::error('Page not found', 404);
            }
        } else {
            // Get all notes with pagination
            $page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?? 1);
            $perPage = max(1, min(100, filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT) ?? 20));
            $offset = ($page - 1) * $perPage;

            // Get total count
            $countSql = "SELECT COUNT(*) FROM Notes";
            if (!$includeInternal) {
                $countSql .= " WHERE internal = 0";
            }
            $totalCount = $pdo->query($countSql)->fetchColumn();

            // Get paginated notes
            $sql = "SELECT Notes.*, EXISTS(SELECT 1 FROM Attachments WHERE Attachments.note_id = Notes.id) as has_attachments FROM Notes";
            if (!$includeInternal) {
                $sql .= " WHERE Notes.internal = 0";
            }
            $sql .= " ORDER BY Notes.created_at DESC LIMIT ? OFFSET ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$perPage, $offset]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get properties for all notes in this page
            if (!empty($notes)) {
                $noteIds = array_column($notes, 'id');
                $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
                $propSql = "SELECT note_id, name, value, internal FROM Properties WHERE note_id IN ($placeholders) ORDER BY note_id, name";
                $propStmt = $pdo->prepare($propSql);
                $propStmt->execute($noteIds);
                $properties = $propStmt->fetchAll(PDO::FETCH_ASSOC);

                $noteProperties = [];
                foreach ($properties as $prop) {
                    $noteId = $prop['note_id'];
                    if (!isset($noteProperties[$noteId])) $noteProperties[$noteId] = [];
                    if (!isset($noteProperties[$noteId][$prop['name']])) $noteProperties[$noteId][$prop['name']] = [];
                    $noteProperties[$noteId][$prop['name']][] = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
                }

                foreach ($notes as &$note) {
                    $note['properties'] = $noteProperties[$note['id']] ?? [];
                }
            }

            $totalPages = ceil($totalCount / $perPage);
            
            ApiResponse::success([
                'data' => $notes,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_count' => (int)$totalCount,
                    'total_pages' => $totalPages,
                    'has_next_page' => $page < $totalPages,
                    'has_previous_page' => $page > 1
                ]
            ]);
        }
    } catch (Exception $e) {
        error_log("API Error in notes.php: " . $e->getMessage());
        ApiResponse::error('An error occurred while fetching data: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'POST') {
    if (isset($input['action']) && $input['action'] === 'batch') {
        if (isset($input['operations']) && is_array($input['operations'])) {
            _handleBatchOperations($pdo, $input['operations']);
        } else {
            ApiResponse::error('Batch operations require an "operations" array.', 400);
        }
        return;
    }

    // Single note creation (legacy, batch is preferred)
    if (!isset($input['page_id']) || !is_numeric($input['page_id'])) {
        ApiResponse::error('A valid page_id is required.', 400);
    }

    $pageId = (int)$input['page_id'];
    $content = $input['content'] ?? '';
    $parentNoteId = isset($input['parent_note_id']) && ($input['parent_note_id'] !== '' && $input['parent_note_id'] !== null) ? (int)$input['parent_note_id'] : null;

    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO Notes (page_id, content, parent_note_id";
        $params = [':page_id' => $pageId, ':content' => $content, ':parent_note_id' => $parentNoteId];
        if (isset($input['order_index']) && is_numeric($input['order_index'])) {
            $sql .= ", order_index) VALUES (:page_id, :content, :parent_note_id, :order_index)";
            $params[':order_index'] = (int)$input['order_index'];
        } else {
            $sql .= ") VALUES (:page_id, :content, :parent_note_id)";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $noteId = $pdo->lastInsertId();

        // Use the new indexer to process properties from content
        $properties = _indexPropertiesFromContent($pdo, 'note', $noteId, $content);

        $pdo->commit();

        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteId]);
        $newNote = $stmt->fetch(PDO::FETCH_ASSOC);
        $newNote['properties'] = $properties;

        ApiResponse::success($newNote, 201);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Failed to create note: " . $e->getMessage());
        ApiResponse::error('Failed to create note.', 500, ['details' => $e->getMessage()]);
    }
} elseif ($method === 'PUT') {
    $noteId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($input['id']) ? (int)$input['id'] : null);
    if (!$noteId) ApiResponse::error('Note ID is required', 400);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->rollBack();
            ApiResponse::error('Note not found', 404);
        }

        $setClauses = [];
        $executeParams = [];
        $contentWasUpdated = false;

        if (isset($input['content'])) {
            $setClauses[] = "content = ?";
            $executeParams[] = $input['content'];
            $contentWasUpdated = true;
        }
        if (array_key_exists('parent_note_id', $input)) {
            $setClauses[] = "parent_note_id = ?";
            $executeParams[] = $input['parent_note_id'] === null ? null : (int)$input['parent_note_id'];
        }
        if (isset($input['order_index'])) {
            $setClauses[] = "order_index = ?";
            $executeParams[] = (int)$input['order_index'];
        }
        if (isset($input['collapsed'])) {
            $setClauses[] = "collapsed = ?";
            $executeParams[] = (int)$input['collapsed'];
        }

        if (empty($setClauses)) {
            $pdo->rollBack();
            ApiResponse::error('No updateable fields provided', 400);
        }

        $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
        $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
        $executeParams[] = $noteId;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($executeParams);

        // If content was updated, re-index all properties from it.
        if ($contentWasUpdated) {
            _indexPropertiesFromContent($pdo, 'note', $noteId, $input['content']);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $propSql = "SELECT name, value, internal FROM Properties WHERE note_id = :note_id ORDER BY name";
        $stmtProps = $pdo->prepare($propSql);
        $stmtProps->bindParam(':note_id', $note['id'], PDO::PARAM_INT);
        $stmtProps->execute();
        $propertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);

        $note['properties'] = [];
        foreach ($propertiesResult as $prop) {
            if (!isset($note['properties'][$prop['name']])) $note['properties'][$prop['name']] = [];
            $note['properties'][$prop['name']][] = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
        }
        
        $pdo->commit();
        ApiResponse::success($note);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Failed to update note: " . $e->getMessage());
        ApiResponse::error('Failed to update note: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'DELETE') {
    $noteId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($input['id']) ? (int)$input['id'] : null);
    if (!$noteId) ApiResponse::error('Note ID is required', 400);
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT id FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            ApiResponse::error('Note not found', 404);
        }
        
        $stmt = $pdo->prepare("DELETE FROM Properties WHERE note_id = ?");
        $stmt->execute([$noteId]);
        
        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        
        $pdo->commit();
        ApiResponse::success(['deleted_note_id' => $noteId]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        ApiResponse::error('Failed to delete note: ' . $e->getMessage(), 500);
    }
} else {
    ApiResponse::error('Method not allowed', 405);
}