<?php

namespace App;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../template_processor.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// header('Content-Type: application/json'); // Will be handled by ApiResponse
require_once __DIR__ . '/../response_utils.php'; // Include the new response utility

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Log function for debugging
function logError($message, $context = []) {
    $logMessage = date('Y-m-d H:i:s') . " - " . $message;
    if (!empty($context)) {
        $logMessage .= " - Context: " . json_encode($context);
    }
    error_log($logMessage);
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
                if (!isset($input['type']) || !in_array($input['type'], ['note', 'page'])) {
                    \App\ApiResponse::error('Invalid template type', 400);
                    exit;
                }
                if (!isset($input['current_name']) || !isset($input['content'])) {
                    \App\ApiResponse::error('Current template name and content are required for update', 400);
                    exit;
                }
                $processor = new \App\TemplateProcessor($input['type']);
                $success = false;
                if (isset($input['new_name']) && $input['new_name'] !== $input['current_name']) {
                    $deleteSuccess = $processor->deleteTemplate($input['current_name']);
                    if ($deleteSuccess) {
                         $success = $processor->addTemplate($input['new_name'], $input['content']);
                    }
                } else {
                    $deleteSuccess = $processor->deleteTemplate($input['current_name']);
                    if ($deleteSuccess) {
                        $success = $processor->addTemplate($input['current_name'], $input['content']);
                    }
                }
                if ($success) {
                    \App\ApiResponse::success(['message' => 'Template updated successfully']);
                } else {
                    \App\ApiResponse::error('Failed to update template', 500);
                }

            } elseif ($overrideMethod === 'DELETE') {
                // DELETE LOGIC
                // Validate: name, type
                if (!isset($input['type']) || !in_array($input['type'], ['note', 'page'])) {
                    \App\ApiResponse::error('Invalid template type', 400);
                    exit;
                }
                if (!isset($input['name'])) {
                    \App\ApiResponse::error('Template name is required for deletion', 400);
                    exit;
                }
                $processor = new \App\TemplateProcessor($input['type']);
                $success = $processor->deleteTemplate($input['name']);
                if ($success) {
                    \App\ApiResponse::success(['message' => 'Template deleted successfully']);
                } else {
                    \App\ApiResponse::error('Failed to delete template', 500);
                }

            } elseif ($overrideMethod === null || $overrideMethod === 'POST') {
                // CREATE LOGIC (original POST)
                // Validate: type, name, content
                if (!isset($input['type']) || !in_array($input['type'], ['note', 'page'])) {
                    \App\ApiResponse::error('Invalid template type', 400);
                    exit;
                }
                if (!isset($input['name']) || !isset($input['content'])) {
                    \App\ApiResponse::error('Template name and content are required', 400);
                    exit;
                }
                $processor = new \App\TemplateProcessor($input['type']);
                $success = $processor->addTemplate($input['name'], $input['content']);
                if ($success) {
                    \App\ApiResponse::success(['message' => 'Template created successfully'], 201);
                } else {
                    \App\ApiResponse::error('Failed to create template', 500);
                }
            } else {
                \App\ApiResponse::error('Invalid _method specified for POST.', 400);
            }
    }
    else {
        \App\ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    logError("Unhandled exception", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    \App\ApiResponse::error($e->getMessage(), 500, 'Check server logs for more information');
}