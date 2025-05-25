<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

try {
    $db = new SQLite3('../db/notes.db');
    if (!$db) {
        throw new Exception('Failed to connect to database: ' . SQLite3::lastErrorMsg());
    }
    $db->busyTimeout(5000); // Set busy timeout to 5000 milliseconds (5 seconds)

    $id = $_GET['id'] ?? null;
    if (!$id) {
        throw new Exception('Block ID is required');
    }

    // Get the note with the given block_id
    $stmt = $db->prepare('
        SELECT n.*, p.title as page_title
        FROM notes n
        JOIN pages p ON n.page_id = p.id
        WHERE n.block_id = :block_id
    ');
    
    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . $db->lastErrorMsg());
    }
    
    $stmt->bindValue(':block_id', $id, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    if (!$result) {
        throw new Exception('Failed to execute query: ' . $db->lastErrorMsg());
    }
    
    $note = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$note) {
        throw new Exception('Block not found');
    }
    
    // Get note properties
    $stmt = $db->prepare('
        SELECT property_key, property_value
        FROM properties
        WHERE note_id = :note_id
    ');
    
    $stmt->bindValue(':note_id', $note['id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $properties = [];
    while ($prop = $result->fetchArray(SQLITE3_ASSOC)) {
        $properties[$prop['property_key']] = $prop['property_value'];
    }
    $note['properties'] = $properties;
    
    // Get attachments
    $stmt = $db->prepare('
        SELECT id, filename, original_name
        FROM attachments
        WHERE note_id = :note_id
    ');
    
    $stmt->bindValue(':note_id', $note['id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $attachments = [];
    while ($att = $result->fetchArray(SQLITE3_ASSOC)) {
        $attachments[] = $att;
    }
    $note['attachments'] = $attachments;
    
    echo json_encode($note);
} catch (Exception $e) {
    error_log("Error in block.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 