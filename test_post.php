<?php
// Simple test script to test POST to notes API
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Testing POST to notes API...\n\n";

$url = 'http://localhost:50207/api/notes.php';
$data = json_encode([
    'page_id' => 1,
    'content' => 'Test note from script'
]);

$options = [
    'http' => [
        'header' => "Content-Type: application/json\r\n",
        'method' => 'POST',
        'content' => $data
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === FALSE) {
    echo "Error: Failed to make request\n";
    print_r($http_response_header);
} else {
    echo "Response: " . $result . "\n";
}

// Also test PUT using POST with _method override (for phpdesktop compatibility)
echo "\n\nTesting PUT to notes API using POST with _method override...\n\n";

$putUrl = 'http://localhost:50207/api/notes.php'; // Remove query parameter for phpdesktop compatibility
$putData = json_encode([
    '_method' => 'PUT',
    'id' => 8, // Include ID in body for phpdesktop compatibility
    'content' => 'Updated test content'
]);

$putOptions = [
    'http' => [
        'header' => "Content-Type: application/json\r\n",
        'method' => 'POST', // Use POST instead of PUT
        'content' => $putData
    ]
];

$putContext = stream_context_create($putOptions);
$putResult = file_get_contents($putUrl, false, $putContext);

if ($putResult === FALSE) {
    echo "Error: Failed to make PUT request\n";
    if (isset($http_response_header)) {
        print_r($http_response_header);
    }
} else {
    echo "PUT Response: " . $putResult . "\n";
}
?> 