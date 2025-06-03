<?php
require_once 'db_connect.php';
require_once 'property_triggers.php';
require_once 'pattern_processor.php'; // Use new unified pattern processor
require_once 'property_auto_internal.php';

header('Content-Type: application/json');
$pdo = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Handle method overriding for PUT/DELETE via POST (e.g., for phpdesktop)
if ($method === 'POST' && isset($input['_method'])) {
    $overrideMethod = strtoupper($input['_method']);
    if ($overrideMethod === 'PUT' || $overrideMethod === 'DELETE') {
        $method = $overrideMethod;
        // Optionally, remove _method from $input if it could interfere with validation or processing
        // unset($input['_method']); 
        // However, validateNoteData only checks for 'content', so it's likely fine to leave it.
    }
}

// Helper function to send JSON response
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Helper function to validate note data
function validateNoteData($data, $contentRequired = true) {
    if ($contentRequired && !isset($data['content'])) {
        sendJsonResponse(['success' => false, 'error' => 'Note content is required'], 400);
    }
    if (isset($data['content']) && !is_string($data['content'])) {
        sendJsonResponse(['success' => false, 'error' => 'Note content must be a string'], 400);
    }
    if (isset($data['page_id']) && !is_numeric($data['page_id'])) {
        sendJsonResponse(['success' => false, 'error' => 'Page ID must be a number'], 400);
    }
    if (isset($data['parent_note_id']) && $data['parent_note_id'] !== null && !is_numeric($data['parent_note_id'])) {
        sendJsonResponse(['success' => false, 'error' => 'Parent note ID must be a number or null'], 400);
    }
    if (isset($data['order_index']) && !is_numeric($data['order_index'])) {
        sendJsonResponse(['success' => false, 'error' => 'Order index must be a number'], 400);
    }
    if (isset($data['collapsed']) && !in_array($data['collapsed'], [0, 1, '0', '1', true, false], true)) {
        sendJsonResponse(['success' => false, 'error' => 'Collapsed must be a boolean or 0/1'], 400);
    }
    return true;
}

// Helper function to parse properties from note content using new pattern processor
function parsePropertiesFromContent($content) {
    // Use the new pattern processor for backward compatibility
    $processor = getPatternProcessor();
    $results = $processor->processContent($content, 'note', 0);
    
    // Convert to old format for backward compatibility
    $oldFormat = [];
    foreach ($results['properties'] as $property) {
        $oldFormat[] = [
            'name' => $property['name'],
            'value' => $property['value']
        ];
    }
    return $oldFormat;
}

// Helper function to save properties for a note using pattern processor
function savePropertiesForNote($pdo, $noteId, $content) {
    try {
        error_log("[NOTES_API_DEBUG] Starting savePropertiesForNote for note {$noteId}");
        error_log("[NOTES_API_DEBUG] Content to process: " . $content);
        
        // Delete ALL existing properties for this note (both internal and non-internal)
        // This ensures we don't have stale properties if they're removed from content
        try {
            error_log("[NOTES_API_DEBUG] Deleting existing properties for note {$noteId}");
            $stmtDeleteProps = $pdo->prepare("DELETE FROM Properties WHERE note_id = ?");
            $stmtDeleteProps->execute([$noteId]);
            error_log("[NOTES_API_DEBUG] Successfully deleted existing properties");
        } catch (Exception $e) {
            error_log("[NOTES_API_ERROR] Failed to delete existing properties: " . $e->getMessage());
            throw $e;
        }
        
        // Use the pattern processor to get properties
        try {
            error_log("[NOTES_API_DEBUG] Getting pattern processor");
            $processor = getPatternProcessor();
            error_log("[NOTES_API_DEBUG] Processing content through pattern processor");
            $results = $processor->processContent($content, 'note', $noteId);
            error_log("[NOTES_API_DEBUG] Pattern processor results: " . json_encode($results));
        } catch (Exception $e) {
            error_log("[NOTES_API_ERROR] Pattern processor error: " . $e->getMessage());
            throw $e;
        }
        
        // Save new properties
        foreach ($results['properties'] as $property) {
            try {
                error_log("[NOTES_API_DEBUG] Processing property: " . json_encode($property));
                
                // Determine internal status based on property definitions
                try {
                    error_log("[NOTES_API_DEBUG] Determining internal status for property {$property['name']}");
                    $internal = determinePropertyInternalStatus($pdo, $property['name']);
                    error_log("[NOTES_API_DEBUG] Internal status determined: " . $internal);
                } catch (Exception $e) {
                    error_log("[NOTES_API_ERROR] Error determining internal status: " . $e->getMessage());
                    throw $e;
                }
                
                try {
                    error_log("[NOTES_API_DEBUG] Inserting property into database");
                    $stmt = $pdo->prepare("
                        INSERT INTO Properties (note_id, page_id, name, value, internal)
                        VALUES (?, NULL, ?, ?, ?)
                    ");
                    $stmt->execute([$noteId, $property['name'], $property['value'], $internal]);
                    error_log("[NOTES_API_DEBUG] Successfully inserted property");
                } catch (Exception $e) {
                    error_log("[NOTES_API_ERROR] Database error inserting property: " . $e->getMessage());
                    throw $e;
                }
                
                try {
                    error_log("[NOTES_API_DEBUG] Dispatching property triggers");
                    dispatchPropertyTriggers($pdo, 'note', $noteId, $property['name'], $property['value']);
                    error_log("[NOTES_API_DEBUG] Successfully dispatched triggers");
                } catch (Exception $e) {
                    error_log("[NOTES_API_ERROR] Error dispatching triggers: " . $e->getMessage());
                    // Don't re-throw trigger errors
                }
            } catch (Exception $e) {
                error_log("[NOTES_API_ERROR] Error processing property {$property['name']}: " . $e->getMessage());
                throw $e; // Re-throw to trigger transaction rollback
            }
        }
        
        error_log("[NOTES_API_DEBUG] Successfully saved all properties for note {$noteId}");
        
    } catch (Exception $e) {
        error_log("[NOTES_API_ERROR] Failed to save properties for note {$noteId}: " . $e->getMessage());
        error_log("[NOTES_API_ERROR] Stack trace: " . $e->getTraceAsString());
        throw $e; // Re-throw to be caught by the caller
    }
}

// Helper function to save page-level properties from note content
function savePagePropertiesFromContent($pdo, $pageId, $content) {
    try {
        $processor = getPatternProcessor();
        $results = $processor->processContent($content, 'page', $pageId);
        
        // Filter for page-level properties (currently only alias, but extensible)
        $pageProperties = [];
        foreach ($results['properties'] as $property) {
            // Currently only alias is a page-level property, but this can be extended
            if ($property['name'] === 'alias') {
                $pageProperties[] = [
                    'name' => $property['name'],
                    'value' => $property['value']
                ];
            }
        }
        
        // Save page properties
        foreach ($pageProperties as $property) {
            try {
                // Determine internal status based on property definitions
                $internal = determinePropertyInternalStatus($pdo, $property['name']);
                
                $stmt = $pdo->prepare("
                    REPLACE INTO Properties (page_id, note_id, name, value, internal)
                    VALUES (?, NULL, ?, ?, ?)
                ");
                $stmt->execute([$pageId, $property['name'], $property['value'], $internal]);
                
                // Dispatch triggers (this will trigger our alias handler)
                dispatchPropertyTriggers($pdo, 'page', $pageId, $property['name'], $property['value']);
            } catch (Exception $e) {
                error_log("[NOTES_API_ERROR] Error saving page property {$property['name']} for page {$pageId}: " . $e->getMessage());
                throw $e; // Re-throw to trigger transaction rollback
            }
        }
        
        error_log("[NOTES_API_DEBUG] Successfully saved page properties for page {$pageId}");
        
    } catch (Exception $e) {
        error_log("[NOTES_API_ERROR] Failed to save page properties for page {$pageId}: " . $e->getMessage());
        throw $e; // Re-throw to be caught by the caller
    }
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
        // Get notes for a page
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
            $propSql .= " ORDER BY name"; // Added order

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
        
        sendJsonResponse(['success' => true, 'data' => $notes]);
    } else {
        sendJsonResponse(['success' => false, 'error' => 'Either page_id or id is required'], 400);
    }
} elseif ($method === 'POST') {
    if (!isset($input['page_id'])) {
        sendJsonResponse(['success' => false, 'error' => 'Page ID is required'], 400);
    }
    
    validateNoteData($input);
    
    try {
        error_log("[NOTES_API_DEBUG] Starting note creation for page_id: " . $input['page_id']);
        error_log("[NOTES_API_DEBUG] Content: " . $input['content']);
        
        $pdo->beginTransaction();
        
        // Check for internal property in content
        $internalValueFromContent = null;
        $propertiesFromContent = parsePropertiesFromContent($input['content']);
        foreach ($propertiesFromContent as $prop) {
            if (strtolower($prop['name']) === 'internal') {
                $internalValueFromContent = (strtolower($prop['value']) === 'true' || $prop['value'] === '1') ? 1 : 0;
                break; // Use the first 'internal' property found
            }
        }
        
        // Get max order_index for the page
        $stmt = $pdo->prepare("SELECT MAX(order_index) as max_order FROM Notes WHERE page_id = ?");
        $stmt->execute([(int)$input['page_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $orderIndex = ($result['max_order'] ?? 0) + 1;
        
        error_log("[NOTES_API_DEBUG] Calculated order_index: " . $orderIndex);
        
        // Insert new note
        $insertSql = "
            INSERT INTO Notes (page_id, content, parent_note_id, order_index, internal, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ";
        $insertParams = [
            (int)$input['page_id'],
            $input['content'],
            isset($input['parent_note_id']) ? (int)$input['parent_note_id'] : null,
            $orderIndex,
            $internalValueFromContent !== null ? $internalValueFromContent : 0
        ];
        
        error_log("[NOTES_API_DEBUG] About to execute INSERT Notes with SQL: " . $insertSql);
        error_log("[NOTES_API_DEBUG] Parameters: " . json_encode($insertParams));
        
        $stmt = $pdo->prepare($insertSql);
        try {
            $stmt->execute($insertParams);
            
            // Check for errors immediately after execute
            $executeError = $stmt->errorInfo();
            if ($executeError[0] !== PDO::ERR_NONE && $executeError[0] !== '00000') {
                $error_message = "SQL Error after INSERT Notes: [{$executeError[0]}] {$executeError[2]}";
                error_log("[NOTES_API_ERROR] " . $error_message);
                error_log("[NOTES_API_ERROR] SQL State: " . $executeError[0]);
                error_log("[NOTES_API_ERROR] Error Code: " . $executeError[1]);
                error_log("[NOTES_API_ERROR] Error Message: " . $executeError[2]);
                
                // Try to get more info about the FTS table state
                try {
                    $ftsCheck = $pdo->query("SELECT COUNT(*) as count FROM Notes_fts");
                    $ftsCount = $ftsCheck->fetch(PDO::FETCH_ASSOC)['count'];
                    error_log("[NOTES_API_DEBUG] Current Notes_fts count: " . $ftsCount);
                } catch (Exception $e) {
                    error_log("[NOTES_API_ERROR] Could not check Notes_fts: " . $e->getMessage());
                }
                
                throw new PDOException($error_message);
            }
            
            $noteId = $pdo->lastInsertId();
            error_log("[NOTES_API_DEBUG] Successfully inserted note with ID: " . $noteId);
            
            // Parse and save properties from note content
            try {
                error_log("[NOTES_API_DEBUG] Starting to save properties for note " . $noteId);
                $properties = parsePropertiesFromContent($input['content']);
                error_log("[NOTES_API_DEBUG] Parsed properties: " . json_encode($properties));
                savePropertiesForNote($pdo, $noteId, $input['content']);
                error_log("[NOTES_API_DEBUG] Successfully saved properties for note " . $noteId);
            } catch (Exception $e) {
                error_log("[NOTES_API_ERROR] Error saving properties for note " . $noteId . ": " . $e->getMessage());
                throw $e; // Re-throw to trigger transaction rollback
            }
            
            // Parse and save page properties from note content
            try {
                error_log("[NOTES_API_DEBUG] Starting to save page properties for page " . $input['page_id']);
                savePagePropertiesFromContent($pdo, (int)$input['page_id'], $input['content']);
                error_log("[NOTES_API_DEBUG] Successfully saved page properties for page " . $input['page_id']);
            } catch (Exception $e) {
                error_log("[NOTES_API_ERROR] Error saving page properties for page " . $input['page_id'] . ": " . $e->getMessage());
                throw $e; // Re-throw to trigger transaction rollback
            }
            
            // Fetch the created note
            $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
            $stmt->execute([$noteId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            $note['properties'] = [];
            
            // Fetch properties for the response
            $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
            $propSql = "SELECT name, value, internal FROM Properties WHERE note_id = :note_id";
            if (!$includeInternal) {
                $propSql .= " AND internal = 0";
            }
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
                // Always include internal flag in response objects
                $propEntry = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
                $note['properties'][$prop['name']][] = $propEntry;
            }
            
            // Only simplify to direct value if not including internal and single non-internal value
            foreach ($note['properties'] as $name => $values) {
                if (count($values) === 1 && !$includeInternal && $values[0]['internal'] === 0) {
                    $note['properties'][$name] = $values[0]['value'];
                }
            }
            
            $pdo->commit();
            error_log("[NOTES_API_DEBUG] Successfully committed transaction for note " . $noteId);
            sendJsonResponse(['success' => true, 'data' => $note]);
            
        } catch (PDOException $e) {
            error_log("[NOTES_API_ERROR] PDO Exception during note creation: " . $e->getMessage());
            error_log("[NOTES_API_ERROR] SQL State: " . $e->getCode());
            error_log("[NOTES_API_ERROR] Trace: " . $e->getTraceAsString());
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendJsonResponse(['success' => false, 'error' => 'Database error during note creation: ' . $e->getMessage()], 500);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("[NOTES_API_ERROR] Unexpected error during note creation: " . $e->getMessage());
        error_log("[NOTES_API_ERROR] Trace: " . $e->getTraceAsString());
        sendJsonResponse(['success' => false, 'error' => 'Failed to create note: ' . $e->getMessage()], 500);
    }
} elseif ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        sendJsonResponse(['success' => false, 'error' => 'Note ID is required'], 400);
    }
    
    if (isset($input['content'])) {
        validateNoteData($input, false); // content is not strictly required, but validate if present
    }

    try {
        error_log("[NOTES_API_DEBUG] Starting PUT request for note update");
        error_log("[NOTES_API_DEBUG] Input data: " . json_encode($input));
        
        $pdo->beginTransaction(); // Start transaction for the main note update

        $noteId = (int)$_GET['id'];
        error_log("[NOTES_API_DEBUG] Processing update for note {$noteId}");

        // Check if note exists
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $existingNote = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingNote) {
            error_log("[NOTES_API_ERROR] Note {$noteId} not found");
            $pdo->rollBack(); // Rollback if note not found
            sendJsonResponse(['success' => false, 'error' => 'Note not found'], 404);
        }
        
        error_log("[NOTES_API_DEBUG] Found existing note: " . json_encode($existingNote));
        
        // --- Build and execute the main UPDATE Notes statement ---
        $setClauses = [];
        $executeParams = [];

        $internalValueFromContent = null; // Variable to hold internal status from content

        if (isset($input['content'])) {
            error_log("[NOTES_API_DEBUG] Processing content update: " . $input['content']);
            $setClauses[] = "content = ?";
            $executeParams[] = $input['content'];

            // Parse properties from content to find 'internal' status
            try {
                error_log("[NOTES_API_DEBUG] Parsing properties from content");
                $propertiesFromContent = parsePropertiesFromContent($input['content']);
                error_log("[NOTES_API_DEBUG] Parsed properties: " . json_encode($propertiesFromContent));
                
                foreach ($propertiesFromContent as $prop) {
                    if (strtolower($prop['name']) === 'internal') {
                        $internalValueFromContent = (strtolower($prop['value']) === 'true' || $prop['value'] === '1') ? 1 : 0;
                        error_log("[NOTES_API_DEBUG] Found internal property: " . $internalValueFromContent);
                        break;
                    }
                }
            } catch (Exception $e) {
                error_log("[NOTES_API_ERROR] Error parsing properties: " . $e->getMessage());
                error_log("[NOTES_API_ERROR] Stack trace: " . $e->getTraceAsString());
                throw $e;
            }
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

        // Add internal status from content to the main update query if found
        if ($internalValueFromContent !== null) {
            $setClauses[] = "internal = ?";
            $executeParams[] = $internalValueFromContent;
        }

        if (empty($setClauses)) {
            $pdo->rollBack(); // Rollback if nothing to update
            sendJsonResponse(['success' => false, 'error' => 'No updateable fields provided'], 400);
            return; // Exit early
        }

        $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
        
        $sql = "UPDATE Notes SET " . implode(", ", $setClauses) . " WHERE id = ?";
        $executeParams[] = $noteId;
        
        error_log("[NOTES_API_DEBUG] About to execute UPDATE Notes with SQL: " . $sql);
        error_log("[NOTES_API_DEBUG] Parameters: " . json_encode($executeParams));
        
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute($executeParams);
            
            // Check for errors immediately after execute
            $executeError = $stmt->errorInfo();
            if ($executeError[0] !== PDO::ERR_NONE && $executeError[0] !== '00000') {
                $error_message = "SQL Error after UPDATE Notes: [{$executeError[0]}] {$executeError[2]}";
                error_log("[NOTES_API_ERROR] " . $error_message);
                error_log("[NOTES_API_ERROR] SQL State: " . $executeError[0]);
                error_log("[NOTES_API_ERROR] Error Code: " . $executeError[1]);
                error_log("[NOTES_API_ERROR] Error Message: " . $executeError[2]);
                
                // Try to get more info about the FTS table state
                try {
                    $ftsCheck = $pdo->query("SELECT COUNT(*) as count FROM Notes_fts");
                    $ftsCount = $ftsCheck->fetch(PDO::FETCH_ASSOC)['count'];
                    error_log("[NOTES_API_DEBUG] Current Notes_fts count: " . $ftsCount);
                } catch (Exception $e) {
                    error_log("[NOTES_API_ERROR] Could not check Notes_fts: " . $e->getMessage());
                }
                
                $pdo->rollBack(); // Rollback before sending response
                sendJsonResponse(['success' => false, 'error' => $error_message], 500);
                return; // Exit early
            }
            
            // Log successful update
            error_log("[NOTES_API_DEBUG] Successfully executed UPDATE Notes for note {$noteId}");
            
            // --- Save properties within the same transaction ---
            if (isset($input['content'])) {
                try {
                    error_log("[NOTES_API_DEBUG] About to save properties for note {$noteId}");
                    error_log("[NOTES_API_DEBUG] Content for property processing: " . $input['content']);
                    
                    // First check if we can delete properties
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Properties WHERE note_id = ?");
                    $stmt->execute([$noteId]);
                    $propCount = $stmt->fetchColumn();
                    error_log("[NOTES_API_DEBUG] Found {$propCount} existing properties to delete");
                    
                    try {
                        savePropertiesForNote($pdo, $noteId, $input['content']);
                        error_log("[NOTES_API_DEBUG] Successfully saved properties for note {$noteId}");
                    } catch (Exception $e) {
                        error_log("[NOTES_API_ERROR] Error in savePropertiesForNote: " . $e->getMessage());
                        error_log("[NOTES_API_ERROR] Stack trace: " . $e->getTraceAsString());
                        throw $e;
                    }
                    
                    // Verify properties were saved
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Properties WHERE note_id = ?");
                    $stmt->execute([$noteId]);
                    $newPropCount = $stmt->fetchColumn();
                    error_log("[NOTES_API_DEBUG] New property count: {$newPropCount}");
                    
                } catch (Exception $e) {
                    error_log("[NOTES_API_ERROR] Error saving note properties: " . $e->getMessage());
                    error_log("[NOTES_API_ERROR] Stack trace: " . $e->getTraceAsString());
                    throw $e;
                }

                try {
                    error_log("[NOTES_API_DEBUG] About to save page properties for page {$existingNote['page_id']}");
                    savePagePropertiesFromContent($pdo, (int)$existingNote['page_id'], $input['content']);
                    error_log("[NOTES_API_DEBUG] Successfully saved page properties");
                } catch (Exception $e) {
                    error_log("[NOTES_API_ERROR] Error saving page properties: " . $e->getMessage());
                    error_log("[NOTES_API_ERROR] Stack trace: " . $e->getTraceAsString());
                    throw $e; // Re-throw to trigger transaction rollback
                }
            }
            
            // --- Fetch the updated note data for the response ---
            $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
            $stmt->execute([$noteId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);

            // --- Get properties (respecting include_internal - defaulting to false for PUT response) ---
            $includeInternal = filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
            $propSql = "SELECT name, value, internal FROM Properties WHERE note_id = :note_id";
            if (!$includeInternal) {
                $propSql .= " AND internal = 0";
            }
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
                // Always include internal flag in response objects
                $propEntry = ['value' => $prop['value'], 'internal' => (int)$prop['internal']];
                $note['properties'][$prop['name']][] = $propEntry;
            }

            // Only simplify to direct value if not including internal and single non-internal value
            foreach ($note['properties'] as $name => $values) {
                if (count($values) === 1 && !$includeInternal && $values[0]['internal'] === 0) {
                    $note['properties'][$name] = $values[0]['value'];
                }
            }

            // --- Commit the entire transaction ---
            $pdo->commit();
            sendJsonResponse(['success' => true, 'data' => $note]);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("[NOTES_API_ERROR] PDO Exception during note update: " . $e->getMessage());
            error_log("[NOTES_API_ERROR] SQL State: " . $e->getCode());
            error_log("[NOTES_API_ERROR] Trace: " . $e->getTraceAsString());
            sendJsonResponse(['success' => false, 'error' => 'Database error during update: ' . $e->getMessage()], 500);
            return;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("[NOTES_API_ERROR] Unexpected error during note update: " . $e->getMessage());
            error_log("[NOTES_API_ERROR] Trace: " . $e->getTraceAsString());
            sendJsonResponse(['success' => false, 'error' => 'Failed to update note: ' . $e->getMessage()], 500);
            return;
        }
    } catch (PDOException $e) {
        // This catch block now primarily handles errors from the main note update transaction
        if ($pdo->inTransaction()) {
             $pdo->rollBack(); // Ensure rollback if an error occurred before commit
        }
        $error_detail = 'Failed to update note: ' . $e->getMessage();
        if (method_exists($e, 'errorInfo') && $e->errorInfo !== null && isset($e->errorInfo[1]) && isset($e->errorInfo[2])) {
            $error_detail .= " | SQL Error [" . $e->errorInfo[1] . "]: " . $e->errorInfo[2];
        }
        error_log("[NOTES_API_ERROR] PUT Note (Main Update): " . $error_detail . " | Trace: " . $e->getTraceAsString()); // Server-side log
        sendJsonResponse(['success' => false, 'error' => $error_detail], 500); // Send detailed error to client for debugging
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
