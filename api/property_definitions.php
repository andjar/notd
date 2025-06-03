<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_triggers.php';

header('Content-Type: application/json');

function send_json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function applyPropertyDefinitionsToExisting($pdo, $propertyName = null) {
    try {
        $pdo->beginTransaction();
        
        // Get property definitions to apply
        if ($propertyName) {
            $stmt = $pdo->prepare("SELECT name, internal FROM PropertyDefinitions WHERE name = ? AND auto_apply = 1");
            $stmt->execute([$propertyName]);
        } else {
            $stmt = $pdo->prepare("SELECT name, internal FROM PropertyDefinitions WHERE auto_apply = 1");
            $stmt->execute();
        }
        
        $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $updatedCount = 0;
        
        foreach ($definitions as $definition) {
            // Update existing properties based on definition
            $updateStmt = $pdo->prepare("
                UPDATE Properties 
                SET internal = ? 
                WHERE name = ? AND internal != ?
            ");
            $updateStmt->execute([
                $definition['internal'], 
                $definition['name'], 
                $definition['internal']
            ]);
            
            $updatedCount += $updateStmt->rowCount();
            
            // Trigger property handlers for affected properties
            if ($updateStmt->rowCount() > 0) {
                // Get affected properties to trigger handlers
                $affectedStmt = $pdo->prepare("
                    SELECT id, name, value, note_id, page_id 
                    FROM Properties 
                    WHERE name = ? AND internal = ?
                ");
                $affectedStmt->execute([$definition['name'], $definition['internal']]);
                $affectedProperties = $affectedStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($affectedProperties as $property) {
                    $entityType = $property['note_id'] ? 'note' : 'page';
                    $entityId = $property['note_id'] ?: $property['page_id'];
                    dispatchPropertyTriggers($pdo, $entityType, $entityId, $property['name'], $property['value']);
                }
            }
        }
        
        $pdo->commit();
        return $updatedCount;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function checkPropertyDefinition($pdo, $propertyName) {
    $stmt = $pdo->prepare("SELECT internal FROM PropertyDefinitions WHERE name = ?");
    $stmt->execute([$propertyName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (int)$result['internal'] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo = get_db_connection();
        
        if (isset($_GET['apply_all'])) {
            // Apply all property definitions to existing properties
            $updatedCount = applyPropertyDefinitionsToExisting($pdo);
            send_json_response([
                'success' => true, 
                'message' => "Applied property definitions to {$updatedCount} existing properties"
            ]);
        } else {
            // Get all property definitions
            $stmt = $pdo->prepare("SELECT * FROM PropertyDefinitions ORDER BY name");
            $stmt->execute();
            $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            send_json_response(['success' => true, 'data' => $definitions]);
        }
    } catch (Exception $e) {
        send_json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        send_json_response(['error' => 'Invalid JSON'], 400);
    }
    
    try {
        $pdo = get_db_connection();
        
        if (isset($input['action'])) {
            if ($input['action'] === 'apply_definition') {
                // Apply a specific property definition
                $propertyName = isset($input['name']) ? $input['name'] : null;
                if (!$propertyName) {
                    send_json_response(['error' => 'Property name required'], 400);
                }
                
                $updatedCount = applyPropertyDefinitionsToExisting($pdo, $propertyName);
                send_json_response([
                    'success' => true, 
                    'message' => "Applied definition for '{$propertyName}' to {$updatedCount} existing properties"
                ]);
                
            } elseif ($input['action'] === 'delete') {
                // Delete a property definition
                $id = isset($input['id']) ? (int)$input['id'] : null;
                if (!$id) {
                    send_json_response(['error' => 'Definition ID required'], 400);
                }
                
                $stmt = $pdo->prepare("DELETE FROM PropertyDefinitions WHERE id = ?");
                $stmt->execute([$id]);
                
                send_json_response(['success' => true, 'message' => 'Property definition deleted']);
            }
        } else {
            // Create or update property definition
            $name = isset($input['name']) ? trim($input['name']) : null;
            $internal = isset($input['internal']) ? (int)$input['internal'] : 0;
            $description = isset($input['description']) ? trim($input['description']) : null;
            $autoApply = isset($input['auto_apply']) ? (int)$input['auto_apply'] : 1;
            
            if (!$name) {
                send_json_response(['error' => 'Property name is required'], 400);
            }
            
            $pdo->beginTransaction();
            
            // Insert or update property definition
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO PropertyDefinitions (name, internal, description, auto_apply)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $internal, $description, $autoApply]);
            
            // Apply to existing properties if auto_apply is enabled
            if ($autoApply) {
                $updatedCount = applyPropertyDefinitionsToExisting($pdo, $name);
                $message = "Property definition saved and applied to {$updatedCount} existing properties";
            } else {
                $message = "Property definition saved (not applied to existing properties)";
            }
            
            $pdo->commit();
            send_json_response(['success' => true, 'message' => $message]);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send_json_response(['error' => 'Server error: ' . $e->getMessage()], 500);
    }

} else {
    send_json_response(['error' => 'Method not allowed'], 405);
}
?> 