<?php
// Test API logic directly
require_once 'config.php';
require_once 'api/db_connect.php';
require_once 'api/DataManager.php';
require_once 'api/response_utils.php';
require_once 'api/template_processor.php';

// Disable error display
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

echo "Testing API logic directly...\n\n";

try {
    // Test 1: Database connection
    echo "1. Testing database connection...\n";
    $pdo = get_db_connection();
    echo "✓ Database connected successfully\n\n";
    
    // Test 2: DataManager
    echo "2. Testing DataManager...\n";
    $dataManager = new \App\DataManager($pdo);
    echo "✓ DataManager created successfully\n\n";
    
    // Test 3: Page retrieval
    echo "3. Testing page retrieval...\n";
    $pageName = '2025-08-04';
    $page = $dataManager->getPageByName($pageName);
    if ($page) {
        echo "✓ Page found: " . $page['name'] . "\n";
    } else {
        echo "✓ Page not found (will be created by API)\n";
    }
    echo "\n";
    
    // Test 4: Template processor
    echo "4. Testing template processor...\n";
    $templateProcessor = new \App\TemplateProcessor('note');
    $templates = $templateProcessor->getAvailableTemplates();
    echo "✓ Found " . count($templates) . " templates\n";
    echo "Templates: " . implode(', ', $templates) . "\n\n";
    
    // Test 5: API response format
    echo "5. Testing API response format...\n";
    ob_start();
    \App\ApiResponse::success($page ?: ['name' => $pageName, 'content' => null]);
    $response = ob_get_clean();
    
    echo "Response length: " . strlen($response) . " characters\n";
    echo "Response preview: " . substr($response, 0, 100) . "...\n";
    
    $decoded = json_decode($response, true);
    if ($decoded) {
        echo "✓ JSON response is valid\n";
        echo "Response keys: " . implode(', ', array_keys($decoded)) . "\n";
    } else {
        echo "✗ JSON response is invalid\n";
        echo "JSON error: " . json_last_error_msg() . "\n";
    }
    echo "\n";
    
    // Test 6: Template API response
    echo "6. Testing template API response...\n";
    $templateData = [];
    foreach ($templates as $template) {
        try {
            $content = $templateProcessor->processTemplate($template);
            $templateData[] = [
                'name' => $template,
                'content' => $content
            ];
        } catch (Exception $e) {
            echo "Warning: Failed to process template '$template': " . $e->getMessage() . "\n";
        }
    }
    
    ob_start();
    \App\ApiResponse::success($templateData);
    $templateResponse = ob_get_clean();
    
    echo "Template response length: " . strlen($templateResponse) . " characters\n";
    echo "Template response preview: " . substr($templateResponse, 0, 100) . "...\n";
    
    $templateDecoded = json_decode($templateResponse, true);
    if ($templateDecoded) {
        echo "✓ Template JSON response is valid\n";
        echo "Template response keys: " . implode(', ', array_keys($templateDecoded)) . "\n";
    } else {
        echo "✗ Template JSON response is invalid\n";
        echo "JSON error: " . json_last_error_msg() . "\n";
    }
    echo "\n";
    
    echo "All tests completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?> 