<?php

namespace App;

// Start output buffering to prevent header issues
ob_start();

// Disable error handlers before including config.php to prevent header issues
set_error_handler(null);
set_exception_handler(null);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../template_processor.php';

// Error reporting is handled by config.php

// header('Content-Type: application/json'); // Will be handled by ApiResponse
require_once __DIR__ . '/../response_utils.php'; // Include the new response utility

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Log function for debugging (disabled for production)
function logError($message, $context = []) {
    // Disabled to prevent HTML output
    // error_log($message . " - Context: " . json_encode($context));
}

// Validate input
if ($method === 'POST' || $method === 'PUT') {
    if (!isset($input['type']) || !in_array($input['type'], ['note', 'page'])) {
        logError("Invalid template type", ['type' => $input['type'] ?? 'not set']);
        \App\ApiResponse::error('Invalid template type', 400);
        exit;
    }

    // Validate action for POST requests
    if ($method === 'POST' && isset($input['action'])) {
        if (!in_array($input['action'], ['create', 'delete', 'update'])) {
            logError("Invalid action", ['action' => $input['action']]);
            \App\ApiResponse::error('Invalid action. Must be one of: create, delete, update', 400);
            exit;
        }
    }
}

try {
    $pdo = get_db_connection();
    
    if ($method === 'GET') {
        $type = $_GET['type'] ?? 'note';
        logError("Processing GET request", ['type' => $type]);
        
        if (!in_array($type, ['note', 'page'])) {
            logError("Invalid template type in GET", ['type' => $type]);
            \App\ApiResponse::error('Invalid template type', 400);
            exit;
        }

        try {
            $processor = new \App\TemplateProcessor($type);
            $templates = $processor->getAvailableTemplates();
            logError("Found templates", ['count' => count($templates), 'templates' => $templates]);
            
            // For each template, get its content
            $templateData = [];
            foreach ($templates as $template) {
                try {
                    $content = $processor->processTemplate($template);
                    $templateData[] = [
                        'name' => $template,
                        'content' => $content
                    ];
                } catch (Exception $e) {
                    logError("Error processing template", [
                        'template' => $template,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Skip templates that can't be processed
                    continue;
                }
            }
            
            logError("Sending template data", ['count' => count($templateData)]);
            // Ensure we always send the response in the expected format
            $response = $templateData; // ApiResponse.success will wrap it with 'success' and 'data'
            logError("Final response structure", ['response' => $response]);
            \App\ApiResponse::success($response);
        } catch (Exception $e) {
            logError("Error in template processing", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to be caught by outer try-catch
        }
    }
    else if ($method === 'POST') {
            $overrideMethod = null;
            if (isset($input['_method'])) {
                $overrideMethod = strtoupper($input['_method']);
            }

            if ($overrideMethod === 'PUT') {
                // UPDATE LOGIC
                // Validate: current_name, content (new_name is optional)
                if (!isset($input['current_name']) || !isset($input['content'])) {
                    \App\ApiResponse::error('Missing required fields: current_name, content', 400);
                    exit;
                }

                $type = $input['type'];
                $currentName = $input['current_name'];
                $content = $input['content'];
                $newName = $input['new_name'] ?? $currentName;

                try {
                    $processor = new \App\TemplateProcessor($type);
                    $processor->updateTemplate($currentName, $content, $newName);
                    \App\ApiResponse::success(['message' => 'Template updated successfully']);
                } catch (Exception $e) {
                    logError("Error updating template", [
                        'current_name' => $currentName,
                        'new_name' => $newName,
                        'error' => $e->getMessage()
                    ]);
                    \App\ApiResponse::error('Failed to update template: ' . $e->getMessage(), 500);
                }
            } else {
                // CREATE LOGIC
                if (!isset($input['name']) || !isset($input['content'])) {
                    \App\ApiResponse::error('Missing required fields: name, content', 400);
                    exit;
                }

                $type = $input['type'];
                $name = $input['name'];
                $content = $input['content'];

                try {
                    $processor = new \App\TemplateProcessor($type);
                    $processor->createTemplate($name, $content);
                    \App\ApiResponse::success(['message' => 'Template created successfully'], 201);
                } catch (Exception $e) {
                    logError("Error creating template", [
                        'name' => $name,
                        'error' => $e->getMessage()
                    ]);
                    \App\ApiResponse::error('Failed to create template: ' . $e->getMessage(), 500);
                }
            }
    }
    else if ($method === 'DELETE') {
        // DELETE LOGIC
        if (!isset($input['name'])) {
            \App\ApiResponse::error('Missing required field: name', 400);
            exit;
        }

        $type = $input['type'];
        $name = $input['name'];

        try {
            $processor = new \App\TemplateProcessor($type);
            $processor->deleteTemplate($name);
            \App\ApiResponse::success(['message' => 'Template deleted successfully']);
        } catch (Exception $e) {
            logError("Error deleting template", [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            \App\ApiResponse::error('Failed to delete template: ' . $e->getMessage(), 500);
        }
    }
    else {
        \App\ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    logError("Unhandled exception in templates.php", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    \App\ApiResponse::error('Server error: ' . $e->getMessage(), 500);
}

// End output buffering and send the response
ob_end_flush();