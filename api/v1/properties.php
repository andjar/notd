<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
// require_once __DIR__ . '/../property_triggers.php'; // Old trigger system replaced
require_once __DIR__ . '/../property_trigger_service.php'; // New trigger service
// require_once __DIR__ . '/../property_auto_internal.php'; // Removed
require_once __DIR__ . '/../property_parser.php'; // Ensure PropertyParser is included
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
    
    // Determine colon_count based on explicitInternal. Default to 2.
    // For pages (or if this function is ever called for notes directly),
    // map explicitInternal: true (1) to 3 colons, false (0) or null to 2 colons.
    $colonCountToSave = (isset($explicitInternal) && $explicitInternal == 1) ? 3 : 2;
    
    if ($entityType === 'page') {
        $stmt = $pdo->prepare("
            REPLACE INTO Properties (page_id, note_id, name, value, colon_count, active)
            VALUES (?, NULL, ?, ?, ?, 1)
        ");
        $stmt->execute([$entityId, $validatedName, $validatedValue, $colonCountToSave]);
    } else { // 'note' - This path is less likely to be used by main logic now, but updated for consistency.
        // Try to update existing property first
        $updateStmt = $pdo->prepare("
            UPDATE Properties 
            SET value = ?, colon_count = ?, active = 1, updated_at = CURRENT_TIMESTAMP 
            WHERE note_id = ? AND name = ?
        ");
        $updateStmt->execute([$validatedValue, $colonCountToSave, $entityId, $validatedName]);
        
        // If no rows were affected, insert a new one
        if ($updateStmt->rowCount() === 0) {
            $insertStmt = $pdo->prepare("
                INSERT INTO Properties (note_id, page_id, name, value, colon_count, active)
                VALUES (?, NULL, ?, ?, ?, 1)
            ");
            $insertStmt->execute([$entityId, $validatedName, $validatedValue, $colonCountToSave]);
        }
    }
    
    // Dispatch triggers using the service
    $triggerService = new PropertyTriggerService($pdo);
    $triggerService->dispatch($entityType, $entityId, $validatedName, $validatedValue);
    
    return ['name' => $validatedName, 'value' => $validatedValue, 'colon_count' => $colonCountToSave];
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

            // Standardize property format
            $standardizedProperties = [];
            foreach ($properties as $name => $values) {
                if (!is_array($values)) {
                    $values = [$values];
                }
                $standardizedProperties[$name] = array_map(function($value) {
                    return is_array($value) ? $value : ['value' => $value, 'internal' => 0];
                }, $values);
            }
            
            ApiResponse::success($standardizedProperties);
            
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
            } 
            // Removed 'set_internal_status' action block as the 'internal' column is gone.
            // Any logic to change a property's type (colon_count) would need a new specific action
            // or be handled by deleting and re-adding the property with a different colon_count via note content.
        }
        
        // Handle regular property creation/update
        $validationRules = [
            'entity_type' => 'required|isValidEntityType',
            'entity_id' => 'required|isPositiveInteger',
            'name' => 'required|isNotEmpty',
            'value' => 'required',
            // 'internal' is deprecated in favor of 'colon_count' for notes
            'colon_count' => 'optional|isInteger|min:2', 
            'internal' => 'optional|isBooleanLike' // Kept for page compatibility for now
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
        
        $colon_count = 2; // Default for notes
        if ($entityType === 'note') {
            if (isset($input['colon_count']) && is_numeric($input['colon_count']) && (int)$input['colon_count'] >= 2) {
                $colon_count = (int)$input['colon_count'];
            }
        }
        // $explicitInternal is still used for 'page' entities if they go through _updateOrAddPropertyAndDispatchTriggers
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

            $pdo = get_db_connection(); // Ensure $pdo is initialized

            if ($entityType === 'note') {
                // Refactored logic for 'note'
                try {
                    $pdo->beginTransaction();

                    // 1. Fetch Note Content
                    $stmtNote = $pdo->prepare("SELECT content FROM Notes WHERE id = ?");
                    $stmtNote->execute([$entityId]);
                    $note = $stmtNote->fetch(PDO::FETCH_ASSOC);

                    if (!$note) {
                        $pdo->rollBack();
                        ApiResponse::error('Note not found', 404);
                        exit;
                    }
                    $currentContent = $note['content'] ?: '';

                    // 2. Determine Property String and Internal Status
                    // 2. Determine Property String using colon_count for notes
                    $separator = str_repeat(':', $colon_count);
                    $propertyLine = "{" . $name . $separator . $value . "}";

                    // 3. Modify Content
                    $escapedName = preg_quote($name, '/');
                    // Regex to find an existing property string (key only, any value, any number of colons >= 2)
                    // Ensure it matches the whole line for the property to be replaced.
                    $pattern = "/^\{" . $escapedName . "(:{2,}).*?\}$/m";
                    
                    $newContent = $currentContent;
                    $foundAndReplaced = false;

                    // Check if property with the same name exists
                    if (preg_match($pattern, $currentContent)) {
                        $newContent = preg_replace($pattern, $propertyLine, $currentContent, 1);
                        $foundAndReplaced = true;
                    } else {
                        // Append if not found
                        if (!empty($newContent) && !preg_match("/\n$/", $newContent)) {
                            $newContent .= "\n";
                        }
                        $newContent .= $propertyLine;
                    }
                    
                    // 4. Save Updated Note Content (only if changed)
                    if ($newContent !== $currentContent) {
                        $updateNoteStmt = $pdo->prepare("UPDATE Notes SET content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        if (!$updateNoteStmt->execute([$newContent, $entityId])) {
                            throw new Exception("Failed to update note content.");
                        }
                    }
                    
                    $pdo->commit(); // Commit changes to Notes table before sync

                    // 5. Call Synchronizer
                    $propertyParser = new PropertyParser($pdo);
                    // Pass the *new* content to the synchronizer
                    $syncSuccess = $propertyParser->syncNotePropertiesFromContent($entityId, $newContent); 
                    
                    if (!$syncSuccess) {
                        // syncNotePropertiesFromContent handles its own transaction and error logging.
                        // If it returns false, it means an error occurred during sync.
                        // The note content IS updated, but properties might be out of sync.
                        // This state might require manual intervention or specific error handling.
                        // For now, report a generic error but acknowledge content was likely updated.
                        ApiResponse::error('Note content updated, but property synchronization failed.', 500, ['details' => 'Check server logs for PropertyParser errors.']);
                        exit;
                    }

                    // 6. Response
                    ApiResponse::success([
                        'message' => 'Property operation processed for note. Content updated and properties synced.',
                        'property' => [
                            'name' => $name,
                            'value' => $value,
                            'colon_count' => (int)$colon_count
                        ]
                    ]);

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    ApiResponse::error('Server error for note property: ' . $e->getMessage(), 500);
                }
            } else { // 'page' or other entity types - keep existing logic for now
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
                
                $response = [
                    'property' => [
                        'name' => $savedProperty['name'],
                        'value' => $savedProperty['value'],
                        'colon_count' => (int)$savedProperty['colon_count']
                    ]
                ];
                ApiResponse::success($response);
            }
            
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