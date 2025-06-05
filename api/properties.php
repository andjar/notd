<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_triggers.php';
require_once 'property_auto_internal.php';

header('Content-Type: application/json');

function send_json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

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
    
    // Dispatch triggers
    dispatchPropertyTriggers($pdo, $entityType, $entityId, $validatedName, $validatedValue);
    
    return ['name' => $validatedName, 'value' => $validatedValue, 'internal' => $finalInternal];
}

// GET /api/properties.php?entity_type=note&entity_id=123
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $entityType = isset($_GET['entity_type']) ? htmlspecialchars($_GET['entity_type'], ENT_QUOTES, 'UTF-8') : null;
    $entityId = filter_input(INPUT_GET, 'entity_id', FILTER_VALIDATE_INT);
    $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
    
    if (!$entityType || !$entityId) {
        send_json_response(['error' => 'Missing required parameters'], 400);
    }
    
    try {
        $pdo = get_db_connection();
        
        // Base query
        $sql = "
            SELECT name, value, internal 
            FROM Properties 
            WHERE " . ($entityType === 'page' ? 'page_id' : 'note_id') . " = :entityId";

        // Filter internal properties by default
        if (!$includeInternal) {
            $sql .= " AND internal = 0";
        }
        
        $sql .= " ORDER BY name, id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':entityId', $entityId, PDO::PARAM_INT);
        $stmt->execute();
        
        // Group properties by name to handle lists
        $properties = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['name'];
            if (!isset($properties[$name])) {
                $properties[$name] = [];
            }
            // Store as an object to include internal flag if needed, or simplify if only one value
            $properties[$name][] = ['value' => $row['value'], 'internal' => (int)$row['internal']];
        }
        
        // Convert single values to strings or keep as object if internal flag is relevant
        foreach ($properties as $name => $values) {
            if (count($values) === 1) {
                // If only one property and not specifically including internal, simplify output
                if (!$includeInternal && $values[0]['internal'] == 0) {
                     $properties[$name] = $values[0]['value'];
                } else {
                    // Otherwise, keep it as an object/array to show the internal flag
                    $properties[$name] = $values[0];
                }
            } else {
                 // For multiple values (lists), keep them as an array of objects
                 $properties[$name] = $values;
            }
        }
        
        send_json_response(['success' => true, 'data' => $properties]);
        
    } catch (Exception $e) {
        send_json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

// POST /api/properties.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        send_json_response(['error' => 'Invalid JSON'], 400);
    }
    
    // Check if this is a delete action
    if (isset($input['action']) && $input['action'] === 'delete') {
        // Handle property deletion via POST
        $entityType = isset($input['entity_type']) ? htmlspecialchars($input['entity_type'], ENT_QUOTES, 'UTF-8') : null;
        $rawEntityId = isset($input['entity_id']) ? $input['entity_id'] : null;
        $entityId = filter_var($rawEntityId, FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
        $name = isset($input['name']) ? htmlspecialchars($input['name'], ENT_QUOTES, 'UTF-8') : null;
        
        if ($entityType === null || $entityId === null || $entityId === false || $name === null) {
            send_json_response(['success' => false, 'error' => 'DELETE Error: Missing or invalid parameters'], 400);
        }
        
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
            
            send_json_response(['success' => true]);
            
        } catch (Exception $e) {
            send_json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
        // Important: exit after handling delete action
        exit; 
    }
    
    // Handle regular property creation/update
    $entityType = isset($input['entity_type']) ? htmlspecialchars($input['entity_type'], ENT_QUOTES, 'UTF-8') : null;
    $entityId = isset($input['entity_id']) ? filter_var($input['entity_id'], FILTER_VALIDATE_INT) : null;
    
    if (!$entityType || !$entityId) {
        send_json_response(['error' => 'Missing required parameters: entity_type and entity_id'], 400);
    }
    
    $name = isset($input['name']) ? $input['name'] : null;
    $value = isset($input['value']) ? $input['value'] : null;
    // The $explicitInternal for a direct POST is the 'internal' field from the input, if provided.
    $explicitInternal = isset($input['internal']) ? filter_var($input['internal'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;
    // If 'internal' key is not present in $input, $explicitInternal will be null, and determinePropertyInternalStatus will use definitions or default.
    // If 'internal' key IS present, its value (0 or 1) will be used directly.

    if ($name === null || $value === null) { // Changed from (!$name || $value === null) to allow empty string names if desired, though current validation might still restrict.
        send_json_response(['error' => 'Missing required parameters: name and value'], 400);
    }
    
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
        send_json_response(['success' => true, 'property' => $savedProperty]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

// Method not allowed
send_json_response(['error' => 'Method not allowed'], 405);