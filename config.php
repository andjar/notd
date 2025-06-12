<?php
if (!defined('DB_PATH')) { define('DB_PATH', __DIR__ . '/db/database.sqlite'); }
if (!defined('UPLOADS_DIR')) { define('UPLOADS_DIR', __DIR__ . '/uploads'); }
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', ''); // Set this if your app is in a subdirectory, e.g., /notetaker
}

define('ACTIVE_THEME', 'flatly'); // Defines the active theme file (e.g., 'default' for 'default.css')
define('WEBHOOKS_ENABLED', true); // Option to disable webhooks
define('ACTIVE_EXTENSIONS', ['attachment_dashboard', 'pomodoro_timer', 'kanban_board']);
define('TASK_STATES', ['TODO', 'DOING', 'DONE', 'SOMEDAY', 'WAITING']);

// Property Behavior Configuration by Colon Count
// Defines how properties are treated based on the number of colons in their definition (e.g., key::value, key:::value)
// - 'name': A friendly name for the behavior type.
// - 'visible_view': Boolean, true if the property should be visible in read-only views.
// - 'visible_edit': Boolean, true if the property should be visible in edit modes (e.g., text representation).
// - 'update_behavior': String, defines how updates are handled during sync:
//    - 'replace': Existing properties with this name are removed and replaced by those in content.
//    - 'append': Existing properties are kept, and new ones from content are added. Value changes in content will add a new property, not update existing.
define('PROPERTY_BEHAVIORS_BY_COLON_COUNT', [
    2 => ['name' => 'normal', 'visible_view' => true, 'visible_edit' => true, 'update_behavior' => 'replace'], // Default, e.g., key::value
    3 => ['name' => 'internal', 'visible_view' => false, 'visible_edit' => true, 'update_behavior' => 'replace'], // e.g., key:::value
    4 => ['name' => 'system_append', 'visible_view' => false, 'visible_edit' => false, 'update_behavior' => 'append'] // e.g., key::::value (system managed, append-only)
    // Users can add more definitions here, e.g., for 5 colons etc.
]);

// Error reporting (for development)
ini_set('display_errors', 1); // Enable error display
ini_set('display_startup_errors', 1); // Enable startup error display
error_reporting(E_ALL); // Log all errors

// Timezone
date_default_timezone_set('UTC'); // Or your preferred timezone

// Set error handler to return JSON responses
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        // This error code is not included in error_reporting
        return false;
    }
    
    // Only handle errors if headers haven't been sent yet
    if (!headers_sent()) {
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal Server Error',
            'details' => [
                'message' => $errstr,
                'file' => $errfile,
                'line' => $errline,
                'type' => 'error',
                'errno' => $errno
            ]
        ]);
    } else {
        // Log the error if we can't send JSON
        error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");
    }
    exit(1);
});

// Set exception handler to return JSON responses
set_exception_handler(function($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal Server Error',
            'details' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'type' => 'exception',
                'trace' => $e->getTraceAsString()
            ]
        ]);
    } else {
        // Log the exception if we can't send JSON
        error_log("Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    }
    exit(1);
});