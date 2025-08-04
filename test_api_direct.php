<?php
// Test API endpoints directly
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test API Endpoints Directly</h1>";

// Test 1: Test pages.php directly
echo "<h2>Test 1: Testing pages.php directly</h2>";

try {
    // Set up the environment
    $_GET = ['name' => '2025-08-03'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    // Capture output
    ob_start();
    
    // Include the pages.php file
    include 'api/v1/pages.php';
    
    $output = ob_get_clean();
    
    echo "<p><strong>Raw output:</strong></p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($output);
    echo "</pre>";
    
    // Try to decode as JSON
    $json = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "<p style='color: green;'>✅ Valid JSON response</p>";
        echo "<p><strong>Decoded JSON:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
        print_r($json);
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

// Test 2: Test ping.php directly
echo "<h2>Test 2: Testing ping.php directly</h2>";

try {
    // Set up the environment
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    // Capture output
    ob_start();
    
    // Include the ping.php file
    include 'api/v1/ping.php';
    
    $output = ob_get_clean();
    
    echo "<p><strong>Raw output:</strong></p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($output);
    echo "</pre>";
    
    // Try to decode as JSON
    $json = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "<p style='color: green;'>✅ Valid JSON response</p>";
        echo "<p><strong>Decoded JSON:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
        print_r($json);
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

// Test 3: Test database connection
echo "<h2>Test 3: Testing Database Connection</h2>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check if the page exists
    $stmt = $pdo->prepare("SELECT * FROM Pages WHERE name = ?");
    $stmt->execute(['2025-08-03']);
    $page = $stmt->fetch();
    
    if ($page) {
        echo "<p style='color: green;'>✅ Page '2025-08-03' exists in database</p>";
        echo "<p><strong>Page data:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
        print_r($page);
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>❌ Page '2025-08-03' does not exist in database</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
}

echo "<h2>🎯 SUMMARY:</h2>";
echo "<p>This test will show us exactly what the API endpoints are returning.</p>";
echo "<p>If they return valid JSON, the issue is with the frontend's HTTP requests.</p>";
echo "<p>If they return HTML or errors, the issue is with the API endpoint code.</p>";
?> 