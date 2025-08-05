<?php

namespace App;

// Start output buffering to prevent header issues
ob_start();

// Disable error handlers BEFORE including config.php to prevent HTML output
set_error_handler(null);
set_exception_handler(null);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../PatternProcessor.php';
require_once __DIR__ . '/../DataManager.php';
require_once __DIR__ . '/../response_utils.php';
require_once __DIR__ . '/../uuid_utils.php';
require_once __DIR__ . '/batch_operations.php';

use App\UuidUtils;

// Debug logging disabled to prevent HTML output

// Create a fresh database connection for this request to avoid locking issues
$pdo = get_db_connection();
$dataManager = new \App\DataManager($pdo);
$method = $_SERVER['REQUEST_METHOD'];
// Disable global error handlers
set_error_handler(null);
set_exception_handler(null);

$input = json_decode(file_get_contents('php://input'), true);



if ($method === 'POST' && isset($input['_method'])) {
    $overrideMethod = strtoupper($input['_method']);
    if ($overrideMethod === 'PUT' || $overrideMethod === 'DELETE') {
        $method = $overrideMethod;
    }
}

// All batch operation functions are now defined in batch_operations.php

if ($method === 'GET') {
    // **FIX**: Cast the result of filter_input to a boolean.
    // If 'include_internal' is not set, filter_input returns null, which (bool)null casts to false.
    // This prevents the TypeError in the DataManager.
    $includeInternal = (bool)filter_input(INPUT_GET, 'include_internal', FILTER_VALIDATE_BOOLEAN);
    $includeParentProperties = (bool)filter_input(INPUT_GET, 'include_parent_properties', FILTER_VALIDATE_BOOLEAN);
    $includeChildren = (bool)filter_input(INPUT_GET, 'include_children', FILTER_VALIDATE_BOOLEAN);
    
    try {
        if (isset($_GET['id'])) {
            $noteId = $_GET['id'];
            
            if ($includeChildren) {
                // Use the new method to fetch note with children
                $note = $dataManager->getNoteWithChildren($noteId, $includeInternal, $includeParentProperties);
            } else {
                // Use the existing method for backward compatibility
                $note = $dataManager->getNoteById($noteId, $includeInternal, $includeParentProperties);
            }
            
            if ($note) {
                \App\ApiResponse::success($note);
            } else {
                \App\ApiResponse::error('Note not found', 404);
            }
        } elseif (isset($_GET['page_id'])) {
            $pageId = $_GET['page_id'];
            $notes = $dataManager->getNotesByPageId($pageId, $includeInternal, $includeParentProperties);
            \App\ApiResponse::success($notes);
        } else {
            \App\ApiResponse::error('Missing required parameter: id or page_id', 400);
        }
    } catch (Exception $e) {
        \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'POST') {
    try {
        if (isset($input['batch']) && $input['batch'] === true) {
            // Batch operations
            $response = \App\process_batch_request($input, $pdo);
            
            if (isset($response['error'])) {
                // The process_batch_request function returns detailed error information
                \App\ApiResponse::error($response['error'], $response['status_code'] ?? 500);
            } else {
                // The client expects the array of results directly in the 'data' property
                \App\ApiResponse::success($response['results']);
            }
        } else {
            error_log("Not processing batch operations");
            error_log("Input data: " . json_encode($input));
            error_log("batch field: " . (isset($input['batch']) ? 'set' : 'not set'));
            error_log("batch value: " . ($input['batch'] ?? 'null'));
            // Single note creation
            if (!isset($input['page_id'])) {
                \App\ApiResponse::error('Missing required field: page_id', 400);
            }
            
            $result = _createNoteInBatch($pdo, $dataManager, $input, false);
            
            if ($result['status'] === 'success') {
                \App\ApiResponse::success($result['note'], 201);
            } else {
                \App\ApiResponse::error($result['message'], 400);
            }
        }
    } catch (Exception $e) {
        \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'PUT') {
    try {
        if (!isset($input['id'])) {
            \App\ApiResponse::error('Missing required field: id', 400);
        }
        
        $result = _updateNoteInBatch($pdo, $dataManager, $input);
        
        if ($result['status'] === 'success') {
            \App\ApiResponse::success($result['note']);
        } else {
            \App\ApiResponse::error($result['message'], 400);
        }
    } catch (Exception $e) {
        \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
    }
} elseif ($method === 'DELETE') {
    try {
        if (!isset($input['id'])) {
            \App\ApiResponse::error('Missing required field: id', 400);
        }
        
        $result = _deleteNoteInBatch($pdo, $input);
        
        if ($result['status'] === 'success') {
            \App\ApiResponse::success(['deleted_note_id' => $result['deleted_note_id']]);
        } else {
            \App\ApiResponse::error($result['message'], 400);
        }
    } catch (Exception $e) {
        \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
    }
} else {
    \App\ApiResponse::error('Method not allowed', 405);
}

// End output buffering and send the response
ob_end_flush();