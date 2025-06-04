<?php
require_once '../config.php';
require_once '../api/db_connect.php';
require_once '../template_processor.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

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
        sendJsonResponse(['success' => false, 'error' => 'Invalid template type'], 400);
    }
}

try {
    $pdo = get_db_connection();
    
    if ($method === 'GET') {
        $type = $_GET['type'] ?? 'note';
        logError("Processing GET request", ['type' => $type]);
        
        if (!in_array($type, ['note', 'page'])) {
            logError("Invalid template type in GET", ['type' => $type]);
            sendJsonResponse(['success' => false, 'error' => 'Invalid template type'], 400);
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
            $response = [
                'success' => true,
                'data' => $templateData
            ];
            logError("Final response structure", ['response' => $response]);
            sendJsonResponse($response);
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
            sendJsonResponse(['success' => false, 'error' => 'Template name and content are required'], 400);
        }

        $processor = new TemplateProcessor($input['type']);
        $success = $processor->addTemplate($input['name'], $input['content']);
        
        if ($success) {
            sendJsonResponse(['success' => true, 'message' => 'Template created successfully']);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Failed to create template'], 500);
        }
    }
    else if ($method === 'DELETE') {
        if (!isset($_GET['name']) || !isset($_GET['type'])) {
            sendJsonResponse(['success' => false, 'error' => 'Template name and type are required'], 400);
        }

        $processor = new TemplateProcessor($_GET['type']);
        $success = $processor->deleteTemplate($_GET['name']);
        
        if ($success) {
            sendJsonResponse(['success' => true, 'message' => 'Template deleted successfully']);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Failed to delete template'], 500);
        }
    }
    else {
        sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    logError("Unhandled exception", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    sendJsonResponse([
        'success' => false, 
        'error' => $e->getMessage(),
        'details' => 'Check server logs for more information'
    ], 500);
} 