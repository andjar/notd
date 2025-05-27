<?php

require_once __DIR__ . '/api_common.php';

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
        // Let handle_api_error manage the response and exit
        throw new InvalidArgumentException("SQL query cannot be empty.", 400);
    }

    // SECURITY NOTE: Directly executing user-provided SQL is dangerous.
    // This is done here based on the assumption of a trusted user environment.
    // The execute_select_query function itself does not sanitize the SQL string,
    // but can handle parameterized queries if params were passed (not in this case).
    $results = execute_select_query($db, $sqlQuery);

    send_json_response($results);

} catch (Throwable $e) {
    // $db might be null if get_db_connection() failed.
    // handle_api_error will attempt to close $db if it's not null.
    // It also exits the script.
    handle_api_error($e, $db);
} finally {
    // This finally block will only be reached if no exception occurred in the try block,
    // or if an exception occurred and handle_api_error did NOT exit (which it does).
    // So, this is primarily for closing the DB on successful execution.
    // If get_db_connection() failed, $db would be null.
    // If an error occurs after $db is established, handle_api_error closes it.
    if ($db && !(isset($e))) { // Only close if $db is set and no exception was caught and handled by handle_api_error
        $db->close();
    }
}

?>
