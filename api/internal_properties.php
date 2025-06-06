<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_triggers.php'; // Include the trigger system
require_once 'response_utils.php'; // Include the new response utility

// header('Content-Type: application/json'); // Will be handled by ApiResponse

$entityType = null;
$entityId = null;
$propertyName = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $entityType = isset($_GET['entity_type']) ? htmlspecialchars($_GET['entity_type'], ENT_QUOTES, 'UTF-8') : null;
    $entityId = filter_input(INPUT_GET, 'entity_id', FILTER_VALIDATE_INT);
    $propertyName = isset($_GET['name']) ? htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8') : null;

    if (!$entityType || !$entityId || !$propertyName) {
        ApiResponse::error('Missing required GET parameters: entity_type, entity_id, name', 400);
        exit; // Ensure script termination
    }

    try {
        $pdo = get_db_connection();
        $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';

        $stmt = $pdo->prepare("SELECT internal FROM Properties WHERE {$idColumn} = :entityId AND name = :name");
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

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ApiResponse::error('Invalid JSON', 400);
        exit; // Ensure script termination
    }

    $entityType = isset($input['entity_type']) ? htmlspecialchars($input['entity_type'], ENT_QUOTES, 'UTF-8') : null;
    $entityId = isset($input['entity_id']) ? filter_var($input['entity_id'], FILTER_VALIDATE_INT) : null;
    $propertyName = isset($input['name']) ? $input['name'] : null;
    $internalFlag = isset($input['internal']) ? filter_var($input['internal'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]) : null;


    if (!$entityType || !$entityId || !$propertyName || $internalFlag === null) {
        ApiResponse::error('Missing required POST parameters: entity_type, entity_id, name, internal', 400);
        exit; // Ensure script termination
    }

    if ($internalFlag !== 0 && $internalFlag !== 1) {
        ApiResponse::error('Invalid internal flag value. Must be 0 or 1.', 400);
        exit; // Ensure script termination
    }

    try {
        $pdo = get_db_connection();
        $idColumn = ($entityType === 'page') ? 'page_id' : 'note_id';

        // Check if the property exists
        $stmtCheck = $pdo->prepare("SELECT id FROM Properties WHERE {$idColumn} = :entityId AND name = :name");
        $stmtCheck->bindParam(':entityId', $entityId, PDO::PARAM_INT);
        $stmtCheck->bindParam(':name', $propertyName, PDO::PARAM_STR);
        $stmtCheck->execute();
        $existingProperty = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$existingProperty) {
            ApiResponse::error('Property not found. Cannot set internal status for a non-existent property.', 404);
            exit; // Ensure script termination
        }

        $stmt = $pdo->prepare("UPDATE Properties SET internal = :internal WHERE {$idColumn} = :entityId AND name = :name");
        $stmt->bindParam(':internal', $internalFlag, PDO::PARAM_INT);
        $stmt->bindParam(':entityId', $entityId, PDO::PARAM_INT);
        $stmt->bindParam(':name', $propertyName, PDO::PARAM_STR);
        
        $success = $stmt->execute();

        if ($success) {
            // When internal flag of a property is changed, we need to know its value for the trigger.
            // The $internalFlag here is what we are setting Properties.internal to.
            // If propertyName is 'internal', its *value* is what determines Notes.internal.
            // Let's fetch the property value to be sure.
            $stmtGetValue = $pdo->prepare("SELECT value FROM Properties WHERE {$idColumn} = :entityId AND name = :name");
            $stmtGetValue->bindParam(':entityId', $entityId, PDO::PARAM_INT);
            $stmtGetValue->bindParam(':name', $propertyName, PDO::PARAM_STR);
            $stmtGetValue->execute();
            $propertyRow = $stmtGetValue->fetch(PDO::FETCH_ASSOC);

            if ($propertyRow) {
                dispatchPropertyTriggers($pdo, $entityType, $entityId, $propertyName, $propertyRow['value']);
            } else {
                // This case should ideally not happen if we checked for property existence before update
                error_log("Could not retrieve property value after updating internal status for {$propertyName} on {$entityType} {$entityId}");
            }

            ApiResponse::success(['message' => 'Property internal status updated.']);
        } else {
            ApiResponse::error('Failed to update property internal status', 500);
        }
    } catch (Exception $e) {
        ApiResponse::error('Server error: ' . $e->getMessage(), 500);
    }
    exit; // Ensure script termination after POST
} else {
    ApiResponse::error('Method not allowed', 405);
}

?>