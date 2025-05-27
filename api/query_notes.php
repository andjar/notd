<?php

require_once __DIR__ . '/api_common.php';

// Prevent any output before JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);

handle_api_request_start();

$db = null; // Initialize $db to null

try {
    $db = get_db_connection();

    $sqlQuery = null;

    // Retrieve SQL query from POST or GET
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['query'])) {
            $sqlQuery = $input['query'];
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['query'])) {
            $sqlQuery = $_GET['query'];
        }
    }

    if (empty($sqlQuery)) {
        throw new InvalidArgumentException("SQL query cannot be empty.", 400);
    }

    // Validate that this is a SELECT query
    $trimmedQuery = trim($sqlQuery);
    if (!preg_match('/^SELECT\s+/i', $trimmedQuery)) {
        throw new InvalidArgumentException("Only SELECT queries are allowed.", 400);
    }

    // Execute the query and get results
    $results = execute_select_query($db, $sqlQuery);

    // Ensure we have a valid array to send
    if (!is_array($results)) {
        $results = [];
    }

    // Send the response using the common function
    send_json_response($results);

} catch (Throwable $e) {
    // Use the common error handler
    handle_api_error($e, $db);
} finally {
    if ($db) {
        $db->close();
    }
}

?>
