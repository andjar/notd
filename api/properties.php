<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_triggers.php'; // Re-enabled

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
        
        error_log("[DEBUG API DELETE via POST] Parsed Params: entityType='{$entityType}', rawEntityId='{$rawEntityId}', filteredEntityId='".($entityId === null ? "NULL" : ($entityId === false ? "FALSE" : $entityId))."', name='{$name}'");

        if ($entityType === null || $entityId === null || $entityId === false || $name === null) {
            $errorDetails = "entityType: " . ($entityType === null ? "MISSING" : "OK") . 
                            ", entityId (filtered): " . ($entityId === null ? "MISSING_OR_INVALID_RAW" : ($entityId === false ? "INVALID_INT" : "OK")) .
                            ", name: " . ($name === null ? "MISSING" : "OK");
            error_log("[DEBUG API DELETE via POST] Condition for missing params triggered. Details: " . $errorDetails);
            send_json_response(['success' => false, 'error' => 'DELETE Error: Missing or invalid parameters. Details: ' . $errorDetails], 400);
        }
        
        try {
            $pdo = get_db_connection();
            
            if ($entityType === 'page') {
                $stmt = $pdo->prepare("DELETE FROM Properties WHERE page_id = ? AND note_id IS NULL AND name = ?");
            } else {
                $stmt = $pdo->prepare("DELETE FROM Properties WHERE note_id = ? AND page_id IS NULL AND name = ?");
            }
            $stmt->execute([$entityId, $name]);
            
            send_json_response(['success' => true]);
            
        } catch (Exception $e) {
            send_json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }
    
    // Handle regular property creation/update
    // Get entity_type and entity_id from the JSON input, not $_POST
    $entityType = isset($input['entity_type']) ? htmlspecialchars($input['entity_type'], ENT_QUOTES, 'UTF-8') : null;
    $entityId = isset($input['entity_id']) ? filter_var($input['entity_id'], FILTER_VALIDATE_INT) : null;
    
    if (!$entityType || !$entityId) {
        send_json_response(['error' => 'Missing required parameters: entity_type and entity_id'], 400);
    }
    
    // Extract the property name, value, and internal flag from the input
    $name = isset($input['name']) ? $input['name'] : null;
    $value = isset($input['value']) ? $input['value'] : null;
    $internal = isset($input['internal']) ? filter_var($input['internal'], FILTER_VALIDATE_INT, ['options' => ['default' => 0]]) : 0;
    
    if (!$name || $value === null) {
        send_json_response(['error' => 'Missing required parameters: name and value'], 400);
    }
    
    try {
        $pdo = get_db_connection();
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Validate and normalize property data
        $propertyData = validate_property_data(['name' => $name, 'value' => $value]);
        if (!$propertyData) {
            $pdo->rollBack();
            send_json_response(['error' => 'Invalid property data'], 400);
        }
        
        // Use the validated/normalized data
        $validatedName = $propertyData['name'];
        $validatedValue = $propertyData['value'];
        
        // For single values, use REPLACE to handle both insert and update
        if ($entityType === 'page') {
            $stmt = $pdo->prepare("
                REPLACE INTO Properties (page_id, note_id, name, value, internal)
                VALUES (?, NULL, ?, ?, ?)
            ");
        } else {
            $stmt = $pdo->prepare("
                REPLACE INTO Properties (note_id, page_id, name, value, internal)
                VALUES (?, NULL, ?, ?, ?)
            ");
        }
        $stmt->execute([$entityId, $validatedName, $validatedValue, $internal]);
        
        // Dispatch triggers
        dispatchPropertyTriggers($pdo, $entityType, $entityId, $validatedName, $validatedValue);
        
        $pdo->commit();
        send_json_response(['success' => true, 'property' => ['name' => $validatedName, 'value' => $validatedValue, 'internal' => $internal]]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

// Method not allowed
send_json_response(['error' => 'Method not allowed'], 405);