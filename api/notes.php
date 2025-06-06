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
    $processor = getPatternProcessor();
    $results = $processor->processContent($content, $entityType, $entityId);
    
    // Save the extracted properties
    if (!empty($results['properties'])) {
        $processor->saveProperties($results['properties'], $entityType, $entityId);
    }
    
    return $results;
}

if ($method === 'GET') {
    $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);

    if (isset($_GET['id'])) {
        // Get single note
        $noteId = (int)$_GET['id'];
        error_log("[NOTES_API_DEBUG] Getting single note with ID: " . $noteId);
        $sql = "SELECT * FROM Notes WHERE id = :id";
        if (!$includeInternal) {
            $sql .= " AND internal = 0";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $noteId, PDO::PARAM_INT);
        $stmt->execute();
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($note) {
            error_log("[NOTES_API_DEBUG] Found note: " . json_encode($note));
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
            error_log("[NOTES_API_DEBUG] Found properties: " . json_encode($propertiesResult));
            
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
            
            error_log("[NOTES_API_DEBUG] Final note with properties: " . json_encode($note));
            sendJsonResponse(['success' => true, 'data' => $note]);
        } else {
            error_log("[NOTES_API_DEBUG] Note not found or is internal");
            sendJsonResponse(['success' => false, 'error' => 'Note not found or is internal'], 404); // Updated error message
        }
    } elseif (isset($_GET['page_id'])) {
        try {
            error_log("[NOTES_API_DEBUG] Starting page load for page_id: " . $_GET['page_id']);
            
            // Get notes for a page
            $pageId = (int)$_GET['page_id'];
            $notesSql = "SELECT * FROM Notes WHERE page_id = :page_id";
            if (!$includeInternal) {
                $notesSql .= " AND internal = 0";
            }
            $notesSql .= " ORDER BY order_index ASC";
            
            error_log("[NOTES_API_DEBUG] Executing notes query: " . $notesSql);
            $stmt = $pdo->prepare($notesSql);
            $stmt->bindParam(':page_id', $pageId, PDO::PARAM_INT);
            $stmt->execute();
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("[NOTES_API_DEBUG] Found " . count($notes) . " notes");
            
            $noteIds = array_column($notes, 'id');
            
            foreach ($notes as &$note) {
                $note['properties'] = []; // Initialize properties
            }
            unset($note);

            if (!empty($noteIds)) {
                error_log("[NOTES_API_DEBUG] Fetching properties for " . count($noteIds) . " notes");
                $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
                $propSql = "SELECT note_id, name, value, internal FROM Properties WHERE note_id IN ($placeholders)";
                if (!$includeInternal) {
                    $propSql .= " AND internal = 0";
                }
                $propSql .= " ORDER BY name";

                $stmtProps = $pdo->prepare($propSql);
                $stmtProps->execute($noteIds);
                $propertiesResult = $stmtProps->fetchAll(PDO::FETCH_ASSOC);
                error_log("[NOTES_API_DEBUG] Found " . count($propertiesResult) . " properties");
                
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
            error_log("[NOTES_API_DEBUG] Fetching page details");
            // 1. Fetch page details
            $pageSql = "SELECT * FROM Pages WHERE id = :page_id";
            $stmtPage = $pdo->prepare($pageSql);
            $stmtPage->bindParam(':page_id', $pageId, PDO::PARAM_INT);
            $stmtPage->execute();
            $pageDetails = $stmtPage->fetch(PDO::FETCH_ASSOC);

            if (!$pageDetails) {
                error_log("[NOTES_API_DEBUG] Page not found for ID: " . $pageId);
                sendJsonResponse(['success' => false, 'error' => 'Page not found'], 404);
                return;
            }
            error_log("[NOTES_API_DEBUG] Found page: " . json_encode($pageDetails));

            // 2. Fetch page properties
            error_log("[NOTES_API_DEBUG] Fetching page properties");
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
            error_log("[NOTES_API_DEBUG] Found " . count($pagePropertiesResult) . " page properties");
            
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
            error_log("[NOTES_API_DEBUG] Constructing final response");
            $response = [
                'success' => true,
                'data' => [
                    'page' => $pageDetails,
                    'notes' => $notes // $notes is from the original part of the page_id block
                ]
            ];
            error_log("[NOTES_API_DEBUG] Final response: " . json_encode($response));
            sendJsonResponse($response);
            // END NEW LOGIC FOR GET ?page_id={id}

        } catch (Exception $e) {
            error_log("[NOTES_API_ERROR] Error loading page: " . $e->getMessage());
            error_log("[NOTES_API_ERROR] Stack trace: " . $e->getTraceAsString());
            sendJsonResponse(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    } else {
        sendJsonResponse(['success' => false, 'error' => 'Either page_id or id is required for GET request'], 400);
    }
} elseif ($method === 'POST') {
    // Added check for invalid JSON payload
    if ($input === null) {
        error_log("[NOTES_API_ERROR] Invalid JSON payload received");
        sendJsonResponse(['success' => false, 'error' => 'Invalid JSON payload or empty request body'], 400);
    }

    if (!isset($input['page_id'])) {
        error_log("[NOTES_API_ERROR] Missing page_id in POST request");
        sendJsonResponse(['success' => false, 'error' => 'Page ID is required'], 400);
    }
    
    try {
        error_log("[NOTES_API_DEBUG] Starting note creation for page_id: " . $input['page_id']);
        validateNoteData($input);
        
        $pdo->beginTransaction();
        
        // Get max order_index for the page
        $stmt = $pdo->prepare("SELECT MAX(order_index) as max_order FROM Notes WHERE page_id = ?");
        $stmt->execute([(int)$input['page_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $orderIndex = ($result['max_order'] ?? 0) + 1;
        error_log("[NOTES_API_DEBUG] New note will have order_index: " . $orderIndex);
        
        // Insert new note
        $stmt = $pdo->prepare("
            INSERT INTO Notes (page_id, content, parent_note_id, order_index, internal, created_at, updated_at)
            VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) 
        ");
        $stmt->execute([
            (int)$input['page_id'],
            $input['content'],
            isset($input['parent_note_id']) ? (int)$input['parent_note_id'] : null,
            $orderIndex
        ]);
        
        $noteId = $pdo->lastInsertId();
        error_log("[NOTES_API_DEBUG] Created note with ID: " . $noteId);
        
        // Process note content and save properties
        error_log("[NOTES_API_DEBUG] Processing note content for properties");
        processNoteContent($pdo, $input['content'], 'note', $noteId);
        
        // Fetch the created note
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        $note['properties'] = [];
        
        $pdo->commit();
        error_log("[NOTES_API_DEBUG] Note creation completed successfully");
        sendJsonResponse(['success' => true, 'data' => $note]);
    } catch (PDOException $e) {
        error_log("[NOTES_API_ERROR] Database error during note creation: " . $e->getMessage());
        error_log("[NOTES_API_ERROR] Stack trace: " . $e->getTraceAsString());
        $pdo->rollBack();
        sendJsonResponse(['success' => false, 'error' => 'Failed to create note: ' . $e->getMessage()], 500);
    } catch (Exception $e) {
        error_log("[NOTES_API_ERROR] General error during note creation: " . $e->getMessage());
        error_log("[NOTES_API_ERROR] Stack trace: " . $e->getTraceAsString());
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendJsonResponse(['success' => false, 'error' => 'Failed to create note: ' . $e->getMessage()], 500);
    }
} elseif ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        sendJsonResponse(['success' => false, 'error' => 'Note ID is required'], 400);
    }
    
    // Content is not always required for PUT (e.g. only changing parent/order)
    // validateNoteData($input); // Content is only required if it's the only thing sent or for new notes

    try {
        $pdo->beginTransaction();
        
        $noteId = (int)$_GET['id'];

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
        sendJsonResponse(['success' => true, 'data' => $note]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        sendJsonResponse(['success' => false, 'error' => 'Failed to update note: ' . $e->getMessage()], 500);
    }
} elseif ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        sendJsonResponse(['success' => false, 'error' => 'Note ID is required'], 400);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if note exists
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            sendJsonResponse(['success' => false, 'error' => 'Note not found'], 404);
        }
        
        // Delete properties first (due to foreign key constraint)
        $stmt = $pdo->prepare("DELETE FROM Properties WHERE note_id = ?"); // Corrected column name
        $stmt->execute([(int)$_GET['id']]);
        
        // Delete the note
        $stmt = $pdo->prepare("DELETE FROM Notes WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        
        $pdo->commit();
        sendJsonResponse(['success' => true, 'data' => ['deleted_note_id' => (int)$_GET['id']]]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        sendJsonResponse(['success' => false, 'error' => 'Failed to delete note: ' . $e->getMessage()], 500);
    }
} else {
    sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}