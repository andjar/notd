<?php
// Test the main application and API endpoints
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Application Test</h1>";

// Test 1: Check if main application loads
echo "<h2>1. Testing Main Application</h2>";
echo "<p>Testing if index.php loads without errors...</p>";

try {
    // Simulate a basic request to the main application
    $mainAppContent = file_get_contents('index.php');
    if ($mainAppContent !== false) {
        echo "<p style='color: green;'>✅ index.php file is accessible</p>";
    } else {
        echo "<p style='color: red;'>❌ index.php file is not accessible</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error accessing index.php: " . $e->getMessage() . "</p>";
}

// Test 2: Test API endpoints
echo "<h2>2. Testing API Endpoints</h2>";

// Test batch operations endpoint
echo "<h3>Testing Batch Operations API</h3>";

$testData = [
    'operations' => [
        [
            'type' => 'create',
            'payload' => [
                'page_id' => '018f1234-5678-9abc-def0-123456789abc', // Test UUID
                'content' => 'Test note from API test',
                'order_index' => 0
            ]
        ]
    ]
];

$url = 'api/v1/batch_operations.php';
$postData = http_build_query($testData);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $postData
    ]
]);

$response = file_get_contents($url, false, $context);

echo "<p><strong>Batch Operations API Response:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
echo htmlspecialchars($response);
echo "</pre>";

// Test 3: Test pages API
echo "<h3>Testing Pages API</h3>";

$pagesUrl = 'api/v1/pages.php';
$pagesResponse = file_get_contents($pagesUrl, false, stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Content-Type: application/json'
    ]
]));

echo "<p><strong>Pages API Response:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
echo htmlspecialchars($pagesResponse);
echo "</pre>";

// Test 4: Test ping endpoint
echo "<h3>Testing Ping API</h3>";

$pingUrl = 'api/v1/ping.php';
$pingResponse = file_get_contents($pingUrl, false, stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Content-Type: application/json'
    ]
]));

echo "<p><strong>Ping API Response:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
echo htmlspecialchars($pingResponse);
echo "</pre>";

echo "<h2>3. Summary</h2>";
echo "<p>If the API responses show successful operations, the application should be working correctly with UUIDs.</p>";
echo "<p><a href='index.php'>Click here to test the full application</a></p>";
?> 