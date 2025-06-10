<?php
// api/v1/extensions.php

// Include base files - db_connect.php often handles session, error reporting, and base includes
require_once __DIR__ . '/../db_connect.php'; 
require_once __DIR__ . '/../property_utils.php'; // For PropertyUtils class

header('Content-Type: application/json');

try {
    // Ensure PropertyUtils class and its method exist
    if (!class_exists('PropertyUtils') || !method_exists('PropertyUtils', 'getActiveExtensionDetails')) {
        throw new Exception('Required utility class or method not found.');
    }

    $activeExtensions = PropertyUtils::getActiveExtensionDetails();
    
    echo json_encode([
        'success' => true,
        'extensions' => $activeExtensions
    ]);

} catch (Exception $e) {
    // Send a generic error response
    // In a production environment, log $e->getMessage() and avoid sending detailed errors to the client
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while fetching extension details.',
        // 'debug_message' => $e->getMessage() // Optional: for debugging, not for production
    ]);
}

exit;
?>
