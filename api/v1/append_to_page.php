<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../property_parser.php';
require_once __DIR__ . '/../validator_utils.php';
// For property processing, we might need functions from notes.php or properties.php
require_once __DIR__ . '/../property_auto_internal.php'; // For determinePropertyInternalStatus
require_once __DIR__ . '/properties.php'; // For _updateOrAddPropertyAndDispatchTriggers (if used directly)


// Helper function (adapted from api/v1/notes.php - needs careful placement or inclusion)
// Ensure this function is defined or accessible in this script's scope.
// For this subtask, we can define it directly in the script if it's not too large,
// or assume it's available if properties.php is correctly included and defines it globally.
// To be safe, let's re-declare a simplified version or ensure it's truly available.
// For now, we'll assume _updateOrAddPropertyAndDispatchTriggers and determinePropertyInternalStatus are accessible.

if (!function_exists('local_processNoteContentAndProperties')) {
    function local_processNoteContentAndProperties($pdo, $content, $entityType, $entityId) {
        $propertyParser = new PropertyParser($pdo);
        // property_parser.php provides parsePropertiesFromContent which returns an array of ['name' => ..., 'value' => ..., 'is_internal' => ...]
        $parsedPropertiesRaw = $propertyParser->parsePropertiesFromContent($content);
        
        $finalPropertiesData = [];

        foreach ($parsedPropertiesRaw as $prop) {
            $isInternal = $prop['is_internal'] || determinePropertyInternalStatus($prop['name'], $prop['value']);
            // Directly save/update. _updateOrAddPropertyAndDispatchTriggers handles complex logic including triggers.
            // For this endpoint, a direct save might be initially simpler if triggers aren't essential for the first pass.
            // However, using the existing function ensures consistency.
             _updateOrAddPropertyAndDispatchTriggers(
                $pdo,
                $entityType,
                $entityId,
                $prop['name'],
                $prop['value'],
                $isInternal, // Pass the determined internal status
                false // No individual commit within the loop
            );
            if (!isset($finalPropertiesData[$prop['name']])) {
                $finalPropertiesData[$prop['name']] = [];
            }
            $finalPropertiesData[$prop['name']][] = ['value' => $prop['value'], 'internal' => (int)$isInternal];
        }
        return $finalPropertiesData; // Return structured properties for the response
    }
}

if (!function_exists('local_checkAndSetNoteInternalFlag')) {
    function local_checkAndSetNoteInternalFlag($pdo, $noteId) {
        $stmt = $pdo->prepare("SELECT value FROM Properties WHERE note_id = ? AND name = 'internal' AND active = 1");
        $stmt->execute([$noteId]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($properties as $prop) {
            if (strtolower($prop['value']) === 'true') {
                $updateStmt = $pdo->prepare("UPDATE Notes SET internal = 1 WHERE id = ?");
                $updateStmt->execute([$noteId]);
                return true;
            }
        }
        // Ensure note is not internal if property is not set or false
        $updateStmt = $pdo->prepare("UPDATE Notes SET internal = 0 WHERE id = ?");
        $updateStmt->execute([$noteId]);
        return false;
    }
}


header('Content-Type: application/json');
$pdo = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($method !== 'POST') {
    ApiResponse::error('Method not allowed. Only POST is supported.', 405);
    exit;
}

// 1. Validate input: page_name
if (!isset($input['page_name']) || !is_string($input['page_name']) || empty(trim($input['page_name']))) {
    ApiResponse::error('page_name is required and must be a non-empty string.', 400);
    exit;
}
$page_name = Validator::sanitizeString($input['page_name']);

$page_id = null;
$page_data = null;
$created_page = false;
$created_notes_list = [];
$temp_id_map = []; // For mapping client-side temporary IDs to actual DB IDs

try {
    $pdo->beginTransaction();

    // 2. Page Handling (Retrieve or Create) - (from previous step)
    $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$page_name]);
    $page_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($page_data) {
        $page_id = $page_data['id'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $page_name) || strtolower($page_name) === 'journal') {
            $propStmt = $pdo->prepare("SELECT 1 FROM Properties WHERE page_id = ? AND name = 'type' AND value = 'journal'");
            $propStmt->execute([$page_id]);
            if (!$propStmt->fetch()) {
                $insertPropStmt = $pdo->prepare("INSERT INTO Properties (page_id, name, value, internal) VALUES (?, 'type', 'journal', 0)");
                $insertPropStmt->execute([$page_id]);
            }
        }
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO Pages (name, updated_at) VALUES (?, CURRENT_TIMESTAMP)");
        $insertStmt->execute([$page_name]);
        $page_id = $pdo->lastInsertId();
        if (!$page_id) {
            $pdo->rollBack();
            ApiResponse::error('Failed to create page.', 500);
            exit;
        }
        $created_page = true;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $page_name) || strtolower($page_name) === 'journal') {
            $insertPropStmt = $pdo->prepare("INSERT INTO Properties (page_id, name, value, internal) VALUES (?, 'type', 'journal', 0)");
            $insertPropStmt->execute([$page_id]);
        }
        $stmt = $pdo->prepare("SELECT * FROM Pages WHERE id = ?");
        $stmt->execute([$page_id]);
        $page_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 3. Note Handling
    if (!isset($input['notes'])) {
        $pdo->rollBack(); // Nothing to append
        ApiResponse::error('notes field is required.', 400);
        exit;
    }

    $notes_input = $input['notes'];
    if (is_string($notes_input)) {
        $notes_input = [['content' => $notes_input]]; // Convert single string to array of one note
    }

    if (!is_array($notes_input)) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ApiResponse::error('notes field must be a string or an array of note objects.', 400);
        exit;
    }

    foreach ($notes_input as $index => $note_item) {
        if (!is_array($note_item)) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ApiResponse::error("Each item in notes array must be an object (associative array). Error at index {$index}.", 400);
            exit;
        }

        $client_temp_id = $note_item['client_temp_id'] ?? null;

        // Validate 'content'
        if (!isset($note_item['content']) || !is_string($note_item['content'])) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ApiResponse::error("Note item at index {$index} ('{$client_temp_id}') must have a 'content' field of type string.", 400);
            exit;
        }
        $content = $note_item['content'];
        
        // Validate 'parent_note_id' (type check)
        if (isset($note_item['parent_note_id'])) {
            $temp_parent_id_val = $note_item['parent_note_id'];
            if (!is_string($temp_parent_id_val) && !is_numeric($temp_parent_id_val) && $temp_parent_id_val !== null) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                ApiResponse::error("Note item at index {$index} ('{$client_temp_id}') has an invalid 'parent_note_id'. It must be a string (temp ID), a number (DB ID), or null.", 400);
                exit;
            }
        }
        
        // Validate 'order_index' and set default
        $order_index = $note_item['order_index'] ?? 0; 
        if (isset($note_item['order_index'])) {
             if (is_string($note_item['order_index']) && is_numeric($note_item['order_index'])) {
                 $order_index = (int)$note_item['order_index'];
             } elseif (!is_int($note_item['order_index'])) { // Handles cases where it might be float or non-numeric string
                if ($pdo->inTransaction()) $pdo->rollBack();
                ApiResponse::error("Note item at index {$index} ('{$client_temp_id}') has an invalid 'order_index'. It must be an integer or a numeric string.", 400);
                exit;
             }
        }

        // Validate 'collapsed' and set default
        $collapsed = $note_item['collapsed'] ?? 0; 
        if (isset($note_item['collapsed'])) {
            if (is_bool($note_item['collapsed'])) {
                $collapsed = (int)$note_item['collapsed'];
            } elseif (is_string($note_item['collapsed']) && ($note_item['collapsed'] === '0' || $note_item['collapsed'] === '1')) {
                $collapsed = (int)$note_item['collapsed'];
            } elseif (is_int($note_item['collapsed']) && ($note_item['collapsed'] === 0 || $note_item['collapsed'] === 1)) {
                // $collapsed is already correctly set as an int (0 or 1)
            } else { // Covers non-numeric strings, floats, integers not 0 or 1
                if ($pdo->inTransaction()) $pdo->rollBack();
                ApiResponse::error("Note item at index {$index} ('{$client_temp_id}') has an invalid 'collapsed' value. It must be a boolean, integer (0 or 1), or string ('0' or '1').", 400);
                exit;
            }
        }
        
        // Validate 'client_temp_id'
        if ($client_temp_id !== null && !is_string($client_temp_id)) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ApiResponse::error("Note item at index {$index} has an invalid 'client_temp_id'. It must be a string if provided.", 400);
            exit;
        }
        
        $final_parent_note_id_for_sql = null;
        if (isset($note_item['parent_note_id'])) {
            $temp_parent_id_val_sql = $note_item['parent_note_id'];
            if (is_string($temp_parent_id_val_sql) && isset($temp_id_map[$temp_parent_id_val_sql])) {
                $final_parent_note_id_for_sql = $temp_id_map[$temp_parent_id_val_sql];
            } elseif (is_numeric($temp_parent_id_val_sql)) {
                $final_parent_note_id_for_sql = (int)$temp_parent_id_val_sql;
            } elseif ($temp_parent_id_val_sql === null){
                $final_parent_note_id_for_sql = null;
            }
        }

        $sql_note_fields = ['page_id', 'content', 'parent_note_id', 'order_index', 'collapsed'];
        $sql_note_params = [
            ':page_id' => $page_id,
            ':content' => $content,
            ':parent_note_id' => $final_parent_note_id_for_sql,
            ':order_index' => $order_index,
            ':collapsed' => $collapsed
        ];
        
        $sql_note_field_placeholders = implode(', ', $sql_note_fields);
        $sql_note_value_placeholders = ':' . implode(', :', $sql_note_fields);
        
        $sql = "INSERT INTO Notes ($sql_note_field_placeholders) VALUES ($sql_note_value_placeholders)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($sql_note_params);
        $note_id = $pdo->lastInsertId();

        if (!$note_id) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ApiResponse::error("Failed to create note entry for item at index {$index} ('{$client_temp_id}').", 500);
            exit;
        }

        if ($client_temp_id !== null) {
            $temp_id_map[$client_temp_id] = $note_id;
        }

        $note_properties = local_processNoteContentAndProperties($pdo, $content, 'note', $note_id);
        local_checkAndSetNoteInternalFlag($pdo, $note_id);

        $stmt_new_note = $pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt_new_note->execute([':id' => $note_id]);
        $new_note_data = $stmt_new_note->fetch(PDO::FETCH_ASSOC);
        if ($new_note_data) {
            $new_note_data['properties'] = $note_properties;
            $created_notes_list[] = $new_note_data;
        }
    } // End of foreach

    $pdo->commit();

    // Re-fetch page_data to include any new properties like 'type: journal' if it was added.
    $dataManager = new DataManager($pdo); // Use DataManager for consistency
    $final_page_data = $dataManager->getPageDetailsById($page_id, true); // true for includeInternal

    ApiResponse::success([
        'message' => ($created_page ? 'Page created' : 'Page retrieved') . ' and notes appended successfully.',
        'page' => $final_page_data,
        'appended_notes' => $created_notes_list
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in append_to_page.php: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    ApiResponse::error('An error occurred: ' . $e->getMessage(), 500, ['trace' => $e->getTraceAsString()]);
    exit;
}

?>
