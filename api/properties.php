<?php
require_once '../config.php';
require_once 'db_connect.php';

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
    
    if (!$entityType || !$entityId) {
        send_json_response(['error' => 'Missing required parameters'], 400);
    }
    
    try {
        $pdo = get_db_connection();
        
        // Get all properties for the entity
        $stmt = $pdo->prepare("
            SELECT name, value 
            FROM Properties 
            WHERE " . ($entityType === 'page' ? 'page_id' : 'note_id') . " = ?
            ORDER BY name, id
        ");
        $stmt->execute([$entityId]);
        
        // Group properties by name to handle lists
        $properties = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['name'];
            if (!isset($properties[$name])) {
                $properties[$name] = [];
            }
            $properties[$name][] = $row['value'];
        }
        
        // Convert single values to strings
        foreach ($properties as $name => $values) {
            if (count($values) === 1) {
                $properties[$name] = $values[0];
            }
        }
        
        send_json_response($properties);
        
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
    
    // Get entity_type and entity_id from the JSON input, not $_POST
    $entityType = isset($input['entity_type']) ? htmlspecialchars($input['entity_type'], ENT_QUOTES, 'UTF-8') : null;
    $entityId = isset($input['entity_id']) ? filter_var($input['entity_id'], FILTER_VALIDATE_INT) : null;
    
    if (!$entityType || !$entityId) {
        send_json_response(['error' => 'Missing required parameters: entity_type and entity_id'], 400);
    }
    
    // Extract the property name and value from the input
    $name = isset($input['name']) ? $input['name'] : null;
    $value = isset($input['value']) ? $input['value'] : null;
    
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
        
        // For single values, use REPLACE to handle both insert and update
        if ($entityType === 'page') {
            $stmt = $pdo->prepare("
                REPLACE INTO Properties (page_id, note_id, name, value)
                VALUES (?, NULL, ?, ?)
            ");
        } else {
            $stmt = $pdo->prepare("
                REPLACE INTO Properties (note_id, page_id, name, value)
                VALUES (?, NULL, ?, ?)
            ");
        }
        $stmt->execute([$entityId, $name, $value]);
        
        $pdo->commit();
        send_json_response(['success' => true, 'property' => ['name' => $name, 'value' => $value]]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

// DELETE /api/properties.php?entity_type=note&entity_id=123&name=property_name
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $entityType = isset($_GET['entity_type']) ? htmlspecialchars($_GET['entity_type'], ENT_QUOTES, 'UTF-8') : null;
    $entityId = filter_input(INPUT_GET, 'entity_id', FILTER_VALIDATE_INT);
    $name = isset($_GET['name']) ? htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8') : null;
    
    if (!$entityType || !$entityId || !$name) {
        send_json_response(['error' => 'Missing required parameters'], 400);
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

// Method not allowed
send_json_response(['error' => 'Method not allowed'], 405);