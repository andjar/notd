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
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable displaying errors to the client
ini_set('log_errors', 1);
error_log(__DIR__ . '/../logs/php_errors.log');

$db = null; // Initialize db variable

try {
    $db = new SQLite3(__DIR__ . '/../db/notes.db');
    if (!$db) {
        throw new Exception('Failed to connect to database: ' . SQLite3::lastErrorMsg());
    }
    $db->busyTimeout(5000);
    if (!$db->exec('PRAGMA foreign_keys = ON;')) {
        error_log("Notice: Attempted to enable foreign_keys for batch_blocks.php. Check SQLite logs if issues persist with FKs.");
    }

    if (!isset($_GET['ids']) || empty($_GET['ids'])) {
        echo json_encode([]); // Return empty JSON for no IDs
        exit;
    }

    $idsString = $_GET['ids'];
    // Sanitize IDs: split by comma, trim whitespace, remove empty values
    $blockIds = array_filter(array_map('trim', explode(',', $idsString)), function($id) {
        return !empty($id) && preg_match('/^[a-zA-Z0-9_-]+$/', $id); // Basic validation for block_id format
    });

    if (empty($blockIds)) {
        echo json_encode([]); // Return empty JSON if no valid IDs after sanitization
        exit;
    }

    // Create placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($blockIds), '?'));

    $sql = "
        SELECT n.id AS note_id, n.content, n.block_id, n.page_id AS note_page_id, p.id AS page_id, p.title AS page_title
        FROM notes n
        JOIN pages p ON n.page_id = p.id
        WHERE n.block_id IN ($placeholders)
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $db->lastErrorMsg());
    }

    // Bind each ID to the statement
    foreach ($blockIds as $index => $id) {
        // SQLite parameters are 1-indexed
        $stmt->bindValue($index + 1, $id, SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    if (!$result) {
        throw new Exception('Failed to execute statement: ' . $db->lastErrorMsg());
    }

    $response = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Key the response by block_id for easy lookup on the client-side
        $response[$row['block_id']] = [
            'note_id' => $row['note_id'],
            'content' => $row['content'],
            'page_id' => $row['page_id'], // This is the page_id from the pages table (p.id)
            'page_title' => $row['page_title']
        ];
    }

    // For any requested block_ids not found, they will simply be absent in the response.
    // This is acceptable as per the requirements ("omitted from the response").

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in batch_blocks.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
} finally {
    if ($db) {
        $db->close();
    }
}
?>
