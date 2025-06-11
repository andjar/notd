<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
// require_once __DIR__ . '/../property_triggers.php'; // Old trigger system replaced
require_once __DIR__ . '/../property_trigger_service.php'; // New trigger service
require_once __DIR__ . '/../property_auto_internal.php';
require_once __DIR__ . '/../response_utils.php'; // Include the new response utility
require_once __DIR__ . '/../data_manager.php';   // Include the new DataManager
require_once __DIR__ . '/../validator_utils.php'; // Include the new Validator

// header('Content-Type: application/json'); // Will be handled by ApiResponse

// This specific validation can be replaced by Validator class or kept if it has special logic (like tag normalization)
// For now, let's assume Validator can handle 'name' and 'value' presence, but tag normalization is specific.
// We might call Validator first, then this if basic checks pass.
if (!function_exists('validate_property_data')) {
    function validate_property_data($data) {
        if (!isset($data['name']) || !isset($data['value'])) {
            return false;
        }
        
        // Handle tag::tag format
        if (strpos($data['name'], 'tag::') === 0) {
            $tagName = substr($data['name'], 5);
            if (empty($tagName)) {
                return false;
            }
            // Normalize tag value to match the tag name
            $data['value'] = $tagName;
        }
        
        return $data;
    }
}

// Add this helper function after validate_property_data
if (!function_exists('checkEntityExists')) {
    function checkEntityExists($pdo, $entityType, $entityId) {
        if ($entityType === 'note') {
            $stmt = $pdo->prepare("SELECT id FROM Notes WHERE id = ?");
        } else { // 'page'
            $stmt = $pdo->prepare("SELECT id FROM Pages WHERE id = ?");
        }
        $stmt->execute([$entityId]);
        return $stmt->fetch() !== false;
    }
}

/**
 * Core function to add/update a property, determine its internal status, and dispatch triggers.
 * This function assumes $pdo is available and a transaction might be externally managed if multiple operations are batched.
 * If no transaction is externally managed, it should handle its own.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $entityType 'note' or 'page'.
 * @param int $entityId The ID of the note or page.
 * @param string $name The name of the property.
 * @param mixed $value The value of the property.
 * @param int|null $explicitInternal Explicitly sets the internal status (0 or 1). If null, it's determined automatically.
 * @return array Associative array with 'name', 'value', 'internal' of the saved property.
 * @throws Exception If validation fails or DB operation fails.
 */
if (!function_exists('_updateOrAddPropertyAndDispatchTriggers')) {
    function _updateOrAddPropertyAndDispatchTriggers($pdo, $entityType, $entityId, $name, $value, $explicitInternal = null) {
        // Validate and normalize property data
        $propertyData = validate_property_data(['name' => $name, 'value' => $value]);
    if (!$propertyData) {
        throw new Exception('Invalid property data');
    }
    
    $validatedName = $propertyData['name'];
    $validatedValue = $propertyData['value'];

    // Deactivate existing properties
    if ($entityType === 'page') {
        $deactivateStmt = $pdo->prepare("UPDATE Properties SET active = 0 WHERE page_id = ? AND name = ?");
    } else { // 'note'
        $deactivateStmt = $pdo->prepare("UPDATE Properties SET active = 0 WHERE note_id = ? AND name = ?");
    }
    $deactivateStmt->execute([$entityId, $validatedName]);
    
    // Check property definitions to determine internal status
    // $explicitInternal will typically come from property definition applications
    $finalInternal = determinePropertyInternalStatus($pdo, $validatedName, $explicitInternal);
    
    if ($entityType === 'page') {
        $stmt = $pdo->prepare("
            REPLACE INTO Properties (page_id, note_id, name, value, internal, active)
            VALUES (?, NULL, ?, ?, ?, 1)
        ");
        $stmt->execute([$entityId, $validatedName, $validatedValue, $finalInternal]);
    } else { // 'note'
        // Try to update existing property first
        $updateStmt = $pdo->prepare("
            UPDATE Properties 
            SET value = ?, internal = ?, active = 1, updated_at = CURRENT_TIMESTAMP 
            WHERE note_id = ? AND name = ?
        ");
        $updateStmt->execute([$validatedValue, $finalInternal, $entityId, $validatedName]);
        
        // If no rows were affected, insert a new one
        if ($updateStmt->rowCount() === 0) {
            $insertStmt = $pdo->prepare("
                INSERT INTO Properties (note_id, page_id, name, value, internal, active)
                VALUES (?, NULL, ?, ?, ?, 1)
            ");
            $insertStmt->execute([$entityId, $validatedName, $validatedValue, $finalInternal]);
        }
    }
    
    // Dispatch triggers using the service
    $triggerService = new PropertyTriggerService($pdo);
    $triggerService->dispatch($entityType, $entityId, $validatedName, $validatedValue);
    
    return ['name' => $validatedName, 'value' => $validatedValue, 'internal' => $finalInternal];
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    // GET /api/properties.php?entity_type=note&entity_id=123
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $validationRules = [
            'entity_type' => 'required|isValidEntityType',
            'entity_id' => 'required|isPositiveInteger'
            // include_internal is boolean, filter_input is fine
        ];
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid input parameters.', 400, $errors);
            exit;
        }

        $entityType = $_GET['entity_type']; // Validated
        $entityId = (int)$_GET['entity_id']; // Validated
        $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);

        try {
            $pdo = get_db_connection();
            
            // Check if entity exists
            if (!checkEntityExists($pdo, $entityType, $entityId)) {
                ApiResponse::error($entityType === 'note' ? 'Note not found' : 'Page not found', 404);
                exit;
            }

            $dataManager = new DataManager($pdo);
            $properties = null;

            if ($entityType === 'note') {
                $properties = $dataManager->getNoteProperties($entityId, $includeInternal);
            } elseif ($entityType === 'page') {
                $properties = $dataManager->getPageProperties($entityId, $includeInternal);
            }
            
            // Properties are now correctly formatted by DataManager methods (getNoteProperties, getPageProperties)
            // which use the updated _formatProperties.
            ApiResponse::success($properties);
            
        } catch (Exception $e) {
            ApiResponse::error('Server error: ' . $e->getMessage(), 500);
        }
    }

    // POST /api/properties.php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            ApiResponse::error('Invalid JSON', 400);
            exit;
        }
        
        // Check if this is a delete action
        if (isset($input['action'])) {
            if ($input['action'] === 'delete') {
                $validationRules = [
                    'entity_type' => 'required|isValidEntityType',
                    'entity_id' => 'required|isPositiveInteger',
                    'name' => 'required|isNotEmpty'
                ];
                $errors = Validator::validate($input, $validationRules);
                if (!empty($errors)) {
                    ApiResponse::error('Invalid input for deleting property.', 400, $errors);
                    exit;
                }
                
                $entityType = $input['entity_type']; // Validated
                $entityId = (int)$input['entity_id']; // Validated
                $name = $input['name']; // Validated
                
                try {
                    $pdo = get_db_connection();
                    
                    // Check if entity exists
                    if (!checkEntityExists($pdo, $entityType, $entityId)) {
                        ApiResponse::error($entityType === 'note' ? 'Note not found' : 'Page not found', 404);
                        exit;
                    }

                    if ($entityType === 'page') {
                        $stmt = $pdo->prepare("DELETE FROM Properties WHERE page_id = ? AND note_id IS NULL AND name = ?");
                    } else { // 'note'
                        $stmt = $pdo->prepare("DELETE FROM Properties WHERE note_id = ? AND page_id IS NULL AND name = ?");
                    }
                    $stmt->execute([$entityId, $name]);
                    
                    ApiResponse::success(null, 200);
                    
                } catch (Exception $e) {
                    ApiResponse::error('Server error: ' . $e->getMessage(), 500);
                }
                exit;
            } elseif ($input['action'] === 'set_internal_status') {
                $validationRules = [
                    'entity_type' => 'required|isValidEntityType',
                    'entity_id' => 'required|isPositiveInteger',
                    'name' => 'required|isNotEmpty',
                    'internal' => 'required|isBooleanLike'
                ];
                $errors = Validator::validate($input, $validationRules);
                if (!empty($errors)) {
                    ApiResponse::error('Invalid input for setting internal status.', 400, $errors);
                    exit;
                }

                $entityType = $input['entity_type']; // Validated
                $entityId = (int)$input['entity_id']; // Validated
                $name = $input['name']; // Validated
                $internalFlag = (int)$input['internal']; // Validated

                if ($internalFlag !== 0 && $internalFlag !== 1) {
                    ApiResponse::error('Invalid internal flag value. Must be 0 or 1.', 400);
                    exit;
                }

                try {
                    $pdo = get_db_connection();
                    
                    // Check if entity exists
                    if (!checkEntityExists($pdo, $entityType, $entityId)) {
                        ApiResponse::error($entityType === 'note' ? 'Note not found' : 'Page not found', 404);
                        exit;
                    }

                    $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';

                    // Check if property exists
                    $stmtCheck = $pdo->prepare("SELECT id FROM Properties WHERE {$idColumn} = ? AND name = ?");
                    $stmtCheck->execute([$entityId, $name]);
                    if (!$stmtCheck->fetch()) {
                        ApiResponse::error('Property not found. Cannot set internal status for a non-existent property.', 404);
                        exit;
                    }

                    // Update internal status
                    $stmt = $pdo->prepare("UPDATE Properties SET internal = ? WHERE {$idColumn} = ? AND name = ?");
                    $success = $stmt->execute([$internalFlag, $entityId, $name]);

                    if ($success) {
                        // Get property value for trigger dispatch
                        $stmtGetValue = $pdo->prepare("SELECT value FROM Properties WHERE {$idColumn} = ? AND name = ?");
                        $stmtGetValue->execute([$entityId, $name]);
                        $propertyRow = $stmtGetValue->fetch(PDO::FETCH_ASSOC);

                        if ($propertyRow) {
                            $triggerService = new PropertyTriggerService($pdo);
                            $triggerService->dispatch($entityType, $entityId, $name, $propertyRow['value']);
                        } else {
                            error_log("Could not retrieve property value after updating internal status for {$name} on {$entityType} {$entityId}");
                        }

                        ApiResponse::success(['message' => 'Property internal status updated.']);
                    } else {
                        ApiResponse::error('Failed to update property internal status', 500);
                    }
                } catch (Exception $e) {
                    ApiResponse::error('Server error: ' . $e->getMessage(), 500);
                }
                exit;
            }
        }
        
        // Handle regular property creation/update
        $validationRules = [
            'entity_type' => 'required|isValidEntityType',
            'entity_id' => 'required|isPositiveInteger',
            'name' => 'required|isNotEmpty',
            'value' => 'required',
            'internal' => 'optional|isBooleanLike'
        ];
        $errors = Validator::validate($input, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid input for creating/updating property.', 400, $errors);
            exit;
        }

        $entityType = $input['entity_type']; // Validated
        $entityId = (int)$input['entity_id']; // Validated
        $name = $input['name']; // Validated
        $value = $input['value']; // Validated (presence)
        $explicitInternal = isset($input['internal']) ? (int)$input['internal'] : null;

        // The specific validate_property_data function can still be used for its tag normalization logic
        $normalizedPropertyData = validate_property_data(['name' => $name, 'value' => $value]);
        if (!$normalizedPropertyData) {
            ApiResponse::error('Invalid property format (e.g., tag:: without tag name).', 400);
            exit;
        }
        $name = $normalizedPropertyData['name'];
        $value = $normalizedPropertyData['value'];

        try {
            $pdo = get_db_connection();
            
            // Check if entity exists
            if (!checkEntityExists($pdo, $entityType, $entityId)) {
                ApiResponse::error($entityType === 'note' ? 'Note not found' : 'Page not found', 404);
                exit;
            }

            $pdo->beginTransaction();
            
            $savedProperty = _updateOrAddPropertyAndDispatchTriggers(
                $pdo,
                $entityType,
                $entityId,
                $name,
                $value,
                $explicitInternal 
            );
            
            $pdo->commit();

            // If the entity is a note, append the property to its content
            if ($entityType === 'note') {
                try {
                    // Fetch the note's current content
                    $stmt = $pdo->prepare("SELECT content FROM Notes WHERE id = ?");
                    $stmt->execute([$entityId]);
                    $noteRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    $currentContent = $noteRow ? $noteRow['content'] : '';

                    // Construct the property string
                    // $savedProperty contains ['name', 'value', 'internal']
                    $propertyString = $savedProperty['name'] . 
                                      ($savedProperty['internal'] ? ':::' : '::') . 
                                      $savedProperty['value'];

                    // Append the property string to the content
                    // Ensure it's on a new line, and add a trailing newline
                    $newContent = rtrim($currentContent);
                    if (!empty($currentContent)) { // Add a newline only if there's existing content
                        $newContent .= "\n";
                    }
                    $newContent .= $propertyString . "\n";

                    // Save the updated note content
                    // This is done after the main property transaction is committed.
                    // If this fails, the property is in the DB but not in the content,
                    // which property_parser.php should eventually reconcile.
                    $updateNoteStmt = $pdo->prepare("UPDATE Notes SET content = ? WHERE id = ?");
                    $updateNoteStmt->execute([$newContent, $entityId]);

                } catch (Exception $noteUpdateException) {
                    // Log the error, but don't let it fail the main property API response,
                    // as the property itself was successfully saved.
                    error_log("Error appending property to note content for note ID {$entityId}: " . $noteUpdateException->getMessage());
                }
            }
            
            // After successful update/add, fetch all current values for this property name
            // to return in the specified format.
            // We need a DataManager instance here.
            // This assumes get_db_connection() can be called again or $pdo is still valid.
            // Ideally, DataManager instance would be available if this script was structured as a class.
            // For now, creating one locally for this operation.
            $dataManager = new DataManager($pdo); 
            $currentProperties = [];
            if ($entityType === 'note') {
                // DataManager's getNoteProperties fetches all properties for a note.
                // We need to filter for the specific property name.
                $allPropsForNote = $dataManager->getNoteProperties($entityId, true); // true to include internal if it was set
                if (isset($allPropsForNote[$savedProperty['name']])) {
                    $currentProperties = $allPropsForNote[$savedProperty['name']];
                }
            } elseif ($entityType === 'page') {
                $allPropsForPage = $dataManager->getPageProperties($entityId, true);
                if (isset($allPropsForPage[$savedProperty['name']])) {
                    $currentProperties = $allPropsForPage[$savedProperty['name']];
                }
            }
            
            // $currentProperties should now be an array of {"value": ..., "internal": ...} objects
            // as returned by the new _formatProperties method.

            ApiResponse::success([
                'message' => 'Property set successfully.', // Added success message
                'name' => $savedProperty['name'],
                'values' => $currentProperties // This now holds the array of value objects
            ]);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            ApiResponse::error('Server error: ' . $e->getMessage(), 500);
        }
        exit;
    }

    // Method not allowed (if not GET or POST)
    ApiResponse::error('Method not allowed', 405);
}