<?php
// Test the API directly using PHP's HTTP client
$url = 'http://127.0.0.1:49368/api/v1/pages.php?name=2025-08-04';

echo "Testing API endpoint: $url\n";
echo "================================\n\n";

// Use cURL to test the API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Content-Type: $contentType\n";
echo "Response:\n";
echo $response; 