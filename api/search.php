<?php
header('Content-Type: application/json');

// Set error handling for this script (consistent with others)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Errors should be logged, not displayed for API
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // Consistent error logging

$db = new SQLite3(__DIR__ . '/../db/notes.db'); // Use absolute path
if (!$db) {
    // Handle error immediately if connection fails
    error_log("search.php: Failed to connect to database: " . SQLite3::lastErrorMsg());
    echo json_encode(['error' => 'Failed to connect to database.']);
    exit;
}
$db->busyTimeout(5000); // Set busy timeout to 5000 milliseconds (5 seconds)

// Enable foreign key constraints for this connection
if (!$db->exec('PRAGMA foreign_keys = ON;')) {
    error_log("Notice: Attempted to enable foreign_keys for search.php. Check SQLite logs if issues persist with FKs.");
}

function searchNotes($query) {
    global $db;
    
    // Search in notes content
    $stmt = $db->prepare('
        SELECT n.*, p.title as page_title, p.id as page_id,
               GROUP_CONCAT(prop.property_key || ":" || prop.property_value) as properties
        FROM notes n
        JOIN pages p ON n.page_id = p.id
        LEFT JOIN properties prop ON n.id = prop.note_id
        WHERE n.content LIKE :query
        GROUP BY n.id
        ORDER BY n.updated_at DESC
        LIMIT 50
    ');
    
    $stmt->bindValue(':query', '%' . $query . '%', SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $notes = [];
    while ($note = $result->fetchArray(SQLITE3_ASSOC)) {
        // Parse properties
        $properties = [];
        if ($note['properties']) {
            foreach (explode(',', $note['properties']) as $prop) {
                list($key, $value) = explode(':', $prop, 2);
                $properties[$key] = $value;
            }
        }
        $note['properties'] = $properties;
        
        // Add context (parent notes)
        $context = getNoteContext($note['id']);
        $note['context'] = $context;
        
        $notes[] = $note;
    }
    
    return $notes;
}

function getNoteContext($noteId) {
    global $db;
    
    $context = [];
    $currentId = $noteId;
    
    while ($currentId) {
        $stmt = $db->prepare('
            SELECT id, content, parent_id
            FROM notes
            WHERE id = :id
        ');
        
        $stmt->bindValue(':id', $currentId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $note = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$note) break;
        
        array_unshift($context, [
            'id' => $note['id'],
            'content' => $note['content']
        ]);
        
        $currentId = $note['parent_id'];
    }
    
    return $context;
}

// Handle the request
$method = $_SERVER['REQUEST_METHOD'];
$query = $_GET['q'] ?? '';

if ($method !== 'GET') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (strlen($query) < 2) {
    echo json_encode(['error' => 'Search query too short']);
    exit;
}

echo json_encode(searchNotes($query));

$db->close();
?> 