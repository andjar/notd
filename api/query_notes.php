<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); // Do not display errors to the client
ini_set('log_errors', 1);     // Log errors
ini_set('error_log', __DIR__.'/../php-error.log'); // Specify error log file

header('Content-Type: application/json');

// Custom error handler to convert PHP errors to ErrorExceptions
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        // This error code is not included in error_reporting
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$db = null; // Initialize $db to null for the finally block

try {
    // Connect to the database
    $dbPath = __DIR__ . '/../db/notes.db';
    $db = new SQLite3($dbPath);

    if (!$db) {
        throw new Exception("Could not connect to the database.");
    }

    // Set a busy timeout of 5 seconds
    $db->busyTimeout(5000);

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
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'No SQL query provided.']);
        exit;
    }

    // Prepare and execute the SQL query
    // SECURITY NOTE: Directly executing user-provided SQL is dangerous.
    // This is done here based on the assumption of a trusted user environment.
    $stmt = $db->prepare($sqlQuery);
    if (!$stmt) {
        throw new Exception("Failed to prepare SQL query: " . $db->lastErrorMsg());
    }

    $result = $stmt->execute();
    if (!$result) {
        throw new Exception("Failed to execute SQL query: " . $db->lastErrorMsg());
    }

    $notes = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Sanitize or process row data if necessary, though SQL output is generally direct.
        // Example: Convert boolean-like integers to actual booleans if needed by the frontend.
        // foreach ($row as $key => $value) {
        //     if ($value === '0' || $value === '1') { // Example: Convert string '0'/'1' to int 0/1
        //         $row[$key] = (int)$value;
        //     }
        // }
        $notes[] = $row;
    }

    echo json_encode($notes);

} catch (ErrorException $e) {
    http_response_code(500);
    error_log("ErrorException in query_notes.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode(['error' => 'A server error occurred: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error for general exceptions
    // Check if it's a "Bad Request" type error based on the message
    if (strpos($e->getMessage(), 'No SQL query provided') !== false || strpos($e->getMessage(), 'Failed to prepare SQL query') !== false) {
        http_response_code(400); // Bad Request for specific query errors
    }
    error_log("Exception in query_notes.php: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    if ($stmt ?? null) {
        $stmt->close();
    }
    if ($db) {
        $db->close();
    }
}

?>
