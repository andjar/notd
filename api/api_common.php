<?php

declare(strict_types=1);

/**
 * Initializes API request environment: output buffering, error handling, and content type.
 */
function handle_api_request_start(): void {
    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    // Ensure the logs directory exists
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        // Attempt to create it if it doesn't exist
        if (!mkdir($log_dir, 0755, true) && !is_dir($log_dir)) {
            // Log to default error log if directory creation fails
            error_log("Warning: Log directory '{$log_dir}' does not exist and could not be created. Falling back to default error log.");
            ini_set('error_log', 'php_error.log'); // Fallback or default
        } else {
             ini_set('error_log', $log_dir . '/php_errors.log');
        }
    } else {
        ini_set('error_log', $log_dir . '/php_errors.log');
    }
    

    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            // This error code is not included in error_reporting
            return false; // Don't execute PHP internal error handler
        }
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    header('Content-Type: application/json');
}

/**
 * Establishes and returns a SQLite3 database connection.
 *
 * @return SQLite3 The database connection object.
 * @throws Exception If the connection fails or PRAGMA statement fails.
 */
function get_db_connection(): SQLite3 {
    $db_path = __DIR__ . '/../db/notes.db';
    
    try {
        $db = new SQLite3($db_path);
    } catch (Exception $e) {
        // Catch potential exceptions from SQLite3 constructor (e.g., file not found, permissions)
        throw new Exception("Failed to connect to the database at '{$db_path}': " . $e->getMessage(), 0, $e);
    }

    // Additional check if $db object was created but connection still failed (less common for SQLite3)
    if (!$db) {
        throw new Exception("Failed to connect to the database at '{$db_path}'. SQLite3 object creation returned null.");
    }
    
    $db->busyTimeout(5000);

    // Enable foreign key constraints
    $pragma_result = $db->exec('PRAGMA foreign_keys = ON;');
    if ($pragma_result === false) {
        // Log a notice if PRAGMA fails, but don't necessarily throw an exception
        // as the DB connection itself might still be usable for other queries.
        error_log("Notice: Failed to execute 'PRAGMA foreign_keys = ON;'. Error: " . $db->lastErrorMsg());
    }

    return $db;
}

/**
 * Executes a SELECT SQL query and returns all results.
 *
 * @param SQLite3 $db The database connection object.
 * @param string $sql The SQL query to execute.
 * @param array $params Parameters to bind to the query.
 * @return array An array of associative arrays representing the query results.
 * @throws Exception If query preparation or execution fails.
 */
function execute_select_query(SQLite3 $db, string $sql, array $params = []): array {
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Failed to prepare SQL query: " . $db->lastErrorMsg() . " Query: " . $sql);
    }

    // Bind parameters
    foreach ($params as $key => $value) {
        $type = SQLITE3_TEXT; // Default to text
        if (is_int($value)) {
            $type = SQLITE3_INTEGER;
        } elseif (is_float($value)) {
            $type = SQLITE3_FLOAT;
        } elseif (is_null($value)) {
            $type = SQLITE3_NULL;
        } elseif (is_bool($value)) {
            $type = SQLITE3_INTEGER; // Store booleans as integers 0 or 1
            $value = (int)$value;
        }
        // For named parameters, use $key. For positional, use index (e.g., $key + 1 if 0-indexed array)
        $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
    }

    $result = $stmt->execute();
    if ($result === false) {
        $error_msg = $db->lastErrorMsg();
        $stmt->close(); // Attempt to close statement before throwing
        throw new Exception("Failed to execute SQL query: " . $error_msg . " Query: " . $sql);
    }

    $results = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $results[] = $row;
    }

    $stmt->close();
    return $results;
}

/**
 * Sends a JSON response with the given data and HTTP status code.
 *
 * @param mixed $data The data to encode as JSON.
 * @param int $http_status_code The HTTP status code to send (default is 200).
 */
function send_json_response($data, int $http_status_code = 200): void {
    if (headers_sent()) {
        error_log("Warning: Headers already sent. Cannot set HTTP status code to {$http_status_code}.");
    } else {
        http_response_code($http_status_code);
    }
    // Clear any previously buffered content if this is meant to be the final output
    // and ob_start was called.
    if (ob_get_length() > 0 && $http_status_code !== 200) { // Be cautious about clearing successful output
         // ob_clean(); // Decided against auto-cleaning here, should be handled by error handler or main script.
    }
    echo json_encode($data);
    // Consider calling ob_end_flush() or exit here if this function always terminates the script.
    // For now, it just sends the response.
}

/**
 * Handles API errors by logging the error, sending a JSON error response,
 * and attempting to close database resources.
 *
 * @param Throwable $e The throwable error/exception.
 * @param SQLite3|null $db An optional database connection object to close.
 * @param SQLite3Stmt|null $stmt An optional statement object to close.
 */
function handle_api_error(Throwable $e, ?SQLite3 $db = null, ?SQLite3Stmt $stmt = null): void {
    $error_message = $e->getMessage();
    $error_file = $e->getFile();
    $error_line = $e->getLine();
    
    error_log("API Error: {$error_message} in {$error_file} on line {$error_line}");
    // For more detailed logging, you could include $e->getTraceAsString()

    $status_code = 500; // Default to Internal Server Error

    if ($e instanceof InvalidArgumentException) { // Or any custom exception for client errors
        $status_code = 400; // Bad Request
    } elseif ($e instanceof ErrorException && str_contains($e->getMessage(), "ailed to connect")) { // Example specific error
        $status_code = 503; // Service Unavailable (e.g. DB down)
    } else {
        // Use exception code if it's a valid HTTP status code
        $exception_code = $e->getCode();
        if (is_int($exception_code) && $exception_code >= 400 && $exception_code < 600) {
            $status_code = $exception_code;
        }
    }
    
    // Clean any output buffer that might have started before the error occurred
    if (ob_get_length()) {
        ob_clean();
    }

    // Ensure headers are not already sent before trying to set new ones
    if (!headers_sent()) {
        http_response_code($status_code);
        // header('Content-Type: application/json'); // This should be set by handle_api_request_start
    }

    echo json_encode(['error' => $error_message]);

    // Attempt to close resources
    if ($stmt !== null) {
        try {
            $stmt->close();
        } catch (Exception $close_stmt_ex) {
            error_log("Error closing SQLite3Stmt: " . $close_stmt_ex->getMessage());
        }
    }
    if ($db !== null) {
        try {
            $db->close();
        } catch (Exception $close_db_ex) {
            error_log("Error closing SQLite3 connection: " . $close_db_ex->getMessage());
        }
    }
    
    // Terminate script execution after handling the error
    exit;
}

// No closing ?> tag is recommended for files containing only PHP code.
