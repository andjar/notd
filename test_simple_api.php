<?php
// Simple API test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Simple API Test</h1>";

// Test pages.php directly
echo "<h2>Testing pages.php</h2>";

$_GET = ['name' => '2025-08-03'];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
include 'api/v1/pages.php';
$output = ob_get_clean();

echo "<p><strong>Response:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
echo htmlspecialchars($output);
echo "</pre>";

$json = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "<p style='color: green;'>✅ Valid JSON response</p>";
    echo "<p><strong>Status:</strong> " . ($json['status'] ?? 'unknown') . "</p>";
} else {
    echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
}

echo "<hr>";

// Test ping.php
echo "<h2>Testing ping.php</h2>";

$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
include 'api/v1/ping.php';
$output = ob_get_clean();

echo "<p><strong>Response:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
echo htmlspecialchars($output);
echo "</pre>";

$json = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "<p style='color: green;'>✅ Valid JSON response</p>";
    echo "<p><strong>Status:</strong> " . ($json['status'] ?? 'unknown') . "</p>";
} else {
    echo "<p style='color: red;'>❌ Not valid JSON: " . json_last_error_msg() . "</p>";
}
?> 