<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../data_manager.php';
require_once __DIR__ . '/../property_parser.php';
require_once __DIR__ . '/../validator_utils.php';

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
$created_page = false;

try {
    $pdo->beginTransaction();

    // 2. Page Handling (Retrieve or Create)
    $stmt = $pdo->prepare("SELECT * FROM Pages WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$page_name]);
    $page_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($page_data) {
        $page_id = $page_data['id'];
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO Pages (name) VALUES (?)");
        $insertStmt->execute([$page_name]);
        $page_id = $pdo->lastInsertId();
        $created_page = true;
    }
    
    // Auto-add 'journal' type property for date-like or 'journal' page names
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $page_name) || strtolower($page_name) === 'journal') {
        $propStmt = $pdo->prepare("SELECT 1 FROM Properties WHERE page_id = ? AND name = 'type' AND value = 'journal'");
        $propStmt->execute([$page_id]);
        if (!$propStmt->fetch()) {
            $insertPropStmt = $pdo->prepare("INSERT INTO Properties (page_id, name, value, colon_count) VALUES (?, 'type', 'journal', 2)");
            $insertPropStmt->execute([$page_id]);
        }
    }

    // 3. Note Handling
    if (!isset($input['notes'])) {
        $pdo->rollBack();
        ApiResponse::error('notes field is required.', 400);
        exit;
    }

    $notes_input = is_string($input['notes']) ? [['content' => $input['notes']]] : $input['notes'];
    if (!is_array($notes_input)) {
        $pdo->rollBack();
        ApiResponse::error('notes field must be a string or an array of note objects.', 400);
        exit;
    }

    $created_notes_list = [];
    $temp_id_map = [];
    $propertyParser = new PropertyParser($pdo);

    foreach ($notes_input as $index => $note_item) {
        $content = $note_item['content'] ?? '';
        $parent_temp_id = $note_item['parent_note_id'] ?? null;
        $parent_id_for_sql = is_string($parent_temp_id) && isset($temp_id_map[$parent_temp_id]) ? $temp_id_map[$parent_temp_id] : (is_numeric($parent_temp_id) ? $parent_temp_id : null);
        
        $sql = "INSERT INTO Notes (page_id, content, parent_note_id, order_index, collapsed) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $page_id,
            $content,
            $parent_id_for_sql,
            $note_item['order_index'] ?? 0,
            $note_item['collapsed'] ?? 0
        ]);
        $note_id = $pdo->lastInsertId();

        if ($note_item['client_temp_id'] ?? null) {
            $temp_id_map[$note_item['client_temp_id']] = $note_id;
        }

        // Use the centralized property parser
        $propertyParser->syncNotePropertiesFromContent($note_id, $content);
        
        $dataManager = new DataManager($pdo);
        $new_note_data = $dataManager->getNoteById($note_id, true);
        if ($new_note_data) {
            $created_notes_list[] = $new_note_data;
        }
    }

    $pdo->commit();

    $dataManager = new DataManager($pdo);
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
    ApiResponse::error('An error occurred: ' . $e->getMessage(), 500, ['trace' => $e->getTraceAsString()]);
}

?>
