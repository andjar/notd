<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_triggers.php';
require_once 'properties.php';

header('Content-Type: application/json');

function send_json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function applyPropertyDefinitionsToExisting($pdo, $propertyName = null) {
    if (!function_exists('_updateOrAddPropertyAndDispatchTriggers')) {
        error_log('_updateOrAddPropertyAndDispatchTriggers function not found in applyPropertyDefinitionsToExisting. Ensure properties.php is included.');
        throw new Exception('Core property update function is missing.');
    }

    try {
        $pdo->beginTransaction();
        
        $sql = "SELECT name, internal FROM PropertyDefinitions WHERE auto_apply = 1";
        $params = [];
        if ($propertyName) {
            $sql .= " AND name = ?";
            $params[] = $propertyName;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $processedCount = 0;

        foreach ($definitions as $definition) {
            $defName = $definition['name'];
            $defInternal = (int)$definition['internal'];

            $propStmt = $pdo->prepare("
                SELECT id, value, note_id, page_id, internal 
                FROM Properties 
                WHERE name = ? AND internal != ?
            ");
            $propStmt->execute([$defName, $defInternal]);
            $propertiesToUpdate = $propStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($propertiesToUpdate as $property) {
                $entityType = $property['note_id'] ? 'note' : 'page';
                $entityId = $property['note_id'] ?: $property['page_id'];
                
                _updateOrAddPropertyAndDispatchTriggers(
                    $pdo,
                    $entityType,
                    $entityId,
                    $defName,
                    $property['value'],
                    $defInternal
                );
                $processedCount++;
            }
        }
        
        $pdo->commit();
        return $processedCount;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in applyPropertyDefinitionsToExisting: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        throw new Exception("Server error while applying property definitions: " . $e->getMessage());
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
            $updatedCount = applyPropertyDefinitionsToExisting($pdo);
            send_json_response([
                'success' => true, 
                'message' => "Applied property definitions to {$updatedCount} existing properties"
            ]);
        } else {
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
                $id = isset($input['id']) ? (int)$input['id'] : null;
                if (!$id) {
                    send_json_response(['error' => 'Definition ID required'], 400);
                }
                
                $stmt = $pdo->prepare("DELETE FROM PropertyDefinitions WHERE id = ?");
                $stmt->execute([$id]);
                
                send_json_response(['success' => true, 'message' => 'Property definition deleted']);
            }
        } else {
            $name = isset($input['name']) ? trim($input['name']) : null;
            $internal = isset($input['internal']) ? (int)$input['internal'] : 0;
            $description = isset($input['description']) ? trim($input['description']) : null;
            $autoApply = isset($input['auto_apply']) ? (int)$input['auto_apply'] : 1;
            
            if (!$name) {
                send_json_response(['error' => 'Property name is required'], 400);
            }
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO PropertyDefinitions (name, internal, description, auto_apply)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $internal, $description, $autoApply]);
            
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