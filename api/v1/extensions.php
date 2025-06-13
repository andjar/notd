<?php
// api/v1/extensions.php

/**
 * This endpoint retrieves details about the active extensions by dynamically
 * reading their configuration files. It adheres to the new API specification.
 */

// db_connect.php includes config.php and sets up global error handlers.
require_once __DIR__ . '/../db_connect.php'; 
// property_utils.php contains the logic for finding and parsing extension configs.
require_once __DIR__ . '/../property_utils.php'; 

// Set the response content type to JSON
header('Content-Type: application/json');

try {
    // Check if the required utility class exists to prevent a fatal error.
    if (!class_exists('PropertyUtils') || !method_exists('PropertyUtils', 'getActiveExtensionDetails')) {
        // Throw a specific exception that can be caught by the global handler.
        throw new Exception('Server misconfiguration: PropertyUtils class or method is missing.');
    }

    // Get the array of active extensions.
    $activeExtensions = PropertyUtils::getActiveExtensionDetails();
    
    // Respond using the new API specification format for a successful request.
    echo json_encode([
        'status' => 'success',
        'data' => [
            'extensions' => $activeExtensions
        ]
    ]);

} catch (Exception $e) {
    // Let the global exception handler (set in config.php) catch this.
    // This will format the JSON error response correctly according to the API spec.
    throw $e;
}

exit;