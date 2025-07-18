<?php

// --- Core Paths and URLs ---
// Test override logic for DB_PATH
global $DB_PATH_OVERRIDE_FOR_TESTING; // For non-HTTP test scripts

if (isset($_GET['DB_PATH_OVERRIDE']) && is_string($_GET['DB_PATH_OVERRIDE'])) {
    // Ensure the path is somewhat sane; realpath for security/normalization
    $overridden_path = realpath($_GET['DB_PATH_OVERRIDE']);
    if ($overridden_path !== false) { // realpath returns false on failure (e.g. file doesn't exist)
                                     // However, for SQLite, the file might not exist yet if it's to be created.
                                     // So, we might accept the path directly if realpath fails but looks like a valid path.
        define('DB_PATH', $_GET['DB_PATH_OVERRIDE']); // Using the direct path from GET
    } else if (strpos($_GET['DB_PATH_OVERRIDE'], '.sqlite') !== false) { // Basic check if it looks like sqlite path
        define('DB_PATH', $_GET['DB_PATH_OVERRIDE']); // Trust the path if realpath fails but it seems intended
    } else {
        // Fallback if override path is problematic
        define('DB_PATH', __DIR__ . '/db/database.sqlite');
    }
} elseif (isset($DB_PATH_OVERRIDE_FOR_TESTING) && is_string($DB_PATH_OVERRIDE_FOR_TESTING)) {
    define('DB_PATH', $DB_PATH_OVERRIDE_FOR_TESTING);
} else {
    // Default production path
    if (!defined('DB_PATH')) { // Ensure it's not already defined by some other means
        define('DB_PATH', __DIR__ . '/db/database.sqlite');
    }
}

if (!defined('UPLOADS_DIR')) {
    define('UPLOADS_DIR', __DIR__ . '/uploads');
}
if (!defined('APP_BASE_URL')) {
    // Set this if your app is in a subdirectory, e.g., /notetaker
    // For a root domain, leave it empty.
    define('APP_BASE_URL', '');
}

// --- Application Features ---
define('ACTIVE_THEME', 'default'); // Defines the active theme CSS file.
define('WEBHOOKS_ENABLED', true); // Master switch to enable or disable all webhook dispatches.
define('ADVANCED_QUERY_MODE', false); // When true, disables security restrictions on the query API endpoint.
define('ACTIVE_EXTENSIONS', ['attachment_dashboard', 'pomodoro_timer', 'kanban_board']);
define('TASK_STATES', ['TODO', 'DOING', 'DONE', 'SOMEDAY', 'WAITING', 'CANCELLED']); // Allowed task states for the task status parser.

define('SPECIAL_STATE_WEIGHTS', [
    'SQL' => 3,
    'TASK' => 4,
    'DONE_AT' => 3,
    'TRANSCLUSION' => 3,
    'LINK' => 3,
    'URL' => 3
]);

// --- Property System Configuration ---
// This array defines the behavior of properties based on their 'weight', which
// is determined by the number of colons used in the property syntax.
// This configuration is primarily interpreted by the FRONTEND to control rendering.
// The backend uses 'update_behavior' to manage how the Properties table is updated.
define('PROPERTY_WEIGHTS', [
    // Default Public Property (e.g., {key::value})
    2 => [
        'label' => 'Public',
        'description' => 'Standard properties visible in all views.',
        'update_behavior' => 'replace', // On update, the old value in the DB is replaced with the new one.
        'visible_in_view_mode' => true,   // Frontend should show this in read-only views.
        'visible_in_edit_mode' => true    // Frontend should show this in editable views.
    ],
    // Internal Property (e.g., {key:::value})
    3 => [
        'label' => 'Internal',
        'description' => 'Properties for internal logic, hidden by default.',
        'update_behavior' => 'replace', // Replace the value on update.
        'visible_in_view_mode' => false,  // Changed from false to true
        'visible_in_edit_mode' => true    // Frontend should SHOW this in editable views.
    ],
    // System/Log Property (e.g., {key::::value})
    4 => [
        'label' => 'System Log',
        'description' => 'Properties that act as an immutable log or history.',
        'update_behavior' => 'append',  // On update, a NEW row is added to the DB, preserving the old one.
        'visible_in_view_mode' => false,  // Frontend should HIDE this in read-only views.
        'visible_in_edit_mode' => false   // Frontend should HIDE this in editable views.
    ]
    // You can add more weights here, e.g., weight 5 for "Archived" properties.
]);

// --- Development and Debugging ---
// WARNING: Do not use these settings in a production environment.
ini_set('display_errors', 1); // Enable error display
ini_set('display_startup_errors', 1); // Enable startup error display
error_reporting(E_ALL); // Report all PHP errors

// --- Timezone ---
// Set a consistent timezone to avoid issues with DATETIME functions.
date_default_timezone_set('UTC');

// --- Global Error and Exception Handling ---
// These handlers ensure that if a fatal error occurs, the API returns a
// structured JSON error response instead of a blank page or HTML error dump.

// Set custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Respect the error_reporting level.
    if (!(error_reporting() & $errno)) {
        return false;
    }

    // Only handle errors if headers haven't been sent yet.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'An internal server error occurred.',
            'details' => [
                'type' => 'PHP Error',
                'message' => $errstr,
                'file' => $errfile,
                'line' => $errline
            ]
        ]);
    } else {
        // Log the error if we can't send JSON.
        error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");
    }
    exit(1);
});

// Set custom exception handler
set_exception_handler(function($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'An uncaught exception occurred.',
            'details' => [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()) // More JSON-friendly trace
            ]
        ]);
    } else {
        // Log the exception if we can't send JSON.
        error_log("Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    }
    exit(1);
});