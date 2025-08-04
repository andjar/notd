<?php
// Debug API endpoints
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug API Endpoints</h1>";

// Test the main API endpoints that the frontend is trying to access
$endpoints = [
    'pages' => 'api/v1/pages.php',
    'templates' => 'api/v1/templates.php',
    'ping' => 'api/v1/ping.php'
];

foreach ($endpoints as $name => $endpoint) {
    echo "<h2>Testing $name endpoint: $endpoint</h2>";
    
    if (file_exists($endpoint)) {
        echo "<p style='color: green;'>✅ File exists</p>";
        
        // Test the endpoint
        $url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/$endpoint";
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
    } else {
        echo "<p style='color: red;'>❌ File does not exist</p>";
    }
    
    echo "<hr>";
}

// Test database connection
echo "<h2>Testing Database Connection</h2>";

try {
    require_once 'api/db_connect.php';
    $pdo = get_db_connection();
    echo "<p style='color: green;'>✅ Database connection successful</p>";
    
    // Check if tables exist
    $tables = ['Pages', 'Notes', 'Notes_fts_lookup'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if ($stmt->fetch()) {
            echo "<p style='color: green;'>✅ Table $table exists</p>";
        } else {
            echo "<p style='color: red;'>❌ Table $table does not exist</p>";
        }
    }
    
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

// Test specific page endpoint
echo "<h2>Testing Specific Page Endpoint</h2>";

$pageName = "2025-08-03";
$url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/api/v1/pages.php?name=" . urlencode($pageName);

echo "<p><strong>Testing page:</strong> $pageName</p>";
echo "<p><strong>URL:</strong> $url</p>";

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

echo "<h2>🎯 SUMMARY:</h2>";
echo "<p>The issue is likely that the API endpoints are returning HTML error pages instead of JSON responses.</p>";
echo "<p>This could be due to:</p>";
echo "<ul>";
echo "<li>PHP errors in the API endpoints</li>";
echo "<li>Database connection issues</li>";
echo "<li>Missing pages in the database</li>";
echo "<li>Configuration issues</li>";
echo "</ul>";
?> 