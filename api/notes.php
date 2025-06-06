<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_triggers.php';
require_once 'pattern_processor.php';
require_once 'property_parser.php';
require_once 'response_utils.php'; // Include the new response utility
require_once 'data_manager.php';   // Include the new DataManager
require_once 'validator_utils.php'; // Include the new Validator

// header('Content-Type: application/json'); // Will be handled by ApiResponse
$pdo = get_db_connection();
$dataManager = new DataManager($pdo); // Instantiate DataManager
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Handle method overriding for PUT via POST (e.g., for phpdesktop)
if ($method === 'POST' && isset($input['_method'])) {
    $overrideMethod = strtoupper($input['_method']);
    if ($overrideMethod === 'PUT' || $overrideMethod === 'DELETE') {
        $method = $overrideMethod;
    }
    // Optionally, remove _method from $input if it could interfere with validation or processing
    // unset($input['_method']); 
    // However, validateNoteData only checks for 'content', so it's likely fine to leave it.
}

// Helper function to process note content and extract properties
function processNoteContent($pdo, $content, $entityType, $entityId) {
    $processor = getPatternProcessor();
    $results = $processor->processContent($content, $entityType, $entityId);
    
    // Save the extracted properties
    if (!empty($results['properties'])) {
        $processor->saveProperties($results['properties'], $entityType, $entityId);
    }
    
    return $results;
}

if ($method === 'GET') {
    $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN); // This is fine as boolean
    $validationRules = [];
    $errors = [];

    if (isset($_GET['id'])) {
        $validationRules['id'] = 'required|isPositiveInteger';
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid input for note ID.', 400, $errors);
            exit;
        }
        $noteId = (int)$_GET['id'];
        $note = $dataManager->getNoteById($noteId, $includeInternal);
        
        if ($note) {
            ApiResponse::success($note);
        } else {
            ApiResponse::error('Note not found or is internal', 404);
        }
    } elseif (isset($_GET['page_id'])) {
        $validationRules['page_id'] = 'required|isPositiveInteger';
        $errors = Validator::validate($_GET, $validationRules);
        if (!empty($errors)) {
            ApiResponse::error('Invalid input for page ID.', 400, $errors);
            exit;
        }
        $pageId = (int)$_GET['page_id'];
        $pageWithNotes = $dataManager->getPageWithNotes($pageId, $includeInternal);

        if ($pageWithNotes) {
            ApiResponse::success($pageWithNotes);
        } else {
            ApiResponse::error('Page not found or contains no accessible notes', 404);
        }
    } else {
        ApiResponse::error('Either page_id or id is required for GET request', 400);
    }
} elseif ($method === 'POST') {
    $validationRules = [
        'page_id' => 'required|isPositiveInteger',
        'content' => 'required|isNotEmpty',
        'parent_note_id' => 'optional|isInteger' // Assuming parent_note_id can be 0 or null, isInteger is fine
    ];
    $errors = Validator::validate($input, $validationRules);
    if (!empty($errors)) {
        ApiResponse::error('Invalid input for creating note.', 400, $errors);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get max order_index for the page
        $stmt = $pdo->prepare("SELECT MAX(order_index) as max_order FROM Notes WHERE page_id = ?");
        $stmt->execute([$input['page_id']]); // Use validated input
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $orderIndex = ($result['max_order'] ?? 0) + 1;
        
        // Insert new note
        $stmt = $pdo->prepare("
            INSERT INTO Notes (page_id, content, parent_note_id, order_index, internal, created_at, updated_at)
            VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) 
        ");
        $stmt->execute([
            $input['page_id'], // Use validated input
            $input['content'], // Use validated input
            (isset($input['parent_note_id']) && $input['parent_note_id'] !== '') ? (int)$input['parent_note_id'] : null, // Handle optional & validated
            $orderIndex
        ]);
        
        $noteId = $pdo->lastInsertId();
        
        // Process note content and save properties
        processNoteContent($pdo, $input['content'], 'note', $noteId);
        
        // Fetch the created note
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        $note['properties'] = [];
        
        $pdo->commit();
        ApiResponse::success($note);
    } catch (PDOException $e) {
        $pdo->rollBack();
        ApiResponse::error('Failed to create note: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'PUT') {
    $validationRulesGET = ['id' => 'required|isPositiveInteger'];
    $errorsGET = Validator::validate($_GET, $validationRulesGET);
    if (!empty($errorsGET)) {
        ApiResponse::error('Invalid note ID in URL.', 400, $errorsGET);
        exit;
    }
    $noteId = (int)$_GET['id']; // Validated

    $validationRulesPUT = [
        'content' => 'optional|isNotEmpty', // Content is not always required for PUT
        'parent_note_id' => 'optional|isInteger', // Can be null
        'order_index' => 'optional|isInteger',    // Must be an integer if present
        'collapsed' => 'optional|isBooleanLike' // Must be 0 or 1 if present
    ];
    $errorsPUT = Validator::validate($input, $validationRulesPUT);
    if (!empty($errorsPUT)) {
        ApiResponse::error('Invalid input for updating note.', 400, $errorsPUT);
        exit;
    }

    try {
        $pdo->beginTransaction();
        
        // Check if note exists
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $existingNote = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingNote) {
            $pdo->rollBack();
            ApiResponse::error('Note not found', 404);
            exit; // Ensure script termination
        }

        // --- BEGIN order_index recalculation logic: Step 1 ---
        $old_parent_note_id = null;
        $old_order_index = null;
        $page_id_for_reordering = $existingNote['page_id']; // page_id should not change

        // Only fetch old parent and order if parent_note_id is part of the input,
        // or if order_index is changing (which might imply a move between siblings lists if parent_note_id is also changing)
        // For now, to be safe, we fetch if parent_note_id is potentially changing.
        // The problem description says "If parent_note_id is present in the $input (meaning it might change)"
        if (array_key_exists('parent_note_id', $input) || array_key_exists('order_index', $input)) {
            $old_parent_note_id = $existingNote['parent_note_id'];
            $old_order_index = $existingNote['order_index'];
        }
        // --- END order_index recalculation logic: Step 1 ---
        
        // Build the SET part of the SQL query dynamically
        $setClauses = [];
        $executeParams = [];

        // Use validated input, checking if keys exist because they are optional
        if (array_key_exists('content', $input)) {
            $setClauses[] = "content = ?";
            $executeParams[] = $input['content'];
        }
        if (array_key_exists('parent_note_id', $input)) {
            $setClauses[] = "parent_note_id = ?";
            // Ensure null is correctly passed if parent_note_id is explicitly set to null or empty string after validation
            $executeParams[] = ($input['parent_note_id'] === null || $input['parent_note_id'] === '') ? null : (int)$input['parent_note_id'];
        }
        if (array_key_exists('order_index', $input)) {
            $setClauses[] = "order_index = ?";
            $executeParams[] = (int)$input['order_index'];
        }
        if (array_key_exists('collapsed', $input)) {
            $setClauses[] = "collapsed = ?";
            $executeParams[] = (int)$input['collapsed']; 
        }

        if (empty($setClauses)) {
            $pdo->rollBack();
            ApiResponse::error('No updateable fields provided', 400);
            return; // Exit early
        }

        $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
        
        $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
        $executeParams[] = $noteId;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($executeParams);

        // --- BEGIN order_index recalculation logic: Step 2 ---
        // Retrieve the new parent_note_id and new_order_index for the note.
        // These are the values that were just written to the database.
        $new_parent_note_id = array_key_exists('parent_note_id', $input) ? ($input['parent_note_id'] === null ? null : (int)$input['parent_note_id']) : $existingNote['parent_note_id'];
        $new_order_index = isset($input['order_index']) ? (int)$input['order_index'] : $existingNote['order_index'];
        // page_id_for_reordering is already fetched from $existingNote earlier.
        // --- END order_index recalculation logic: Step 2 ---

        // --- BEGIN order_index recalculation logic: Step 3 (Reorder Old Siblings) ---
        // Check if parent_note_id actually changed and old values were captured
        if ($old_parent_note_id !== null && $old_order_index !== null) { // Ensure old values were set
            // Check if the parent has changed OR if the order within the same parent changed significantly
            // The main condition from description: "if old_parent_note_id is different from new_parent_note_id"
            // However, if order_index changes within the same parent, old siblings might still need adjustment
            // For now, strictly following: "if old_parent_note_id is different from new_parent_note_id"
            
            $old_parent_id_for_sql = $old_parent_note_id === null ? 0 : $old_parent_note_id;
            $new_parent_id_for_sql = $new_parent_note_id === null ? 0 : $new_parent_note_id;

            if ($old_parent_id_for_sql != $new_parent_id_for_sql) { // Compare normalized IDs
                $sqlReorderOld = "
                    UPDATE Notes
                    SET order_index = order_index - 1
                    WHERE page_id = :page_id
                      AND IFNULL(parent_note_id, 0) = :old_parent_note_id_sql
                      AND order_index > :old_order_index
                      AND id != :note_id
                ";
                $stmtReorderOld = $pdo->prepare($sqlReorderOld);
                $stmtReorderOld->bindParam(':page_id', $page_id_for_reordering, PDO::PARAM_INT);
                $stmtReorderOld->bindParam(':old_parent_note_id_sql', $old_parent_id_for_sql, PDO::PARAM_INT);
                $stmtReorderOld->bindParam(':old_order_index', $old_order_index, PDO::PARAM_INT);
                $stmtReorderOld->bindParam(':note_id', $noteId, PDO::PARAM_INT);
                $stmtReorderOld->execute();
            }
        }
        // --- END order_index recalculation logic: Step 3 ---

        // --- BEGIN order_index recalculation logic: Step 4 (Reorder New Siblings) ---
        // This needs to run regardless of whether the parent changed, as items in the new list need to make space.
        if (array_key_exists('order_index', $input) || array_key_exists('parent_note_id', $input)) { // If order or parent changed
            $new_parent_id_for_sql_step4 = $new_parent_note_id === null ? 0 : $new_parent_note_id;
            
            $sqlReorderNew = "
                UPDATE Notes
                SET order_index = order_index + 1
                WHERE page_id = :page_id
                  AND IFNULL(parent_note_id, 0) = :new_parent_note_id_sql
                  AND order_index >= :new_order_index
                  AND id != :note_id 
            "; // id != :note_id is crucial
            $stmtReorderNew = $pdo->prepare($sqlReorderNew);
            $stmtReorderNew->bindParam(':page_id', $page_id_for_reordering, PDO::PARAM_INT);
            $stmtReorderNew->bindParam(':new_parent_note_id_sql', $new_parent_id_for_sql_step4, PDO::PARAM_INT);
            $stmtReorderNew->bindParam(':new_order_index', $new_order_index, PDO::PARAM_INT);
            $stmtReorderNew->bindParam(':note_id', $noteId, PDO::PARAM_INT);
            $stmtReorderNew->execute();
        }
        // --- END order_index recalculation logic: Step 4 ---
        
        // If content was updated, process and save properties
        if (isset($input['content'])) {
            error_log("[NOTES_API_DEBUG] Processing note content for note {$noteId}");
            error_log("[NOTES_API_DEBUG] Content: " . $input['content']);
            
            // Delete existing properties EXCEPT status properties to preserve history
            $stmtDeleteProps = $pdo->prepare("DELETE FROM Properties WHERE note_id = ? AND name != 'status'");
            $stmtDeleteProps->execute([$noteId]);
            error_log("[NOTES_API_DEBUG] Deleted existing non-status properties");
            
            // Process note content and save properties
            $results = processNoteContent($pdo, $input['content'], 'note', $noteId);
            error_log("[NOTES_API_DEBUG] Pattern processor results: " . json_encode($results));
        }
        
        // Fetch updated note
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get properties (respecting include_internal - defaulting to false for PUT response)
        $propSql = "SELECT name, value, internal FROM Properties WHERE note_id = :note_id";
        // For PUT response, let's default to not showing internal properties, similar to a GET without include_internal
        $propSql .= " AND internal = 0";
        $propSql .= " ORDER BY name";
        
        $stmtProps = $pdo->prepare($propSql);
        $stmtProps->bindParam(':note_id', $note['id'], PDO::PARAM_INT);
        $stmtProps->execute();
        $propertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);

        $note['properties'] = [];
        foreach ($propertiesResult as $prop) {
            if (!isset($note['properties'][$prop['name']])) {
                $note['properties'][$prop['name']] = [];
            }
            // Match structure of api/properties.php for consistency
            // Since this is a default view (like include_internal=false), simplify if possible
            $propEntry = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
             // This property would only be here if its internal = 0 due to the query filter
            $note['properties'][$prop['name']][] = $prop['value']; // Simplified for non-internal properties
        }

        foreach ($note['properties'] as $name => $values) {
            if (count($values) === 1) {
                 $note['properties'][$name] = $values[0];
            }
            // If it was a list, it remains a list of values
        }
        
        $pdo->commit();
        ApiResponse::success($note);
    } catch (PDOException $e) {
        $pdo->rollBack();
        ApiResponse::error('Failed to update note: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'DELETE') {
    $validationRules = ['id' => 'required|isPositiveInteger'];
    $errors = Validator::validate($_GET, $validationRules);
    if (!empty($errors)) {
        ApiResponse::error('Invalid note ID in URL.', 400, $errors);
        exit;
    }
    $noteId = (int)$_GET['id']; // Validated
    
    try {
        $pdo->beginTransaction();
        
        // Check if note exists
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            ApiResponse::error('Note not found', 404);
            exit; 
        }
        
        // Delete properties first (due to foreign key constraint)
        $stmt = $pdo->prepare("DELETE FROM Properties WHERE note_id = ?");
        $stmt->execute([$noteId]);
        
        // Delete the note
        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        
        $pdo->commit();
        ApiResponse::success(['deleted_note_id' => (int)$_GET['id']]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        ApiResponse::error('Failed to delete note: ' . $e->getMessage(), 500);
    }
} else {
    ApiResponse::error('Method not allowed', 405);
}