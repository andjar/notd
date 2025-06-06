<?php
require_once '../config.php';
require_once '../api/db_connect.php';
require_once '../template_processor.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// header('Content-Type: application/json'); // Will be handled by ApiResponse
require_once 'response_utils.php'; // Include the new response utility

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
        ApiResponse::error('Invalid template type', 400);
        exit;
    }
}

try {
    $pdo = get_db_connection();
    
    if ($method === 'GET') {
        $type = $_GET['type'] ?? 'note';
        logError("Processing GET request", ['type' => $type]);
        
        if (!in_array($type, ['note', 'page'])) {
            logError("Invalid template type in GET", ['type' => $type]);
            ApiResponse::error('Invalid template type', 400);
            exit;
        }

        try {
            $processor = new TemplateProcessor($type);
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
            ApiResponse::success($response);
        } catch (Exception $e) {
            logError("Error in template processing", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to be caught by outer try-catch
        }
    }
    else if ($method === 'POST') {
        if (!isset($input['name']) || !isset($input['content'])) {
            ApiResponse::error('Template name and content are required', 400);
            exit;
        }

        $processor = new TemplateProcessor($input['type']);
        $success = $processor->addTemplate($input['name'], $input['content']);
        
        if ($success) {
            ApiResponse::success(['message' => 'Template created successfully']);
        } else {
            ApiResponse::error('Failed to create template', 500);
        }
    }
    else if ($method === 'DELETE') {
        if (!isset($_GET['name']) || !isset($_GET['type'])) {
            ApiResponse::error('Template name and type are required', 400);
            exit;
        }

        $processor = new TemplateProcessor($_GET['type']);
        $success = $processor->deleteTemplate($_GET['name']);
        
        if ($success) {
            ApiResponse::success(['message' => 'Template deleted successfully']);
        } else {
            ApiResponse::error('Failed to delete template', 500);
        }
    }
    else {
        ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    logError("Unhandled exception", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    ApiResponse::error($e->getMessage(), 500, 'Check server logs for more information');
}