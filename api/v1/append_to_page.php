<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../property_parser.php';
require_once __DIR__ . '/../validator_utils.php';
require_once __DIR__ . '/../property_auto_internal.php';
require_once __DIR__ . '/properties.php'; // For _updateOrAddPropertyAndDispatchTriggers

// New "Smart Property Indexer"
// This function is the single source of truth for processing properties from content.
if (!function_exists('_indexPropertiesFromContent')) {
    function _indexPropertiesFromContent($pdo, $entityType, $entityId, $content) {
        // For notes, check if encrypted. If so, do not process properties from content.
        if ($entityType === 'note') {
            $encryptedStmt = $pdo->prepare("SELECT 1 FROM Properties WHERE note_id = :note_id AND name = 'encrypted' AND value = 'true' AND internal = 1 LIMIT 1");
            $encryptedStmt->execute([':note_id' => $entityId]);
            if ($encryptedStmt->fetch()) {
                return []; // Note is encrypted, do not parse/modify properties from its content.
            }
        }

        // 1. Clear existing 'replaceable' properties.
        $deleteSql = "DELETE FROM Properties WHERE {$entityType}_id = ? AND weight < 4";
        $stmtDelete = $pdo->prepare($deleteSql);
        $stmtDelete->execute([$entityId]);

        // 2. Parse new properties from content.
        $propertyParser = new PropertyParser($pdo);
        $parsedProperties = $propertyParser->parsePropertiesFromContent($content);

        // 3. Save all parsed properties and check for the 'internal' flag.
        $finalPropertiesForResponse = [];
        $hasInternalTrue = false;

        foreach ($parsedProperties as $prop) {
            $name = $prop['name'];
            $value = (string)$prop['value'];
            $isInternal = determinePropertyInternalStatus($name, $value);

            _updateOrAddPropertyAndDispatchTriggers($pdo, $entityType, $entityId, $name, $value, $isInternal, false);

            if (strtolower($name) === 'internal' && strtolower($value) === 'true') {
                $hasInternalTrue = true;
            }

            if (!isset($finalPropertiesForResponse[$name])) $finalPropertiesForResponse[$name] = [];
            $finalPropertiesForResponse[$name][] = ['value' => $value, 'internal' => (int)$isInternal];
        }

        // 4. Update the note's 'internal' flag.
        if ($entityType === 'note') {
             try {
                $updateStmt = $pdo->prepare("UPDATE Notes SET internal = ? WHERE id = ?");
                $updateStmt->execute([$hasInternalTrue ? 1 : 0, $entityId]);
            } catch (PDOException $e) {
                error_log("Could not update Notes.internal flag in append_to_page.php. Error: " . $e->getMessage());
            }
        }
        
        return $finalPropertiesForResponse;
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

// 1. Validate input
if (!isset($input['page_name']) || !is_string($input['page_name']) || empty(trim($input['page_name']))) {
    ApiResponse::error('page_name is required and must be a non-empty string.', 400);
    exit;
}
$page_name = Validator::sanitizeString($input['page_name']);

$page_id = null;
$page_data = null;
$created_page = false;
$created_notes_list = [];
$temp_id_map = [];

try {
    $pdo->beginTransaction();

    // 2. Page Handling (Retrieve or Create)
    $dataManager = new DataManager($pdo);
    $page_data = $dataManager->getPageDetailsByName($page_name, true);

    if ($page_data) {
        $page_id = $page_data['id'];
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO Pages (name, updated_at) VALUES (?, CURRENT_TIMESTAMP)");
        $insertStmt->execute([$page_name]);
        $page_id = $pdo->lastInsertId();
        if (!$page_id) {
            throw new Exception('Failed to create page.');
        }
        $created_page = true;
    }
    
    // Ensure journal property for journal-like pages
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $page_name) || strtolower($page_name) === 'journal') {
        $propStmt = $pdo->prepare("INSERT INTO Properties (page_id, name, value, internal) VALUES (?, 'type', 'journal', 0) ON CONFLICT(page_id, name) DO NOTHING");
        $propStmt->execute([$page_id]);
    }

    // 3. Note Handling
    if (!isset($input['notes'])) {
        throw new Exception('notes field is required.');
    }
    $notes_input = is_string($input['notes']) ? [['content' => $input['notes']]] : $input['notes'];
    if (!is_array($notes_input)) {
        throw new Exception('notes field must be a string or an array of note objects.');
    }

    foreach ($notes_input as $index => $note_item) {
        if (!is_array($note_item) || !isset($note_item['content']) || !is_string($note_item['content'])) {
            throw new Exception("Each note item must be an object with a 'content' string. Error at index {$index}.");
        }

        $content = $note_item['content'];
        $client_temp_id = $note_item['client_temp_id'] ?? null;
        $order_index = (int)($note_item['order_index'] ?? 0);
        $collapsed = (int)($note_item['collapsed'] ?? 0);
        
        $final_parent_note_id = null;
        if (isset($note_item['parent_note_id'])) {
            $parent_id = $note_item['parent_note_id'];
            if (is_string($parent_id) && isset($temp_id_map[$parent_id])) {
                $final_parent_note_id = $temp_id_map[$parent_id];
            } elseif (is_numeric($parent_id)) {
                $final_parent_note_id = (int)$parent_id;
            }
        }

        $sql = "INSERT INTO Notes (page_id, content, parent_note_id, order_index, collapsed) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$page_id, $content, $final_parent_note_id, $order_index, $collapsed]);
        $note_id = $pdo->lastInsertId();

        if (!$note_id) {
            throw new Exception("Failed to create note entry for item at index {$index}.");
        }

        if ($client_temp_id) {
            $temp_id_map[$client_temp_id] = $note_id;
        }

        // Use the new centralized indexer
        $note_properties = _indexPropertiesFromContent($pdo, 'note', $note_id, $content);

        $stmt_new_note = $pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt_new_note->execute([':id' => $note_id]);
        $new_note_data = $stmt_new_note->fetch(PDO::FETCH_ASSOC);
        if ($new_note_data) {
            $new_note_data['properties'] = $note_properties;
            $created_notes_list[] = $new_note_data;
        }
    }

    $pdo->commit();

    // Re-fetch page_data to include any new properties
    $final_page_data = $dataManager->getPageDetailsById($page_id, true);

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
    ApiResponse::error('An error occurred: ' . $e->getMessage(), 500);
}