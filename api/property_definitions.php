<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_triggers.php';
require_once 'properties.php';
require_once 'response_utils.php'; // Include the new response utility

// header('Content-Type: application/json'); // Will be handled by ApiResponse

class PropertyDefinitionManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function applyPropertyDefinitionsToExisting($propertyName = null) {
        if (!function_exists('_updateOrAddPropertyAndDispatchTriggers')) {
            error_log('_updateOrAddPropertyAndDispatchTriggers function not found in applyPropertyDefinitionsToExisting. Ensure properties.php is included.');
            throw new Exception('Core property update function is missing.');
        }

        try {
            $this->pdo->beginTransaction();
            
            $sql = "SELECT name, internal FROM PropertyDefinitions WHERE auto_apply = 1";
            $params = [];
            if ($propertyName) {
                $sql .= " AND name = ?";
                $params[] = $propertyName;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $processedCount = 0;

            foreach ($definitions as $definition) {
                $defName = $definition['name'];
                $defInternal = (int)$definition['internal'];

                $propStmt = $this->pdo->prepare("
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
                        $this->pdo,
                        $entityType,
                        $entityId,
                        $defName,
                        $property['value'],
                        $defInternal
                    );
                    $processedCount++;
                }
            }
            
            $this->pdo->commit();
            return $processedCount;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error in applyPropertyDefinitionsToExisting: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw new Exception("Server error while applying property definitions: " . $e->getMessage());
        }
    }

    public function checkPropertyDefinition($propertyName) {
        $stmt = $this->pdo->prepare("SELECT internal FROM PropertyDefinitions WHERE name = ?");
        $stmt->execute([$propertyName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['internal'] : null;
    }
}

// Initialize and handle the request
$pdo = get_db_connection();
$propertyDefinitionManager = new PropertyDefinitionManager($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if (isset($_GET['apply_all'])) {
            $updatedCount = $propertyDefinitionManager->applyPropertyDefinitionsToExisting();
            ApiResponse::success(['message' => "Applied property definitions to {$updatedCount} existing properties"]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM PropertyDefinitions ORDER BY name");
            $stmt->execute();
            $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ApiResponse::success($definitions);
        }
    } catch (Exception $e) {
        ApiResponse::error('Server error: ' . $e->getMessage(), 500);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ApiResponse::error('Invalid JSON', 400);
        exit; // Ensure script termination
    }
    
    try {
        if (isset($input['action'])) {
            if ($input['action'] === 'apply_definition') {
                $propertyName = isset($input['name']) ? $input['name'] : null;
                if (!$propertyName) {
                    ApiResponse::error('Property name required', 400);
                    exit; // Ensure script termination
                }
                
                $updatedCount = $propertyDefinitionManager->applyPropertyDefinitionsToExisting($propertyName);
                ApiResponse::success(['message' => "Applied definition for '{$propertyName}' to {$updatedCount} existing properties"]);
                
            } elseif ($input['action'] === 'delete') {
                $id = isset($input['id']) ? (int)$input['id'] : null;
                if (!$id) {
                    ApiResponse::error('Definition ID required', 400);
                    exit; // Ensure script termination
                }
                
                $stmt = $pdo->prepare("DELETE FROM PropertyDefinitions WHERE id = ?");
                $stmt->execute([$id]);
                
                ApiResponse::success(['message' => 'Property definition deleted']);
            }
        } else {
            $name = isset($input['name']) ? trim($input['name']) : null;
            $internal = isset($input['internal']) ? (int)$input['internal'] : 0;
            $description = isset($input['description']) ? trim($input['description']) : null;
            $autoApply = isset($input['auto_apply']) ? (int)$input['auto_apply'] : 1;
            
            if (!$name) {
                ApiResponse::error('Property name is required', 400);
                exit; // Ensure script termination
            }
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO PropertyDefinitions (name, internal, description, auto_apply)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $internal, $description, $autoApply]);
            
            if ($autoApply) {
                $updatedCount = $propertyDefinitionManager->applyPropertyDefinitionsToExisting($name);
                $message = "Property definition saved and applied to {$updatedCount} existing properties";
            } else {
                $message = "Property definition saved (not applied to existing properties)";
            }
            
            $pdo->commit();
            ApiResponse::success(['message' => $message]);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ApiResponse::error('Server error: ' . $e->getMessage(), 500);
    }
    exit; // Ensure script termination after POST
} else {
    ApiResponse::error('Method not allowed', 405);
}
?>