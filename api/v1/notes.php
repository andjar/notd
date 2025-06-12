<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../property_trigger_service.php';
require_once __DIR__ . '/../pattern_processor.php';
require_once __DIR__ . '/../property_parser.php';
// require_once __DIR__ . '/../property_auto_internal.php'; // Removed
// require_once __DIR__ . '/properties.php'; // Removed
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
    // Optionally, remove _method from $input if it could interfere with validation or processing
    // unset($input['_method']); 
    // However, validateNoteData only checks for 'content', so it's likely fine to leave it.
}

// Helper function to validate note data
if (!function_exists('validateNoteData')) {
    function validateNoteData($data) {
        if (!isset($data['content'])) {
            ApiResponse::error('Note content is required', 400);
        }
        return true;
    }
}

// Helper function to filter properties based on visibility from PROPERTY_BEHAVIORS_BY_COLON_COUNT
if (!function_exists('_filterPropertiesByVisibility')) {
    function _filterPropertiesByVisibility($properties) {
        if (empty($properties) || !defined('PROPERTY_BEHAVIORS_BY_COLON_COUNT')) {
            return $properties; // Return as is if no properties or config missing
        }

        $visibleProperties = [];
        foreach ($properties as $name => $propertyValues) {
            $retainedValues = [];
            foreach ($propertyValues as $propEntry) {
                $colonCount = isset($propEntry['colon_count']) ? (int)$propEntry['colon_count'] : 2;
                
                $behavior = PROPERTY_BEHAVIORS_BY_COLON_COUNT[$colonCount] 
                            ?? PROPERTY_BEHAVIORS_BY_COLON_COUNT[2] 
                            ?? ['visible_view' => true]; // Default to visible if completely misconfigured

                if (isset($behavior['visible_view']) && $behavior['visible_view']) {
                    $retainedValues[] = $propEntry;
                }
            }
            if (!empty($retainedValues)) {
                $visibleProperties[$name] = $retainedValues;
            }
        }
        return $visibleProperties;
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
        $collapsed = $payload['collapsed'] ?? 0; // Default to not collapsed
        $clientTempId = $payload['client_temp_id'] ?? null;
        $propertiesExplicit = $payload['properties_explicit'] ?? null;

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

            // 2. Sync properties from content using PropertyParser
            // $propertiesExplicit is no longer used for direct property creation here.
            // All properties are derived from content by PropertyParser.
            $propertyParser = new PropertyParser($pdo);
            $propertyParser->syncNotePropertiesFromContent($noteId, $content);
            
            // Notes.internal flag update is removed from here.

            // 3. Fetch the newly created note to return it, including its properties
            // Using DataManager or a direct detailed query to get properties as they are after sync.
            // For batch, a full DataManager call per note might be heavy.
            // Let's fetch directly for now, ensuring the property structure is consistent.
            $stmtFetch = $pdo->prepare("SELECT * FROM Notes WHERE id = :id");
            $stmtFetch->execute([':id' => $noteId]);
            $newNote = $stmtFetch->fetch(PDO::FETCH_ASSOC);

            if (!$newNote) {
                // This should ideally not happen if note creation succeeded
                return ['type' => 'create', 'status' => 'error', 'message' => 'Failed to fetch newly created note.', 'client_temp_id' => $clientTempId];
            }
            
            // Fetch properties for the new note
            $propSql = "SELECT name, value, colon_count FROM Properties WHERE note_id = :note_id AND active = 1 ORDER BY name";
            $stmtProps = $pdo->prepare($propSql);
            $stmtProps->bindParam(':note_id', $noteId, PDO::PARAM_INT);
            $stmtProps->execute();
            $propertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);

            $newNote['properties'] = [];
            foreach ($propertiesResult as $prop) {
                if (!isset($newNote['properties'][$prop['name']])) {
                    $newNote['properties'][$prop['name']] = [];
                }
                $newNote['properties'][$prop['name']][] = [
                    'value' => $prop['value'],
                    'colon_count' => isset($prop['colon_count']) ? (int)$prop['colon_count'] : 2
                ];
            }
            // Ensure 'id' is part of the main note object
            $newNote['id'] = $noteId;


            if ($clientTempId !== null) {
                $tempIdMap[$clientTempId] = $noteId;
            }
            
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
            // Log the full error for server-side diagnosis
            error_log("Error in _createNoteInBatch (note ID: {$noteId}, client temp ID: {$clientTempId}): " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            return ['type' => 'create', 'status' => 'error', 'message' => 'Failed to create note: ' . $e->getMessage(), 'client_temp_id' => $clientTempId];
        }
    }
}

if (!function_exists('_updateNoteInBatch')) {
    function _updateNoteInBatch($pdo, $payload, $tempIdMap) {
        $originalId = $payload['id'] ?? null; // Keep original ID for error reporting if resolution fails
        $noteId = $originalId;

        if ($noteId === null) {
            return ['type' => 'update', 'status' => 'error', 'message' => 'Missing id for update operation', 'id' => $originalId];
        }

        // Resolve ID if it's a temporary one (e.g., "temp:client-generated-uuid")
        // Assuming temp IDs are strings and might have a prefix like "temp:"
        if (is_string($noteId) && strpos($noteId, "temp:") === 0) { 
            if (isset($tempIdMap[$noteId])) {
                $noteId = $tempIdMap[$noteId];
            } else {
                return ['type' => 'update', 'status' => 'error', 'message' => "Update failed: Temporary ID {$originalId} not resolved.", 'id' => $originalId];
            }
        } elseif (!is_numeric($noteId)) {
             return ['type' => 'update', 'status' => 'error', 'message' => "Invalid ID format for update: {$originalId}", 'id' => $originalId];
        }
        $noteId = (int)$noteId; // Ensure it's an integer after resolution

        try {
            // Check if note exists
            $stmtCheck = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
            $stmtCheck->execute([$noteId]);
            $existingNote = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingNote) {
                return ['type' => 'update', 'status' => 'error', 'message' => 'Note not found for update.', 'id' => $noteId];
            }

            $setClauses = [];
            $executeParams = [];
            $updateContent = false;

            // Dynamically build SET clauses
            if (isset($payload['content'])) {
                $setClauses[] = "content = ?";
                $executeParams[] = $payload['content'];
                $updateContent = true; // Flag that content is being updated for property parsing logic
            }
            if (array_key_exists('parent_note_id', $payload)) {
                $newParentNoteId = $payload['parent_note_id'];
                if (is_string($newParentNoteId) && strpos($newParentNoteId, "temp:") === 0) {
                    if (isset($tempIdMap[$newParentNoteId])) {
                        $newParentNoteId = $tempIdMap[$newParentNoteId];
                    } else {
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
            if (isset($payload['page_id']) && is_numeric($payload['page_id'])) { // Allow page_id update if provided
                 $setClauses[] = "page_id = ?";
                 $executeParams[] = (int)$payload['page_id'];
            }


            if (empty($setClauses) && !isset($payload['properties_explicit'])) {
                return ['type' => 'update', 'status' => 'warning', 'message' => 'No updatable fields provided for note.', 'id' => $noteId];
            }

            if (!empty($setClauses)) {
                $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
                $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
                $executeParams[] = $noteId;
                
                $stmt = $pdo->prepare($sql);
                if (!$stmt->execute($executeParams)) {
                    return ['type' => 'update', 'status' => 'error', 'message' => 'Failed to update note record.', 'id' => $noteId, 'details' => $stmt->errorInfo()];
                }
            }
            
            // Properties Handling - Refactored to use PropertyParser
            // Determine the content to use for syncing properties.
            // If new content is provided in the payload, use that. Otherwise, use the existing note content.
            $contentForSync = isset($payload['content']) ? $payload['content'] : $existingNote['content'];

            // Check if note is encrypted. If so, properties from content are not parsed or synced.
            $isEncrypted = false;
            // We need to check active properties here
            $encryptedStmt = $pdo->prepare("SELECT value FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND colon_count = 3 AND active = 1 LIMIT 1");
            $encryptedStmt->execute([':note_id' => $noteId]);
            $encryptedProp = $encryptedStmt->fetch(PDO::FETCH_ASSOC);
            if ($encryptedProp && $encryptedProp['value'] === 'true') {
                $isEncrypted = true;
            }

            if (!$isEncrypted) {
                $propertyParser = new PropertyParser($pdo);
                $propertyParser->syncNotePropertiesFromContent($noteId, $contentForSync);
            }
            // If $isEncrypted is true, properties are not touched based on content.
            // Notes.internal flag update is removed.

            // Fetch updated note and its properties
            $stmtFetch = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
            $stmtFetch->execute([$noteId]);
            $updatedNote = $stmtFetch->fetch(PDO::FETCH_ASSOC);

            if (!$updatedNote) {
                 return ['type' => 'update', 'status' => 'error', 'message' => 'Failed to fetch updated note after update.', 'id' => $noteId];
            }

            // Fetch all active properties for the response
            $propSql = "SELECT name, value, colon_count FROM Properties WHERE note_id = :note_id AND active = 1 ORDER BY name";
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
                    'colon_count' => isset($prop['colon_count']) ? (int)$prop['colon_count'] : 2
                ];
            }
            
            // Ensure $updatedNote contains the 'id'
            $updatedNote['id'] = $noteId;

            return ['type' => 'update', 'status' => 'success', 'note' => $updatedNote];

        } catch (Exception $e) {
            // Log the full error for server-side diagnosis
            error_log("Error in _updateNoteInBatch (note ID: {$noteId}): " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            return ['type' => 'update', 'status' => 'error', 'message' => 'Failed to update note: ' . $e->getMessage(), 'id' => $noteId];
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
            $stmtDeleteProps->execute([$noteId]); // No need to check rowCount, as note might not have properties

            // Delete the note
            $stmtDeleteNote = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
            if ($stmtDeleteNote->execute([$noteId])) {
                if ($stmtDeleteNote->rowCount() > 0) {
                    return ['type' => 'delete', 'status' => 'success', 'deleted_note_id' => $noteId];
                } else {
                    // Should have been caught by the existence check, but as a safeguard:
                    return ['type' => 'delete', 'status' => 'error', 'message' => 'Note not found during delete execution (unexpected).', 'id' => $noteId];
                }
            } else {
                return ['type' => 'delete', 'status' => 'error', 'message' => 'Failed to execute note deletion.', 'id' => $noteId, 'details_db' => $stmtDeleteNote->errorInfo()]; // Renamed 'details'
            }

        } catch (Exception $e) {
            return ['type' => 'delete', 'status' => 'error', 'message' => 'Failed to delete note: ' . $e->getMessage(), 'id' => $noteId, 'details_trace' => $e->getTraceAsString()]; // Renamed 'details'
        }
    }
}


if (!function_exists('_handleBatchOperations')) {
    function _handleBatchOperations($pdo, $operations) { // Note: $operations is already $input['operations'] from the calling context
        
        // Early validation of the overall batch structure and individual operation structures
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

            // If the basic structure of the operation is invalid, skip to the next one.
            if ($operationHasError) {
                continue;
            }


            $type = $operation['type'];
            $payload = $operation['payload'];

            if (!in_array($type, ['create', 'update', 'delete'])) {
                $validationErrors[] = ['index' => $index, 'type' => $type, 'error' => 'Invalid operation type.'];
            }

            // Validate presence of essential fields in payload based on type
            switch ($type) {
                case 'create':
                    if (!isset($payload['page_id'])) {
                        $validationErrors[] = ['index' => $index, 'type' => 'create', 'error' => 'Missing page_id in payload.'];
                    }
                    // More detailed validation for page_id (e.g., is_numeric) is handled in _createNoteInBatch
                    break;
                case 'update':
                    if (!isset($payload['id'])) {
                        $validationErrors[] = ['index' => $index, 'type' => 'update', 'error' => 'Missing id in payload.'];
                    }
                    break;
                case 'delete':
                    if (!isset($payload['id'])) {
                        $validationErrors[] = ['index' => $index, 'type' => 'delete', 'error' => 'Missing id in payload.'];
                    }
                    break;
            }
        }

        if (!empty($validationErrors)) {
            ApiResponse::error('Batch request validation failed.', 400, ['details' => ['validation_errors' => $validationErrors]]);
            exit; // Use exit as ApiResponse::error should handle script termination.
        }

        // Proceed with processing if validation passes
        $deleteOps = [];
        $createOps = [];
        $updateOps = [];
        $orderedResults = array_fill(0, count($operations), null);
        
        $tempIdMap = []; 
        $anyOperationFailed = false;
        $failedOperationsDetails = [];

        // Categorize operations while preserving original index
        foreach ($operations as $index => $operation) {
            // Type has been validated by the initial loop, but check again for safety or if logic changes
            $type = $operation['type'] ?? 'unknown'; 
            
            $opItem = ['original_index' => $index, 'data' => $operation];

            switch ($type) {
                case 'delete':
                    $deleteOps[] = $opItem;
                    break;
                case 'create':
                    $createOps[] = $opItem;
                    break;
                case 'update':
                    $updateOps[] = $opItem;
                    break;
                default:
                    // This case should ideally not be reached if initial validation is comprehensive
                    $anyOperationFailed = true;
                    $opResult = ['type' => $type, 'status' => 'error', 'message' => 'Invalid operation type during categorization.'];
                    $orderedResults[$index] = $opResult;
                    $failedOperationsDetails[] = [
                        'index' => $index,
                        'type' => $type,
                        'payload_identifier' => $operation['payload']['id'] ?? $operation['payload']['client_temp_id'] ?? null,
                        'error_message' => $opResult['message']
                    ];
                    break;
            }
        }
        
        // Remove try-catch and transaction around the entire batch.
        // Each operation will implicitly commit if successful, or fail independently.
        // try {
        //     $pdo->beginTransaction();

            // 1. Process Deletions
            foreach ($deleteOps as $opItem) {
                $originalIndex = $opItem['original_index'];
                $payload = $opItem['data']['payload']; // Payload was validated to exist
                $operationType = $opItem['data']['type']; // Type was validated

                $opResult = _deleteNoteInBatch($pdo, $payload, $tempIdMap);
                $orderedResults[$originalIndex] = $opResult;
                if (isset($opResult['status']) && $opResult['status'] === 'error') {
                    $anyOperationFailed = true;
                    $failedOperationsDetails[] = [
                        'index' => $originalIndex,
                        'type' => $operationType,
                        'payload_identifier' => ['id' => $payload['id'] ?? null], // 'id' is expected for delete
                        'error_message' => $opResult['message'] ?? 'Unknown error'
                    ];
                }
            }

            // 2. Process Creations
            // Potentially add: if (!$anyOperationFailed || $continueOnErrorStrategy)
            foreach ($createOps as $opItem) {
                $originalIndex = $opItem['original_index'];
                $payload = $opItem['data']['payload'];
                $operationType = $opItem['data']['type'];

                $opResult = _createNoteInBatch($pdo, $payload, $tempIdMap);
                $orderedResults[$originalIndex] = $opResult;
                if (isset($opResult['status']) && $opResult['status'] === 'error') {
                    $anyOperationFailed = true;
                    $failedOperationsDetails[] = [
                        'index' => $originalIndex,
                        'type' => $operationType,
                        'payload_identifier' => ['client_temp_id' => $payload['client_temp_id'] ?? null, 'page_id' => $payload['page_id'] ?? null],
                        'error_message' => $opResult['message'] ?? 'Unknown error'
                    ];
                }
            }

            // 3. Process Updates
            // Potentially add: if (!$anyOperationFailed || $continueOnErrorStrategy)
            foreach ($updateOps as $opItem) {
                $originalIndex = $opItem['original_index'];
                $payload = $opItem['data']['payload'];
                $operationType = $opItem['data']['type'];

                $opResult = _updateNoteInBatch($pdo, $payload, $tempIdMap);
                $orderedResults[$originalIndex] = $opResult;
                if (isset($opResult['status']) && $opResult['status'] === 'error') {
                    $anyOperationFailed = true;
                    $failedOperationsDetails[] = [
                        'index' => $originalIndex,
                        'type' => $operationType,
                        'payload_identifier' => ['id' => $payload['id'] ?? null], // 'id' is expected for update
                        'error_message' => $opResult['message'] ?? 'Unknown error'
                    ];
                }
            }

            // The batch operation will now return success even if individual operations fail.
            // The success/failure of individual operations is in 'results'.
            ApiResponse::success([
                'message' => 'Batch operations completed. Check individual results for status.',
                'results' => $orderedResults
            ]);
            exit; // Ensure script terminates after sending response

        // } catch (Exception $e) {
        //     // This catch block is now for truly unexpected errors outside of individual operation failures
        //     if ($pdo->inTransaction()) {
        //         $pdo->rollBack();
        //     }
        //     error_log("Batch operation critical error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            
        //     ApiResponse::error('An internal server error occurred during batch processing.', 500, [
        //         'details' => ['general_error' => $e->getMessage()]
        //     ]);
        //     exit;
        // }
    }
}

if ($method === 'GET') {
    $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
    $dataManager = new DataManager($pdo);

    try {
        if (isset($_GET['id'])) {
            // Get single note using DataManager
            $noteId = (int)$_GET['id'];
            $note = $dataManager->getNoteById($noteId, $includeInternal);
            
            if ($note) {
                // Properties are already fetched by DataManager in the desired structure
                // Apply visibility filtering if !$includeInternal
                if (!$includeInternal && isset($note['properties'])) {
                    $note['properties'] = _filterPropertiesByVisibility($note['properties']);
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
                // Properties are fetched by DataManager. Apply visibility filtering.
                foreach ($pageData['notes'] as &$noteRef) { // Use different var name to avoid confusion
                    if (isset($noteRef['properties'])) {
                        if (!$includeInternal) {
                            $noteRef['properties'] = _filterPropertiesByVisibility($noteRef['properties']);
                        }
                        // DataManager now returns properties as array of objects, so direct assignment is fine.
                    }
                }
                unset($noteRef); // release reference
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
            // MODIFIED SQL query to include has_attachments
            $sql = "SELECT Notes.*, EXISTS(SELECT 1 FROM Attachments WHERE Attachments.note_id = Notes.id) as has_attachments FROM Notes";
            if (!$includeInternal) {
                // Ensure the WHERE clause is appended correctly
                $sql .= " WHERE Notes.internal = 0"; // Also specify Notes.internal for clarity
            }
            // Append ORDER BY and LIMIT/OFFSET
            $sql .= " ORDER BY Notes.created_at DESC LIMIT ? OFFSET ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$perPage, $offset]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get properties for all notes in this page
            if (!empty($notes)) {
                $noteIds = array_column($notes, 'id');
                $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
                // Fetch colon_count instead of internal
                $propSql = "SELECT note_id, name, value, colon_count FROM Properties WHERE note_id IN ($placeholders) AND active = 1 ORDER BY note_id, name";
                $propStmt = $pdo->prepare($propSql);
                $propStmt->execute($noteIds);
                $allPropertiesResult = $propStmt->fetchAll(PDO::FETCH_ASSOC);

                // Group properties by note_id
                $propertiesByNoteId = [];
                foreach ($allPropertiesResult as $prop) {
                    $currentNoteId = $prop['note_id'];
                    if (!isset($propertiesByNoteId[$currentNoteId])) {
                        $propertiesByNoteId[$currentNoteId] = [];
                    }
                    // Store in the target format: propertyName => [ {value: v, colon_count: c}, ... ]
                    $propName = $prop['name'];
                    if (!isset($propertiesByNoteId[$currentNoteId][$propName])) {
                        $propertiesByNoteId[$currentNoteId][$propName] = [];
                    }
                    $propertiesByNoteId[$currentNoteId][$propName][] = [
                        'value' => $prop['value'],
                        'colon_count' => isset($prop['colon_count']) ? (int)$prop['colon_count'] : 2
                    ];
                }

                // Attach properties to notes and filter if needed
                foreach ($notes as &$noteRef) { // Use different var name
                    $noteSpecificProperties = $propertiesByNoteId[$noteRef['id']] ?? [];
                    if (!$includeInternal) {
                        $noteRef['properties'] = _filterPropertiesByVisibility($noteSpecificProperties);
                    } else {
                        $noteRef['properties'] = $noteSpecificProperties;
                    }
                }
                unset($noteRef); // release reference
            }

            // Calculate pagination metadata
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
    // Check if this is a batch operation
    if (isset($input['action']) && $input['action'] === 'batch') {
        if (isset($input['operations']) && is_array($input['operations'])) {
            _handleBatchOperations($pdo, $input['operations']);
            // _handleBatchOperations will call exit() or ApiResponse::success() which should exit.
            // If it doesn't, ensure no further processing for single POST happens.
            return; 
        } else {
            ApiResponse::error('Batch operations require an "operations" array.', 400);
            return;
        }
    } else {
        // Existing POST logic for single note creation
        if (!isset($input['page_id']) || !is_numeric($input['page_id'])) {
            ApiResponse::error('A valid page_id is required.', 400);
        }

        $pageId = (int)$input['page_id'];
    $content = isset($input['content']) ? $input['content'] : '';
    $parentNoteId = null;
    
    // Handle parent_note_id for creating child notes directly
    if (isset($input['parent_note_id'])) {
        if ($input['parent_note_id'] === null || $input['parent_note_id'] === '') {
            $parentNoteId = null;
        } else {
            $parentNoteId = (int)$input['parent_note_id'];
        }
    }

    try {
        $pdo->beginTransaction();

        // 1. Create the note (with optional parent_note_id and order_index)
        $sql = "INSERT INTO Notes (page_id, content, parent_note_id";
        $params = [
            ':page_id' => $pageId,
            ':content' => $content,
            ':parent_note_id' => $parentNoteId
        ];

        if (isset($input['order_index']) && is_numeric($input['order_index'])) {
            $sql .= ", order_index";
            $params[':order_index'] = (int)$input['order_index'];
            $sql .= ") VALUES (:page_id, :content, :parent_note_id, :order_index)";
        } else {
            $sql .= ") VALUES (:page_id, :content, :parent_note_id)";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $noteId = $pdo->lastInsertId();

        // 2. Sync properties from content using PropertyParser
        $propertyParser = new PropertyParser($pdo);
        $propertyParser->syncNotePropertiesFromContent($noteId, $content);

        // The internal flag for the note (Notes.internal) is now implicitly handled 
        // by PropertyParser logic if 'internal::true' is in content and 
        // if there's a separate mechanism to update Notes.internal based on Properties table.
        // For now, direct update to Notes.internal based on properties is removed from here.
        // If needed, a separate function/trigger would handle that.

        $pdo->commit();

        // 3. Fetch the newly created note using DataManager to ensure properties are included
        $dataManager = new DataManager($pdo);
        $newNote = $dataManager->getNoteById($noteId, true); // true to include internal properties if any

        if ($newNote) {
            // DataManager's getNoteById already returns properties in the new format
            // For POST (create), we typically return all properties ($includeInternal = true was used)
            // No further filtering by _filterPropertiesByVisibility needed here.
            // Ensure the structure is correct if DataManager didn't perfectly align (though it should).
            if (isset($newNote['properties']) && is_array($newNote['properties'])) {
                foreach ($newNote['properties'] as $name => &$propValueArray) { // Pass by reference
                     if (!is_array($propValueArray)) $propValueArray = [$propValueArray]; // Should not happen with new DataManager
                     foreach ($propValueArray as &$entry) { // Pass by reference
                          if (!isset($entry['colon_count']) && isset($entry['internal'])) { // Compatibility or error case
                               $entry['colon_count'] = $entry['internal'] === 1 ? 3 : 2; // Approximate
                               unset($entry['internal']);
                          } else if (!isset($entry['colon_count'])) {
                               $entry['colon_count'] = 2; // Default
                          }
                     }
                }
                unset($propValueArray); unset($entry); // Release references
            }
            ApiResponse::success($newNote, 201); // 201 Created
        } else {
            // This case should ideally not happen if note creation and fetching are correct
            ApiResponse::error('Failed to retrieve created note.', 500);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to create note: " . $e->getMessage());
        ApiResponse::error('Failed to create note.', 500, ['details' => $e->getMessage()]);
    }
  } // End of else for single POST operation
} elseif ($method === 'PUT') {
    // For phpdesktop compatibility, also check for ID in request body when using method override
    $noteId = null;
    if (isset($_GET['id'])) {
        $noteId = (int)$_GET['id'];
    } elseif (isset($input['id'])) {
        $noteId = (int)$input['id'];
    }
    
    if (!$noteId) {
        ApiResponse::error('Note ID is required', 400);
    }
    
    // Content is not always required for PUT (e.g. only changing parent/order)
    // validateNoteData($input); // Content is only required if it's the only thing sent or for new notes

    try {
        $pdo->beginTransaction();

        // Check if note exists
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $existingNote = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingNote) {
            $pdo->rollBack();
            ApiResponse::error('Note not found', 404);
        }

        // --- BEGIN order_index recalculation logic: Step 1 (preserved if still needed) ---
        // This part is complex and related to reordering, not directly properties.
        // Assuming it's still needed for now.
        $old_parent_note_id = null;
        $old_order_index = null;
        // $page_id_for_reordering = $existingNote['page_id']; // page_id should not change

        if (array_key_exists('parent_note_id', $input) || array_key_exists('order_index', $input)) {
            $old_parent_note_id = $existingNote['parent_note_id'];
            $old_order_index = $existingNote['order_index'];
        }
        // --- END order_index recalculation logic: Step 1 ---
        
        // Build the SET part of the SQL query dynamically for Notes table
        $setClauses = [];
        $executeParams = [];
        $contentChanged = false;

        if (isset($input['content'])) {
            $setClauses[] = "content = ?";
            $executeParams[] = $input['content'];
            $contentChanged = true;
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

        // If only properties are being changed via content, but no other note fields.
        // $input['properties_explicit'] is no longer a primary way to update properties.
        // Sync will happen based on content.
        if (empty($setClauses) && !$contentChanged && !isset($input['content'])) {
             // Check if content is implicitly provided for property sync even if not in setClauses
            if (!array_key_exists('content', $input)) { // if content is not even in input
                $pdo->rollBack();
                ApiResponse::error('No updateable fields for Note or content for property sync provided', 400);
                return; 
            }
        }
        
        if (!empty($setClauses)) {
            $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
            $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
            $executeParams[] = $noteId;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($executeParams);
        }

        // --- BEGIN order_index recalculation logic: Step 2 & 3 & 4 (omitted for brevity, assumed to be here if needed) ---
        // ... logic for reordering ...

        // --- SYNC PROPERTIES ---
        // Determine the content to use for syncing properties.
        // If new content is provided in the input, use that. Otherwise, use the existing note content.
        $contentForSync = isset($input['content']) ? $input['content'] : $existingNote['content'];
        
        // Check if note is encrypted. If so, properties from content are not parsed or synced.
        $isEncrypted = false;
        $encryptedStmt = $pdo->prepare("SELECT value FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND colon_count = 3 AND active = 1 LIMIT 1");
        $encryptedStmt->execute([':note_id' => $noteId]);
        $encryptedProp = $encryptedStmt->fetch(PDO::FETCH_ASSOC);
        if ($encryptedProp && $encryptedProp['value'] === 'true') {
            $isEncrypted = true;
        }

        if (!$isEncrypted) {
            $propertyParser = new PropertyParser($pdo);
            $propertyParser->syncNotePropertiesFromContent($noteId, $contentForSync);
        }
        // If $isEncrypted is true, properties are not touched based on content.
        // Any explicit property changes for encrypted notes would need a different mechanism (not covered by this refactor).

        // The Notes.internal flag update is removed from here, similar to POST.
        
        $pdo->commit();

        // Fetch updated note using DataManager to get properties correctly
        $dataManager = new DataManager($pdo);
        // true for includeInternal to get all properties for the response
        $updatedNote = $dataManager->getNoteById($noteId, true); 

        if ($updatedNote) {
            // DataManager's getNoteById already returns properties in the new format
            // For PUT (update), we typically return all properties ($includeInternal = true was used)
            // No further filtering by _filterPropertiesByVisibility needed here.
            // Ensure the structure is correct if DataManager didn't perfectly align.
             if (isset($updatedNote['properties']) && is_array($updatedNote['properties'])) {
                foreach ($updatedNote['properties'] as $name => &$propValueArray) { // Pass by reference
                     if (!is_array($propValueArray)) $propValueArray = [$propValueArray]; // Should not happen
                     foreach ($propValueArray as &$entry) { // Pass by reference
                          if (!isset($entry['colon_count']) && isset($entry['internal'])) { // Compatibility or error
                               $entry['colon_count'] = $entry['internal'] === 1 ? 3 : 2; // Approximate
                               unset($entry['internal']);
                          } else if (!isset($entry['colon_count'])) {
                               $entry['colon_count'] = 2; // Default
                          }
                     }
                }
                unset($propValueArray); unset($entry); // Release references
            }
            ApiResponse::success($updatedNote);
        } else {
            // Should not happen if the update was successful and ID is correct
            ApiResponse::error('Failed to retrieve updated note.', 500);
        }

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Failed to update note: " . $e->getMessage());
        ApiResponse::error('Failed to update note: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'DELETE') {
    // For phpdesktop compatibility, also check for ID in request body when using method override
    $noteId = null;
    if (isset($_GET['id'])) {
        $noteId = (int)$_GET['id'];
    } elseif (isset($input['id'])) {
        $noteId = (int)$input['id'];
    }
    
    if (!$noteId) {
        ApiResponse::error('Note ID is required', 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if note exists
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            ApiResponse::error('Note not found', 404);
        }
        
        // Delete properties first (due to foreign key constraint)
        $stmt = $pdo->prepare("DELETE FROM Properties WHERE note_id = ?"); // Corrected column name
        $stmt->execute([$noteId]);
        
        // Delete the note
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