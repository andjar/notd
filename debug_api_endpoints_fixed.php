<?php
// Debug API endpoints - Fixed version
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug API Endpoints - Fixed Version</h1>";

// Test database connection first
echo "<h2>1. Database Connection Test</h2>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check if there are any pages
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM Pages");
    $result = $stmt->fetch();
    echo "<p><strong>Pages count:</strong> " . $result['count'] . "</p>";
    
    if ($result['count'] > 0) {
        // Show first page
        $stmt = $pdo->query("SELECT * FROM Pages LIMIT 1");
        $page = $stmt->fetch();
        echo "<p><strong>First page:</strong> " . json_encode($page) . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
}

// Test API endpoints directly by including them
echo "<h2>2. Testing API Endpoints Directly</h2>";

// Test pages endpoint
echo "<h3>Testing pages.php</h3>";
try {
    // Capture output
    ob_start();
    
    // Simulate a GET request to pages.php
    $_GET = ['name' => '2025-08-03'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    include 'api/v1/pages.php';
    
    $output = ob_get_clean();
    
    echo "<p><strong>Response:</strong></p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc; max-height: 200px; overflow-y: auto;'>";
    echo htmlspecialchars($output);
    echo "</pre>";
    
    // Check if it's JSON
    $json = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "<p style='color: green;'>✅ Valid JSON response</p>";
    } else {
        echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}

// Test templates endpoint
echo "<h3>Testing templates.php</h3>";
try {
    // Capture output
    ob_start();
    
    // Simulate a GET request to templates.php
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    include 'api/v1/templates.php';
    
    $output = ob_get_clean();
    
    echo "<p><strong>Response:</strong></p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc; max-height: 200px; overflow-y: auto;'>";
    echo htmlspecialchars($output);
    echo "</pre>";
    
    // Check if it's JSON
    $json = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "<p style='color: green;'>✅ Valid JSON response</p>";
    } else {
        echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}

// Test ping endpoint
echo "<h3>Testing ping.php</h3>";
try {
    // Capture output
    ob_start();
    
    // Simulate a GET request to ping.php
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    include 'api/v1/ping.php';
    
    $output = ob_get_clean();
    
    echo "<p><strong>Response:</strong></p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc; max-height: 200px; overflow-y: auto;'>";
    echo htmlspecialchars($output);
    echo "</pre>";
    
    // Check if it's JSON
    $json = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "<p style='color: green;'>✅ Valid JSON response</p>";
    } else {
        echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}

// Test with proper URL construction
echo "<h2>3. Testing with Proper URL Construction</h2>";

$baseUrl = "http://" . $_SERVER['HTTP_HOST'];
if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != '80') {
    $baseUrl .= ":" . $_SERVER['SERVER_PORT'];
}
$baseUrl .= dirname($_SERVER['REQUEST_URI']);

echo "<p><strong>Base URL:</strong> $baseUrl</p>";

$endpoints = [
    'pages' => '/api/v1/pages.php?name=2025-08-03',
    'templates' => '/api/v1/templates.php',
    'ping' => '/api/v1/ping.php'
];

foreach ($endpoints as $name => $endpoint) {
    echo "<h3>Testing $name endpoint</h3>";
    
    $url = $baseUrl . $endpoint;
    echo "<p><strong>URL:</strong> $url</p>";
    
    // Make a simple GET request
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Content-Type: application/json'
        ]
    ]);
    
    $response = file_get_contents($url, false, $context);
    
    if ($response === false) {
        echo "<p style='color: red;'>❌ Failed to get response</p>";
    } else {
        echo "<p><strong>Response:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc; max-height: 200px; overflow-y: auto;'>";
        echo htmlspecialchars($response);
        echo "</pre>";
        
        // Check if it's JSON
        $json = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "<p style='color: green;'>✅ Valid JSON response</p>";
        } else {
            echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
        }
    }
    
    echo "<hr>";
}

echo "<h2>🎯 SUMMARY:</h2>";
echo "<p>This test will help us understand if the API endpoints are working correctly.</p>";
echo "<p>If the direct include tests work but the URL tests don't, it's a server configuration issue.</p>";
echo "<p>If neither works, there's an issue with the API endpoint code itself.</p>";
?> 