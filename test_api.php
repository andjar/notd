<?php
// Test the attachments API endpoint
$api_url = 'http://localhost/api/v1/attachments.php';

echo "Testing attachments API endpoint...\n";
echo "URL: $api_url\n\n";

// Test GET request
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Accept: application/json',
        'timeout' => 10
    ]
]);

$response = file_get_contents($api_url, false, $context);

if ($response === false) {
    echo "Error: Could not connect to API endpoint\n";
    echo "Make sure your web server is running and the path is correct.\n";
} else {
    echo "Response received:\n";
    echo $response . "\n";
    
    // Try to parse JSON
    $data = json_decode($response, true);
    if ($data) {
        echo "\nParsed JSON response:\n";
        print_r($data);
    } else {
        echo "\nCould not parse JSON response.\n";
    }
}
?> 