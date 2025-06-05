<?php
require_once 'db_connect.php';
require_once 'property_triggers.php';

header('Content-Type: application/json');
$pdo = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Handle method overriding for PUT via POST (e.g., for phpdesktop)
if ($method === 'POST' && isset($input['_method']) && strtoupper($input['_method']) === 'PUT') {
    $method = 'PUT';
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

// Helper function to parse properties from note content
function parsePropertiesFromContent($content) {
    $properties = [];
    // Match {property::value} patterns (existing logic)
    if (preg_match_all('/\{([^:]+)::([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $propertyName = trim($match[1]);
            $propertyValue = trim($match[2]);
            if (!empty($propertyName) && !empty($propertyValue)) {
                // Allow multiple properties with the same name (e.g. multiple tags)
                $properties[] = [
                    'name' => $propertyName,
                    'value' => $propertyValue
                ];
            }
        }
    }

    // Match [[PageName]] patterns for 'links_to_page'
    $linkedPages = []; // To track unique page names for links
    if (preg_match_all('/\[\[([^\]]+)\]\]/', $content, $linkMatches, PREG_SET_ORDER)) {
        foreach ($linkMatches as $linkMatch) {
            $pageName = trim($linkMatch[1]);
            // Avoid adding empty page names or self-references like [[]]
            if (!empty($pageName) && !in_array($pageName, $linkedPages)) {
                $properties[] = [
                    'name' => 'links_to_page',
                    'value' => $pageName
                ];
                $linkedPages[] = $pageName; // Add to tracker
            }
        }
    }
    return $properties;
}

// Helper function to save properties for a note
function savePropertiesForNote($pdo, $noteId, $properties) {
    foreach ($properties as $property) {
        try {
            // Save the property
            $stmt = $pdo->prepare("
                REPLACE INTO Properties (note_id, page_id, name, value, internal)
                VALUES (?, NULL, ?, ?, 0)
            ");
            $stmt->execute([$noteId, $property['name'], $property['value']]);
            
            // Dispatch triggers
            dispatchPropertyTriggers($pdo, 'note', $noteId, $property['name'], $property['value']);
        } catch (Exception $e) {
            error_log("Error saving property {$property['name']} for note {$noteId}: " . $e->getMessage());
        }
    }
}

// Helper function to save page-level properties from note content
function savePagePropertiesFromContent($pdo, $pageId, $content) {
    // Parse page-level properties (like alias) from content
    $pageProperties = [];
    if (preg_match_all('/\{(alias)::([^}]+)\}/', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $propertyName = trim($match[1]);
            $propertyValue = trim($match[2]);
            if (!empty($propertyName) && !empty($propertyValue)) {
                $pageProperties[] = [
                    'name' => $propertyName,
                    'value' => $propertyValue
                ];
            }
        }
    }
    
    // Save page properties
    foreach ($pageProperties as $property) {
        try {
            // Save the property to the page
            $stmt = $pdo->prepare("
                REPLACE INTO Properties (page_id, note_id, name, value, internal)
                VALUES (?, NULL, ?, ?, 0)
            ");
            $stmt->execute([$pageId, $property['name'], $property['value']]);
            
            // Dispatch triggers (this will trigger our alias handler)
            dispatchPropertyTriggers($pdo, 'page', $pageId, $property['name'], $property['value']);
        } catch (Exception $e) {
            error_log("Error saving page property {$property['name']} for page {$pageId}: " . $e->getMessage());
        }
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
        
        // START NEW LOGIC FOR GET ?page_id={id}

        // 1. Fetch page details
        $pageSql = "SELECT * FROM Pages WHERE id = :page_id";
        // No internal check for pages table itself, assuming pages are always fetchable if they exist.
        // Visibility of a page would be controlled by other means if necessary (e.g., user permissions, not relevant here).
        $stmtPage = $pdo->prepare($pageSql);
        $stmtPage->bindParam(':page_id', $pageId, PDO::PARAM_INT);
        $stmtPage->execute();
        $pageDetails = $stmtPage->fetch(PDO::FETCH_ASSOC);

        if (!$pageDetails) {
            sendJsonResponse(['success' => false, 'error' => 'Page not found'], 404);
            return; // Exit
        }

        // 2. Fetch page properties
        $pageProperties = [];
        $pagePropSql = "SELECT name, value, internal FROM Properties WHERE page_id = :page_id AND note_id IS NULL"; // Ensure note_id IS NULL for page-specific properties
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
        sendJsonResponse([
            'success' => true,
            'data' => [
                'page' => $pageDetails,
                'notes' => $notes // $notes is from the original part of the page_id block
            ]
        ]);
        // END NEW LOGIC FOR GET ?page_id={id}

    } else {
        sendJsonResponse(['success' => false, 'error' => 'Either page_id or id is required for GET request'], 400); // Clarified error
    }
} elseif ($method === 'POST') {
    if (!isset($input['page_id'])) {
        sendJsonResponse(['success' => false, 'error' => 'Page ID is required'], 400);
    }
    
    validateNoteData($input);
    
    try {
        $pdo->beginTransaction();
        
        // Get max order_index for the page
        $stmt = $pdo->prepare("SELECT MAX(order_index) as max_order FROM Notes WHERE page_id = ?");
        $stmt->execute([(int)$input['page_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $orderIndex = ($result['max_order'] ?? 0) + 1;
        
        // Insert new note
        $stmt = $pdo->prepare("
            INSERT INTO Notes (page_id, content, parent_note_id, order_index, internal, created_at, updated_at)
            VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) 
        "); // Added 'internal' column with default 0
        $stmt->execute([
            (int)$input['page_id'],
            $input['content'],
            isset($input['parent_note_id']) ? (int)$input['parent_note_id'] : null,
            $orderIndex
        ]);
        
        $noteId = $pdo->lastInsertId();
        
        // Parse and save properties from note content
        $properties = parsePropertiesFromContent($input['content']);
        savePropertiesForNote($pdo, $noteId, $properties);
        
        // Parse and save page properties from note content
        savePagePropertiesFromContent($pdo, (int)$input['page_id'], $input['content']);
        
        // Fetch the created note
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        $note['properties'] = [];
        
        $pdo->commit();
        sendJsonResponse(['success' => true, 'data' => $note]);
    } catch (PDOException $e) {
        $pdo->rollBack();
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
        
        // If content was updated, parse and save properties
        if (isset($input['content'])) {
            // First, delete existing non-internal properties for this note
            $stmtDeleteProps = $pdo->prepare("DELETE FROM Properties WHERE note_id = ? AND internal = 0");
            $stmtDeleteProps->execute([$noteId]);
            
            // Parse and save new properties from updated content
            $properties = parsePropertiesFromContent($input['content']);
            savePropertiesForNote($pdo, $noteId, $properties);
            
            // Parse and save page properties from updated content
            savePagePropertiesFromContent($pdo, (int)$existingNote['page_id'], $input['content']);
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