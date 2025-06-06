<?php
require_once '../config.php';
require_once 'db_connect.php';
require_once 'property_trigger_service.php';
require_once 'pattern_processor.php';
require_once 'property_parser.php';

header('Content-Type: application/json');
$pdo = get_db_connection();
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

// Helper function to send JSON response
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Helper function to validate note data
function validateNoteData($data) {
    if (!isset($data['content'])) {
        sendJsonResponse(['success' => false, 'error' => 'Note content is required'], 400);
    }
    return true;
}

// Helper function to process note content and extract properties
function processNoteContent($pdo, $content, $entityType, $entityId) {
    // This now correctly syncs properties with the database and returns the parsed properties.
    // The entityType parameter is not used by syncNotePropertiesFromContent but is kept for compatibility.
    return syncNotePropertiesFromContent($pdo, $entityId, $content);
}

if ($method === 'GET') {
    $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);

    if (isset($_GET['id'])) {
        // Get single note
        $noteId = (int)$_GET['id'];
        $sql = "SELECT * FROM Notes WHERE id = :id";
        if (!$includeInternal) {
            $sql .= " AND internal = 0";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $noteId, PDO::PARAM_INT);
        $stmt->execute();
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($note) {
            // Get properties for the note
            $propSql = "SELECT name, value, internal FROM Properties WHERE note_id = :note_id";
            if (!$includeInternal) {
                $propSql .= " AND internal = 0";
            }
            $propSql .= " ORDER BY name"; // Added order for consistency
            $stmtProps = $pdo->prepare($propSql);
            $stmtProps->bindParam(':note_id', $note['id'], PDO::PARAM_INT);
            $stmtProps->execute();
            $propertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);
            
            $note['properties'] = [];
            foreach ($propertiesResult as $prop) {
                if (!isset($note['properties'][$prop['name']])) {
                    $note['properties'][$prop['name']] = [];
                }
                // Match structure of api/properties.php
                $propEntry = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
                $note['properties'][$prop['name']][] = $propEntry;
            }

            foreach ($note['properties'] as $name => $values) {
                if (count($values) === 1) {
                    if (!$includeInternal && $values[0]['internal'] == 0) {
                        $note['properties'][$name] = $values[0]['value'];
                    } else {
                        $note['properties'][$name] = $values[0];
                    }
                } else {
                     $note['properties'][$name] = $values; // Keep as array of objects for lists
                }
            }
            
            sendJsonResponse(['success' => true, 'data' => $note]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Note not found or is internal'], 404); // Updated error message
        }
    } elseif (isset($_GET['page_id'])) {
        try {
            $pageId = (int)$_GET['page_id'];
            $notesSql = "SELECT * FROM Notes WHERE page_id = :page_id";
            if (!$includeInternal) {
                $notesSql .= " AND internal = 0";
            }
            $notesSql .= " ORDER BY order_index ASC";
            
            $stmt = $pdo->prepare($notesSql);
            $stmt->bindParam(':page_id', $pageId, PDO::PARAM_INT);
            $stmt->execute();
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $noteIds = array_column($notes, 'id');
            
            foreach ($notes as &$note) {
                $note['properties'] = []; // Initialize properties
            }
            unset($note);

            if (!empty($noteIds)) {
                $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
                $propSql = "SELECT note_id, name, value, internal FROM Properties WHERE note_id IN ($placeholders)";
                if (!$includeInternal) {
                    $propSql .= " AND internal = 0";
                }
                $propSql .= " ORDER BY name";

                $stmtProps = $pdo->prepare($propSql);
                $stmtProps->execute($noteIds);
                $propertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);
                
                $propertiesByNote = [];
                foreach ($propertiesResult as $prop) {
                    $currentNoteId = $prop['note_id'];
                    if (!isset($propertiesByNote[$currentNoteId])) {
                        $propertiesByNote[$currentNoteId] = [];
                    }
                    $propName = $prop['name'];
                    if (!isset($propertiesByNote[$currentNoteId][$propName])) {
                        $propertiesByNote[$currentNoteId][$propName] = [];
                    }
                    $propertiesByNote[$currentNoteId][$propName][] = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
                }
                
                foreach ($notes as &$note) {
                    if (isset($propertiesByNote[$note['id']])) {
                        foreach ($propertiesByNote[$note['id']] as $name => $values) {
                            if (count($values) === 1) {
                                 if (!$includeInternal && $values[0]['internal'] == 0) {
                                    $note['properties'][$name] = $values[0]['value'];
                                } else {
                                    $note['properties'][$name] = $values[0];
                                }
                            } else {
                                $note['properties'][$name] = $values; // Keep as array of objects for lists
                            }
                        }
                    }
                }
                unset($note);
            }
            
            // START NEW LOGIC FOR GET ?page_id={id}
            $pageSql = "SELECT * FROM Pages WHERE id = :page_id";
            $stmtPage = $pdo->prepare($pageSql);
            $stmtPage->bindParam(':page_id', $pageId, PDO::PARAM_INT);
            $stmtPage->execute();
            $pageDetails = $stmtPage->fetch(PDO::FETCH_ASSOC);

            if (!$pageDetails) {
                sendJsonResponse(['success' => false, 'error' => 'Page not found'], 404);
                return;
            }

            // 2. Fetch page properties
            $pageProperties = [];
            $pagePropSql = "SELECT name, value, internal FROM Properties WHERE page_id = :page_id AND note_id IS NULL";
            if (!$includeInternal) {
                $pagePropSql .= " AND internal = 0";
            }
            $pagePropSql .= " ORDER BY name";
            
            $stmtPageProps = $pdo->prepare($pagePropSql);
            $stmtPageProps->bindParam(':page_id', $pageId, PDO::PARAM_INT);
            $stmtPageProps->execute();
            $pagePropertiesResult = $stmtPageProps->fetchAll(PDO::FETCH_ASSOC);
            
            $pagePropertiesFormatted = [];
            foreach ($pagePropertiesResult as $prop) {
                if (!isset($pagePropertiesFormatted[$prop['name']])) {
                    $pagePropertiesFormatted[$prop['name']] = [];
                }
                $propEntry = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
                $pagePropertiesFormatted[$prop['name']][] = $propEntry;
            }

            foreach ($pagePropertiesFormatted as $name => $values) {
                if (count($values) === 1) {
                    if (!$includeInternal && $values[0]['internal'] == 0) {
                        $pagePropertiesFormatted[$name] = $values[0]['value'];
                    } else {
                        // If includeInternal is true, or if it's false but the property is internal (which shouldn't happen due to SQL filter, but as a safeguard)
                        $pagePropertiesFormatted[$name] = $values[0];
                    }
                } else {
                    // For multiple values, always return the array of objects
                     $pagePropertiesFormatted[$name] = $values;
                }
            }
            $pageDetails['properties'] = $pagePropertiesFormatted;

            // Notes are already fetched and processed with their properties by the existing logic above this new block.
            // $notes variable already contains the notes with their properties.

            // 3. Construct the final JSON response
            $response = [
                'success' => true,
                'data' => [
                    'page' => $pageDetails,
                    'notes' => $notes // $notes is from the original part of the page_id block
                ]
            ];
            sendJsonResponse($response);
            // END NEW LOGIC FOR GET ?page_id={id}

        } catch (Exception $e) {
            sendJsonResponse(['success' => false, 'error' => 'An error occurred while fetching notes: ' . $e->getMessage()], 500);
        }
    } else {
        // Get all notes (consider pagination for performance)
        // This is a placeholder and might need a more robust implementation
        $sql = "SELECT * FROM Notes";
        if (!$includeInternal) {
            $sql .= " WHERE internal = 0";
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->query($sql);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendJsonResponse(['success' => true, 'data' => $notes]);
    }
} elseif ($method === 'POST') {
    // Validate input
    if (!isset($input['page_id']) || !is_numeric($input['page_id'])) {
        sendJsonResponse(['success' => false, 'error' => 'A valid page_id is required.'], 400);
    }

    $pageId = (int)$input['page_id'];
    $content = isset($input['content']) ? $input['content'] : '';

    try {
        $pdo->beginTransaction();

        // 1. Create the note
        $stmt = $pdo->prepare("INSERT INTO Notes (page_id, content) VALUES (:page_id, :content)");
        $stmt->execute([':page_id' => $pageId, ':content' => $content]);
        $noteId = $pdo->lastInsertId();

        // 2. Parse and save properties from the content
        $properties = processNoteContent($pdo, $content, 'note', $noteId);

        $pdo->commit();

        // 3. Fetch the newly created note to return it
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = :id");
        $stmt->execute([':id' => $noteId]);
        $newNote = $stmt->fetch(PDO::FETCH_ASSOC);

        // Attach the parsed properties to the response
        $newNote['properties'] = $properties;

        sendJsonResponse(['success' => true, 'data' => $newNote], 201); // 201 Created

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to create note: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => 'Failed to create note.', 'details' => $e->getMessage()], 500);
    }
} elseif ($method === 'PUT') {
    // For phpdesktop compatibility, also check for ID in request body when using method override
    $noteId = null;
    if (isset($_GET['id'])) {
        $noteId = (int)$_GET['id'];
    } elseif (isset($input['id'])) {
        $noteId = (int)$input['id'];
    }
    
    if (!$noteId) {
        sendJsonResponse(['success' => false, 'error' => 'Note ID is required'], 400);
    }
    
    // Content is not always required for PUT (e.g. only changing parent/order)
    // validateNoteData($input); // Content is only required if it's the only thing sent or for new notes

    try {
        $pdo->beginTransaction();

        // Check if note exists
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $existingNote = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingNote) {
            $pdo->rollBack();
            sendJsonResponse(['success' => false, 'error' => 'Note not found'], 404);
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

        if (isset($input['content'])) {
            $setClauses[] = "content = ?";
            $executeParams[] = $input['content'];
        }
        if (array_key_exists('parent_note_id', $input)) { // Use array_key_exists to allow null
            $setClauses[] = "parent_note_id = ?";
            $executeParams[] = $input['parent_note_id'] === null ? null : (int)$input['parent_note_id'];
        }
        if (isset($input['order_index'])) {
            $setClauses[] = "order_index = ?";
            $executeParams[] = (int)$input['order_index'];
        }
        if (isset($input['collapsed'])) {
            $setClauses[] = "collapsed = ?";
            $executeParams[] = (int)$input['collapsed']; // Should be 0 or 1
        }

        if (empty($setClauses)) {
            $pdo->rollBack();
            sendJsonResponse(['success' => false, 'error' => 'No updateable fields provided'], 400);
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
            // Process note content and save properties
            $results = processNoteContent($pdo, $input['content'], 'note', $noteId);
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
        sendJsonResponse(['success' => true, 'data' => $note]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Failed to update note: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'error' => 'Failed to update note: ' . $e->getMessage()], 500);
    }
} elseif ($method === 'DELETE') {
    // For phpdesktop compatibility, also check for ID in request body when using method override
    $noteId = null;
    if (isset($_GET['id'])) {
        $noteId = (int)$_GET['id'];
    } elseif (isset($input['id'])) {
        $noteId = (int)$input['id'];
    }
    
    if (!$noteId) {
        sendJsonResponse(['success' => false, 'error' => 'Note ID is required'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if note exists
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            sendJsonResponse(['success' => false, 'error' => 'Note not found'], 404);
        }
        
        // Delete properties first (due to foreign key constraint)
        $stmt = $pdo->prepare("DELETE FROM Properties WHERE note_id = ?"); // Corrected column name
        $stmt->execute([$noteId]);
        
        // Delete the note
        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        
        $pdo->commit();
        sendJsonResponse(['success' => true, 'data' => ['deleted_note_id' => $noteId]]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        sendJsonResponse(['success' => false, 'error' => 'Failed to delete note: ' . $e->getMessage()], 500);
    }
} else {
    sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}