<?php
// Test script to check API endpoint responses
require_once 'config.php';

// Test the pages endpoint
echo "Testing pages endpoint...\n";
$url = 'http://localhost/api/v1/pages.php?name=2025-08-04';
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Content-Type: application/json'
    ]
]);

$response = file_get_contents($url, false, $context);
$headers = $http_response_header ?? [];

echo "Response Headers:\n";
foreach ($headers as $header) {
    echo "  $header\n";
}

echo "\nResponse Body:\n";
echo substr($response, 0, 500) . "\n";

// Test the templates endpoint
echo "\nTesting templates endpoint...\n";
$url = 'http://localhost/api/v1/templates.php?type=note';
$response = file_get_contents($url, false, $context);
$headers = $http_response_header ?? [];

echo "Response Headers:\n";
foreach ($headers as $header) {
    echo "  $header\n";
}

echo "\nResponse Body:\n";
echo substr($response, 0, 500) . "\n";
?> 