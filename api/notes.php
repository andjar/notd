<?php
require_once 'db_connect.php';

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

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        // Get single note
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($note) {
            // Get properties for the note
            $stmt = $pdo->prepare("SELECT * FROM Properties WHERE note_id = ?");
            $stmt->execute([$note['id']]);
            $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add properties to note data
            $note['properties'] = array_reduce($properties, function($acc, $prop) {
                $acc[$prop['name']] = $prop['value'];
                return $acc;
            }, []);
            
            sendJsonResponse(['success' => true, 'data' => $note]);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Note not found'], 404);
        }
    } elseif (isset($_GET['page_id'])) {
        // Get notes for a page
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE page_id = ? ORDER BY order_index ASC");
        $stmt->execute([(int)$_GET['page_id']]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get properties for all notes
        $noteIds = array_column($notes, 'id');
        
        // Initialize properties for all notes to empty arrays first
        foreach ($notes as &$note) {
            $note['properties'] = [];
        }
        unset($note); // Unset reference to last element

        if (!empty($noteIds)) {
            $placeholders = str_repeat('?,', count($noteIds) - 1) . '?';
            $stmt = $pdo->prepare("SELECT * FROM Properties WHERE note_id IN ($placeholders)");
            $stmt->execute($noteIds);
            $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group properties by note_id
            $propertiesByNote = [];
            foreach ($properties as $prop) {
                if (!isset($propertiesByNote[$prop['note_id']])) {
                    $propertiesByNote[$prop['note_id']] = [];
                }
                $propertiesByNote[$prop['note_id']][$prop['name']] = $prop['value'];
            }
            
            // Add properties to notes
            foreach ($notes as &$note) {
                if (isset($propertiesByNote[$note['id']])) {
                    $note['properties'] = $propertiesByNote[$note['id']];
                }
            }
            unset($note); // Unset reference to last element
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
        $pdo->beginTransaction();
        
        // Get max order_index for the page
        $stmt = $pdo->prepare("SELECT MAX(order_index) as max_order FROM Notes WHERE page_id = ?");
        $stmt->execute([(int)$input['page_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $orderIndex = ($result['max_order'] ?? 0) + 1;
        
        // Insert new note
        $stmt = $pdo->prepare("
            INSERT INTO Notes (page_id, content, parent_note_id, order_index, created_at, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            (int)$input['page_id'],
            $input['content'],
            isset($input['parent_note_id']) ? (int)$input['parent_note_id'] : null,
            $orderIndex
        ]);
        
        $noteId = $pdo->lastInsertId();
        
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
        
        // Fetch updated note
        $stmt = $pdo->prepare("SELECT * FROM Notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get properties
        $stmt = $pdo->prepare("SELECT * FROM Properties WHERE note_id = ?"); // Corrected table and column names
        $stmt->execute([$note['id']]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $note['properties'] = array_reduce($properties, function($acc, $prop) {
            $acc[$prop['name']] = $prop['value'];
            return $acc;
        }, []);
        
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