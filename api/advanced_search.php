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

    // Get the query from POST data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['query'])) {
        throw new Exception('No query provided');
    }

    $query = $input['query'];
    
    // Basic security check - only allow SELECT queries
    if (!preg_match('/^SELECT\s+/i', trim($query))) {
        throw new Exception('Only SELECT queries are allowed');
    }

    // Execute the query
    $result = $db->query($query);
    if (!$result) {
        throw new Exception('Query execution failed: ' . $db->lastErrorMsg());
    }

    // Fetch results
    $results = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $results[] = $row;
    }

    echo json_encode($results);
} catch (Exception $e) {
    error_log("Error in advanced_search.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 