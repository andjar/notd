<?php
require_once '../config.php';
require_once 'db_connect.php';
// require_once 'property_triggers.php'; // Old trigger system replaced
require_once 'property_trigger_service.php'; // New trigger service
require_once 'property_auto_internal.php';
require_once 'response_utils.php'; // Include the new response utility
require_once 'data_manager.php';   // Include the new DataManager
require_once 'validator_utils.php'; // Include the new Validator

// header('Content-Type: application/json'); // Will be handled by ApiResponse

// This specific validation can be replaced by Validator class or kept if it has special logic (like tag normalization)
// For now, let's assume Validator can handle 'name' and 'value' presence, but tag normalization is specific.
// We might call Validator first, then this if basic checks pass.
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
function _updateOrAddPropertyAndDispatchTriggers($pdo, $entityType, $entityId, $name, $value, $explicitInternal = null) {
    // Validate and normalize property data
    $propertyData = validate_property_data(['name' => $name, 'value' => $value]);
    if (!$propertyData) {
        throw new Exception('Invalid property data');
    }
    
    $validatedName = $propertyData['name'];
    $validatedValue = $propertyData['value'];
    
    // Check property definitions to determine internal status
    // $explicitInternal will typically come from property definition applications
    $finalInternal = determinePropertyInternalStatus($pdo, $validatedName, $explicitInternal);
    
    // For single values, use REPLACE to handle both insert and update
    if ($entityType === 'page') {
        $stmt = $pdo->prepare("
            REPLACE INTO Properties (page_id, note_id, name, value, internal)
            VALUES (?, NULL, ?, ?, ?)
        ");
    } else { // 'note'
        $stmt = $pdo->prepare("
            REPLACE INTO Properties (note_id, page_id, name, value, internal)
            VALUES (?, NULL, ?, ?, ?)
        ");
    }
    $stmt->execute([$entityId, $validatedName, $validatedValue, $finalInternal]);
    
    // Dispatch triggers using the service
    $triggerService = new PropertyTriggerService($pdo);
    $triggerService->dispatch($entityType, $entityId, $validatedName, $validatedValue);
    
    return ['name' => $validatedName, 'value' => $validatedValue, 'internal' => $finalInternal];
}

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
        $pdo = get_db_connection(); // DataManager needs PDO
        $dataManager = new DataManager($pdo);
        $properties = null;

        if ($entityType === 'note') {
            $properties = $dataManager->getNoteProperties($entityId, $includeInternal);
        } elseif ($entityType === 'page') {
            $properties = $dataManager->getPageProperties($entityId, $includeInternal);
        }
        // $properties will be an array, empty if no properties found or entity_id is invalid.
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
        exit; // Ensure script termination
    }
    
    // Check if this is a delete action
    if (isset($input['action']) && $input['action'] === 'delete') {
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
            // Transaction for delete? Usually not necessary for single deletes unless triggers do complex things.
            // For now, assuming delete itself is atomic and triggers handle their own logic.
            if ($entityType === 'page') {
                $stmt = $pdo->prepare("DELETE FROM Properties WHERE page_id = ? AND note_id IS NULL AND name = ?");
            } else { // 'note'
                $stmt = $pdo->prepare("DELETE FROM Properties WHERE note_id = ? AND page_id IS NULL AND name = ?");
            }
            $stmt->execute([$entityId, $name]);
            
            // FUTURE: Consider if delete operations should also have a "before_delete" or "after_delete" trigger mechanism.
            // For now, keeping it simple.
            
            ApiResponse::success(null, 200); // Or perhaps a more descriptive success message if needed.
            
        } catch (Exception $e) {
            ApiResponse::error('Server error: ' . $e->getMessage(), 500);
        }
        // Important: exit after handling delete action
        exit; 
    }
    
    // Handle regular property creation/update
    $validationRules = [
        'entity_type' => 'required|isValidEntityType',
        'entity_id' => 'required|isPositiveInteger',
        'name' => 'required|isNotEmpty', // Name is required
        'value' => 'required',          // Value is required (can be empty string, so not isNotEmpty)
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
    // 'internal' is optional, Validator ensures it's 0/1 if present
    $explicitInternal = isset($input['internal']) ? (int)$input['internal'] : null;

    // The specific validate_property_data function can still be used for its tag normalization logic
    // after basic validation passes.
    $normalizedPropertyData = validate_property_data(['name' => $name, 'value' => $value]);
    if (!$normalizedPropertyData) {
        // This indicates an issue with tag format specifically, as basic presence was validated.
        ApiResponse::error('Invalid property format (e.g., tag:: without tag name).', 400);
        exit;
    }
    $name = $normalizedPropertyData['name']; // Potentially normalized name (though current logic doesn't change it)
    $value = $normalizedPropertyData['value']; // Potentially normalized value (for tags)

    try {
        $pdo = get_db_connection();
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
        ApiResponse::success(['property' => $savedProperty]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ApiResponse::error('Server error: ' . $e->getMessage(), 500);
    }
    exit; // Ensure script termination after POST
}

// Method not allowed (if not GET or POST)
ApiResponse::error('Method not allowed', 405);