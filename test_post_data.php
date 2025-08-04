<?php
// test_post_data.php

$method = $_SERVER['REQUEST_METHOD'];
$log_file = __DIR__ . '/post_data_log.txt';

$response = [
    'status' => 'error',
    'message' => 'No data received.'
];

if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    
    // Log the raw input to a file for debugging
    file_put_contents($log_file, "Timestamp: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    file_put_contents($log_file, "Raw Input: " . $rawInput . "\n\n", FILE_APPEND);

    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $response['status'] = 'success';
            $response['message'] = 'JSON data received and decoded successfully.';
            $response['data'] = $decoded;
        } else {
            $response['message'] = 'Invalid JSON data received.';
        }
    } else {
        $response['message'] = 'POST request received, but php://input stream was empty.';
    }
} else {
    $response['message'] = 'This endpoint only accepts POST requests.';
}

header('Content-Type: application/json');
echo json_encode($response);
