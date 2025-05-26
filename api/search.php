<?php
// Simulate GET request for CLI execution
if (php_sapi_name() == 'cli') {
    $_SERVER['REQUEST_METHOD'] = 'GET'; // Force request method for CLI
    // Parse command line arguments into $_GET
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $_GET[$key] = $value;
            }
        }
    }
}

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
    
    // Search using FTS5
    // The fts.rank column is implicitly available from FTS5 and indicates relevance.
    $sql = '
        SELECT n.id, n.content, n.page_id, n.block_id, n.created_at, n.updated_at,
               p.title as page_title, 
               (SELECT GROUP_CONCAT(prop.property_key || ":" || prop.property_value) 
                FROM properties prop 
                WHERE prop.note_id = n.id AND prop.page_id IS NULL) as properties_concat,
               fts.rank -- Include FTS rank for ordering
        FROM notes_fts fts
        JOIN notes n ON fts.note_id = n.id
        JOIN pages p ON n.page_id = p.id
        WHERE fts.notes_fts MATCH :query -- Match against the FTS table virtual column
        GROUP BY n.id -- Group by note ID to ensure unique notes if properties join duplicates
        ORDER BY fts.rank, n.updated_at DESC -- Order by relevance, then by update date
        LIMIT 50
    ';
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        // Fallback or error handling if FTS query preparation fails
        // For now, let's log and return empty, or could throw an exception.
        error_log("Failed to prepare FTS search statement: " . $db->lastErrorMsg());
        // Potentially fallback to LIKE search if FTS not available/working
        // For this task, we assume FTS is the primary path.
        return [];
    }
    
    // For FTS MATCH, we don't use '%' wildcards like in LIKE
    $stmt->bindValue(':query', $query, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    if (!$result) {
        error_log("Failed to execute FTS search: " . $db->lastErrorMsg());
        return [];
    }
    
    $notes = [];
    while ($note = $result->fetchArray(SQLITE3_ASSOC)) {
        // Parse properties
        $properties = [];
        if (isset($note['properties_concat']) && $note['properties_concat']) {
            foreach (explode(',', $note['properties_concat']) as $propPair) {
                // Ensure there's a colon before splitting
                if (strpos($propPair, ':') !== false) {
                    list($key, $value) = explode(':', $propPair, 2);
                    $properties[$key] = $value;
                }
            }
        }
        $note['properties'] = $properties;
        unset($note['properties_concat']); // Clean up the concatenated string
        // The 'rank' column is available in $note if needed, but not explicitly used in the final $note structure here.
        
        // Add context (parent notes)
        // The getNoteContext function signature was updated in a previous subtask
        // getNoteContext($noteId, $maxDepth = 5)
        $context = getNoteContext($note['id'], 3); // Fetch up to 3 levels of parent context
        $note['context'] = $context;
        
        $notes[] = $note;
    }
    
    return $notes;
}

function getNoteContext($noteId, $maxDepth = 5) { // Signature from previous subtask
    global $db;
    
    $context = [];
    $currentNoteIdForParentLookup = $noteId; 
    $currentDepth = 0;
    
    while ($currentNoteIdForParentLookup && $currentDepth < $maxDepth) {
        $stmtParentId = $db->prepare('SELECT parent_id FROM notes WHERE id = :id');
        if (!$stmtParentId) {
            error_log("Failed to prepare parent_id lookup: " . $db->lastErrorMsg());
            break;
        }
        $stmtParentId->bindValue(':id', $currentNoteIdForParentLookup, SQLITE3_INTEGER);
        $parentIdResult = $stmtParentId->execute();
        $parentRow = $parentIdResult->fetchArray(SQLITE3_ASSOC);
        $stmtParentId->close(); 

        if (!$parentRow || $parentRow['parent_id'] === null) {
            break; 
        }
        
        $parentId = $parentRow['parent_id'];

        $stmtParentDetails = $db->prepare('
            SELECT id, content
            FROM notes
            WHERE id = :parent_id
        ');
        if (!$stmtParentDetails) {
            error_log("Failed to prepare parent_details lookup: " . $db->lastErrorMsg());
            break;
        }
        $stmtParentDetails->bindValue(':parent_id', $parentId, SQLITE3_INTEGER);
        $parentDetailsResult = $stmtParentDetails->execute();
        $parentNote = $parentDetailsResult->fetchArray(SQLITE3_ASSOC);
        $stmtParentDetails->close(); 
        
        if (!$parentNote) {
            break; 
        }
        
        array_unshift($context, [
            'id' => $parentNote['id'],
            'content' => $parentNote['content']
        ]);
        
        $currentNoteIdForParentLookup = $parentId; 
        $currentDepth++;
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