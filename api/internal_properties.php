<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_trigger_service.php';
require_once 'response_utils.php';

class InternalPropertyManager {
    private $pdo;
    private $triggerService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->triggerService = new PropertyTriggerService($pdo);
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];

        switch ($method) {
            case 'GET':
                $this->handleGetRequest();
                break;
            case 'POST':
                $this->handlePostRequest();
                break;
            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    private function handleGetRequest() {
        $entityType = isset($_GET['entity_type']) ? htmlspecialchars($_GET['entity_type'], ENT_QUOTES, 'UTF-8') : null;
        $entityId = filter_input(INPUT_GET, 'entity_id', FILTER_VALIDATE_INT);
        $propertyName = isset($_GET['name']) ? htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8') : null;

        if (!$entityType || !$entityId || !$propertyName) {
            ApiResponse::error('Missing required GET parameters: entity_type, entity_id, name', 400);
            return;
        }

        try {
            $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';

            $stmt = $this->pdo->prepare("SELECT internal FROM Properties WHERE {$idColumn} = :entityId AND name = :name");
            $stmt->bindParam(':entityId', $entityId, PDO::PARAM_INT);
            $stmt->bindParam(':name', $propertyName, PDO::PARAM_STR);
            $stmt->execute();

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                ApiResponse::success(['name' => $propertyName, 'internal' => (int)$result['internal']]);
            } else {
                ApiResponse::error('Property not found', 404);
            }
        } catch (Exception $e) {
            ApiResponse::error('Server error: ' . $e->getMessage(), 500);
        }
    }

    private function handlePostRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            ApiResponse::error('Invalid JSON', 400);
            return;
        }

        $entityType = isset($input['entity_type']) ? htmlspecialchars($input['entity_type'], ENT_QUOTES, 'UTF-8') : null;
        $entityId = isset($input['entity_id']) ? filter_var($input['entity_id'], FILTER_VALIDATE_INT) : null;
        $propertyName = isset($input['name']) ? $input['name'] : null;
        $internalFlag = isset($input['internal']) ? filter_var($input['internal'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;

        if (!$entityType || !$entityId || !$propertyName || $internalFlag === null) {
            ApiResponse::error('Missing required POST parameters: entity_type, entity_id, name, internal', 400);
            return;
        }

        if ($internalFlag !== 0 && $internalFlag !== 1) {
            ApiResponse::error('Invalid internal flag value. Must be 0 or 1.', 400);
            return;
        }

        try {
            $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';

            $stmtCheck = $this->pdo->prepare("SELECT id FROM Properties WHERE {$idColumn} = :entityId AND name = :name");
            $stmtCheck->bindParam(':entityId', $entityId, PDO::PARAM_INT);
            $stmtCheck->bindParam(':name', $propertyName, PDO::PARAM_STR);
            $stmtCheck->execute();
            $existingProperty = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$existingProperty) {
                ApiResponse::error('Property not found. Cannot set internal status for a non-existent property.', 404);
                return;
            }

            $stmt = $this->pdo->prepare("UPDATE Properties SET internal = :internal WHERE {$idColumn} = :entityId AND name = :name");
            $stmt->bindParam(':internal', $internalFlag, PDO::PARAM_INT);
            $stmt->bindParam(':entityId', $entityId, PDO::PARAM_INT);
            $stmt->bindParam(':name', $propertyName, PDO::PARAM_STR);
            
            $success = $stmt->execute();

            if ($success) {
                $stmtGetValue = $this->pdo->prepare("SELECT value FROM Properties WHERE {$idColumn} = :entityId AND name = :name");
                $stmtGetValue->bindParam(':entityId', $entityId, PDO::PARAM_INT);
                $stmtGetValue->bindParam(':name', $propertyName, PDO::PARAM_STR);
                $stmtGetValue->execute();
                $propertyRow = $stmtGetValue->fetch(PDO::FETCH_ASSOC);

                if ($propertyRow) {
                    $this->triggerService->dispatch($entityType, $entityId, $propertyName, $propertyRow['value']);
                } else {
                    error_log("Could not retrieve property value after updating internal status for {$propertyName} on {$entityType} {$entityId}");
                }

                ApiResponse::success(['message' => 'Property internal status updated.']);
            } else {
                ApiResponse::error('Failed to update property internal status', 500);
            }
        } catch (Exception $e) {
            ApiResponse::error('Server error: ' . $e->getMessage(), 500);
        }
    }
}

// Initialize and handle the request
$pdo = get_db_connection();
$internalPropertyManager = new InternalPropertyManager($pdo);
$internalPropertyManager->handleRequest();

?>