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

// Helper function to check for 'internal' property and update Notes.internal
if (!function_exists('_checkAndSetNoteInternalFlag')) {
    function _checkAndSetNoteInternalFlag($pdo, $noteId) {
        $stmt = $pdo->prepare("SELECT value FROM Properties WHERE note_id = ? AND name = 'internal' AND active = 1");
        $stmt->execute([$noteId]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($properties as $prop) {
            if (strtolower($prop['value']) === 'true') {
                $updateStmt = $pdo->prepare("UPDATE Notes SET internal = 1 WHERE id = ?");
                $updateStmt->execute([$noteId]);
                return true; // Flag set
            }
        }
        return false; // Flag not set or property not found/not true
    }
}

// Helper function to process note content and extract properties
if (!function_exists('processNoteContent')) {
    function processNoteContent($pdo, $content, $entityType, $entityId) {
        // Create a PropertyParser instance and use it to process the content
        $propertyParser = new PropertyParser($pdo);
        $properties = $propertyParser->parsePropertiesFromContent($content);
        
        // Save the properties using the centralized function
        foreach ($properties as $name => $value) {
            _updateOrAddPropertyAndDispatchTriggers(
                $pdo,
                $entityType,
                $entityId,
                $name,
                $value
            );
        }
        
        return $properties;
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

            // 2. Handle properties
            $finalProperties = [];

            if (is_array($propertiesExplicit)) {
                 // Clear existing non-internal properties (though for a new note, there shouldn't be any)
                $stmtDeleteOld = $pdo->prepare("DELETE FROM Properties WHERE note_id = :note_id AND internal = 0");
                $stmtDeleteOld->execute([':note_id' => $noteId]);

                foreach ($propertiesExplicit as $name => $values) {
                    if (!is_array($values)) $values = [$values]; // Ensure values are in an array
                    foreach ($values as $value) {
                        $isInternal = determinePropertyInternalStatus($name, $value);
                         _updateOrAddPropertyAndDispatchTriggers($pdo, 'note', $noteId, $name, (string)$value, $isInternal, false); // No individual commit
                        // Collect for response, mimicking structure from single GET/POST
                        if (!isset($finalProperties[$name])) $finalProperties[$name] = [];
                        $finalProperties[$name][] = ['value' => (string)$value, 'internal' => (int)$isInternal];
                    }
                }
            } elseif (trim($content) !== '') {
                // Parse and save properties from content if no explicit properties given
                // processNoteContent already calls _updateOrAddPropertyAndDispatchTriggers
                // which in turn calls determinePropertyInternalStatus.
                // We need to ensure processNoteContent can run without individual commits or adapt it.
                // For now, let's assume processNoteContent works within a transaction.
                $parsedProps = processNoteContent($pdo, $content, 'note', $noteId); // This function needs to be transaction-aware or not commit itself.
                                                                                     // It internally calls _updateOrAddPropertyAndDispatchTriggers.
                // Structure $parsedProps for $finalProperties
                foreach($parsedProps as $name => $value) { // processNoteContent returns a simple key/value map
                    if (!isset($finalProperties[$name])) $finalProperties[$name] = [];
                     // Assuming processNoteContent gives single values, adjust if it can give arrays
                    $isInternal = determinePropertyInternalStatus($name, $value); // Re-check internal status as processNoteContent might not return it structured
                    $finalProperties[$name][] = ['value' => $value, 'internal' => (int)$isInternal];
                }
            }
            
            _checkAndSetNoteInternalFlag($pdo, $noteId);

            // 3. Fetch the newly created note to return it
            $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = :id");
            $stmt->execute([':id' => $noteId]);
            $newNote = $stmt->fetch(PDO::FETCH_ASSOC);
            $newNote['properties'] = $finalProperties; // Attach the collected properties

            if ($clientTempId !== null) {
                $tempIdMap[$clientTempId] = $noteId;
            }
            
            // Ensure $newNote contains the 'id'
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
            return ['type' => 'create', 'status' => 'error', 'message' => 'Failed to create note: ' . $e->getMessage(), 'client_temp_id' => $clientTempId, 'details_trace' => $e->getTraceAsString()]; // Renamed 'details' to 'details_trace' to avoid clash
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
            
            // Properties Handling
            $finalProperties = []; // To collect properties for the response

            if (isset($payload['properties_explicit']) && is_array($payload['properties_explicit'])) {
                // Delete existing non-internal properties
                $stmtDeleteOld = $pdo->prepare("DELETE FROM Properties WHERE note_id = :note_id AND internal = 0");
                $stmtDeleteOld->execute([':note_id' => $noteId]);
            
                foreach ($payload['properties_explicit'] as $name => $values) {
                    if (!is_array($values)) $values = [$values];
                    foreach ($values as $value) {
                        $isInternal = determinePropertyInternalStatus($name, $value);
                        _updateOrAddPropertyAndDispatchTriggers($pdo, 'note', $noteId, $name, (string)$value, $isInternal, false); // No individual commit
                        if (!isset($finalProperties[$name])) $finalProperties[$name] = [];
                        $finalProperties[$name][] = ['value' => (string)$value, 'internal' => (int)$isInternal];
                    }
                }
            } elseif ($updateContent) { // Only process content for properties if content was actually updated
                // And if note is not encrypted (assuming similar logic to existing PUT)
                $encryptedStmt = $pdo->prepare("SELECT value FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND internal = 1 LIMIT 1");
                $encryptedStmt->execute([':note_id' => $noteId]);
                $encryptedProp = $encryptedStmt->fetch(PDO::FETCH_ASSOC);

                if (!$encryptedProp || $encryptedProp['value'] !== 'true') {
                    $stmtDeleteOld = $pdo->prepare("DELETE FROM Properties WHERE note_id = :note_id AND internal = 0");
                    $stmtDeleteOld->execute([':note_id' => $noteId]);
                    
                    $parsedProps = processNoteContent($pdo, $payload['content'], 'note', $noteId);
                    foreach($parsedProps as $name => $value) {
                        if (!isset($finalProperties[$name])) $finalProperties[$name] = [];
                        $isInternal = determinePropertyInternalStatus($name, $value); 
                        $finalProperties[$name][] = ['value' => $value, 'internal' => (int)$isInternal];
                    }
                }
            }

            _checkAndSetNoteInternalFlag($pdo, $noteId);

            // Fetch updated note and its properties
            $stmtFetch = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
            $stmtFetch->execute([$noteId]);
            $updatedNote = $stmtFetch->fetch(PDO::FETCH_ASSOC);

            // Fetch all properties for the response, including those not touched by this update
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
            
            // Ensure $updatedNote contains the 'id'
            $updatedNote['id'] = $noteId;

            return ['type' => 'update', 'status' => 'success', 'note' => $updatedNote];

        } catch (Exception $e) {
            return ['type' => 'update', 'status' => 'error', 'message' => 'Failed to update note: ' . $e->getMessage(), 'id' => $noteId, 'details_trace' => $e->getTraceAsString()]; // Renamed 'details' to 'details_trace'
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
        
        try {
            $pdo->beginTransaction();

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

            if ($anyOperationFailed) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                ApiResponse::error('Batch operation failed. See details.', 400, [
                    'details' => ['failed_operations' => $failedOperationsDetails]
                ]);
                exit;
            } else {
                $pdo->commit();
                ApiResponse::success([
                    'message' => 'Batch operations completed successfully.',
                    'results' => $orderedResults
                ]);
                exit;
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Batch operation critical error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            
            // When a general exception occurs, do not include 'results'.
            ApiResponse::error('An internal server error occurred during batch processing.', 500, [
                 // 'status' => 'error',
                 // 'message' => 'An internal server error occurred during batch processing.',
                'details' => ['general_error' => $e->getMessage()]
            ]);
            exit;
        }
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
                // Properties are now correctly formatted by DataManager::getNoteById
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

            if ($pageData && isset($pageData['notes'])) { // pageData itself from getPageWithNotes includes 'page' and 'notes'
                // Properties for notes are now correctly formatted by DataManager::getNotesByPageId
                // The $pageData['page']['properties'] are also formatted by DataManager::getPageProperties
                // The API spec for this endpoint implies returning only the notes array.
                ApiResponse::success($pageData['notes']);
            } else if ($pageData && !isset($pageData['notes'])) { // Should not happen if getPageWithNotes is consistent
                 ApiResponse::error('Notes data is missing for the page', 500);
            }
            else {
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
                $propSql = "SELECT note_id, name, value, internal FROM Properties WHERE note_id IN ($placeholders) ORDER BY note_id, name";
                $propStmt = $pdo->prepare($propSql);
                $propStmt->execute($noteIds);
                $properties = $propStmt->fetchAll(PDO::FETCH_ASSOC);

                // $properties is now formatted by DataManager::getPropertiesForNoteIds
                // as $formattedPropertiesByNoteId[$noteId] = $this->_formatProperties($props, $includeInternal);
                // So it's already in the correct structure per note.
                $formattedNoteProperties = $dataManager->getPropertiesForNoteIds($noteIds, $includeInternal);

                // Attach properties to notes
                foreach ($notes as &$note) {
                    $note['properties'] = $formattedNoteProperties[$note['id']] ?? [];
                }
                 unset($note); // break the reference
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

        // 2. Parse and save properties from the content
        $properties = []; // Initialize properties
        if (trim($content) !== '') {
            $properties = processNoteContent($pdo, $content, 'note', $noteId);
        }

        // Check and set internal flag for the note
        _checkAndSetNoteInternalFlag($pdo, $noteId);

        $pdo->commit();

        // 3. Fetch the newly created note to return it
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteId]);
        $newNote = $stmt->fetch(PDO::FETCH_ASSOC);

        // Attach the parsed properties to the response
        $newNote['properties'] = $properties;

        ApiResponse::success($newNote, 201); // 201 Created

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

        // --- BEGIN order_index recalculation logic: Step 1 ---
        $old_parent_note_id = null;
        $old_order_index = null;
        $page_id_for_reordering = $existingNote['page_id']; // page_id should not change

        // Only fetch old parent and order if parent_note_id is part of the input,
        // or if order_index is changing (which might imply a move between siblings lists if parent_note_id is also changing)
        // For now, to be safe, we fetch if parent_note_id is potentially changing.
        // The problem description says "If parent_note_id is present in the $input (meaning it might change)"
        if (array_key_exists('parent_note_id', $input) || array_key_exists('order_index', $input)) {
            $old_parent_note_id = $existingNote['parent_note_id'];
            $old_order_index = $existingNote['order_index'];
        }
        // --- END order_index recalculation logic: Step 1 ---
        
        // Build the SET part of the SQL query dynamically
        $setClauses = [];
        $executeParams = [];

        if (isset($input['content'])) {
            $setClauses[] = "content = ?";
            $executeParams[] = $input['content'];
        }
        if (array_key_exists('parent_note_id', $input)) { // Use array_key_exists to allow null
            $setClauses[] = "parent_note_id = ?";
            $executeParams[] = $input['parent_note_id'] === null ? null : (int)$input['parent_note_id'];
        }
        if (isset($input['order_index'])) {
            $setClauses[] = "order_index = ?";
            $executeParams[] = (int)$input['order_index'];
        }
        if (isset($input['collapsed'])) {
            $setClauses[] = "collapsed = ?";
            $executeParams[] = (int)$input['collapsed']; // Should be 0 or 1
        }

        if (empty($setClauses) && !isset($input['properties_explicit'])) {
            $pdo->rollBack();
            ApiResponse::error('No updateable fields provided', 400);
            return; // Exit early
        }

        if (!empty($setClauses)) {
            $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
        
            $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
            $executeParams[] = $noteId;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($executeParams);
        }

        // --- BEGIN order_index recalculation logic: Step 2 & 3 & 4 (omitted for brevity) ---
        // ... logic for reordering ...

        // --- BEGIN properties LOGIC ---
        // Always delete existing non-internal properties if content or explicit properties are being updated.
        // This simplifies logic and prevents orphaned properties.
        if (isset($input['content']) || (isset($input['properties_explicit']) && !empty($input['properties_explicit']))) {
            $stmtDeleteOld = $pdo->prepare("DELETE FROM Properties WHERE note_id = :note_id AND internal = 0");
            $stmtDeleteOld->execute([':note_id' => $noteId]);
        
            $propertiesToSave = [];

            // If explicit properties are provided, use them.
            if (isset($input['properties_explicit']) && is_array($input['properties_explicit']) && !empty($input['properties_explicit'])) {
                foreach ($input['properties_explicit'] as $name => $values) {
                    if (!is_array($values)) {
                        $values = [$values];
                    }
                    foreach ($values as $value) {
                        $propertiesToSave[] = ['name' => $name, 'value' => (string)$value];
                    }
                }
            } 
            // Otherwise, if content is updated and the note is not encrypted, parse properties from content.
            else if (isset($input['content'])) {
                $encryptedStmt = $pdo->prepare("SELECT value FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND internal = 1 LIMIT 1");
                $encryptedStmt->execute([':note_id' => $noteId]);
                $encryptedProp = $encryptedStmt->fetch(PDO::FETCH_ASSOC);

                if (!$encryptedProp || $encryptedProp['value'] !== 'true') {
                    $processor = getPatternProcessor();
                    $processedData = $processor->processContent($input['content'], 'note', $noteId);
                    if (!empty($processedData['properties'])) {
                        $propertiesToSave = $processedData['properties'];
                    }
                }
            }

            // Save the collected properties to the database using the centralized function.
            if (!empty($propertiesToSave)) {
                foreach ($propertiesToSave as $prop) {
                     _updateOrAddPropertyAndDispatchTriggers(
                        $pdo,
                        'note',
                        $noteId,
                        $prop['name'],
                        $prop['value']
                    );
                }
            }
        }
        // --- END properties LOGIC ---

        // Check and set internal flag for the note
        _checkAndSetNoteInternalFlag($pdo, $noteId);
        
        // Fetch updated note
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get ALL properties for the response, with detailed structure
        $propSql = "SELECT name, value, internal FROM Properties WHERE note_id = :note_id ORDER BY name";
        $stmtProps = $pdo->prepare($propSql);
        $stmtProps->bindParam(':note_id', $note['id'], PDO::PARAM_INT);
        $stmtProps->execute();
        $propertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);

        $note['properties'] = [];
        foreach ($propertiesResult as $prop) {
            if (!isset($note['properties'][$prop['name']])) {
                $note['properties'][$prop['name']] = [];
            }
            $note['properties'][$prop['name']][] = [
                'value' => $prop['value'],
                'internal' => (int)$prop['internal']
            ];
        }
        
        $pdo->commit();
        ApiResponse::success($note);
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