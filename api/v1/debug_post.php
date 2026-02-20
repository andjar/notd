<?php
// debug_post.php
header('Content-Type: application/json');
$log_file = __DIR__ . '/debug_log.txt';

// Clear the log file for each new request
file_put_contents($log_file, '');

$log_data = "--- New Request: " . date('Y-m-d H:i:s') . " ---\n\n";
$log_data .= "SERVER DATA:\n";
$log_data .= print_r($_SERVER, true) . "\n\n";

$log_data .= "POST DATA:\n";
$log_data .= print_r($_POST, true) . "\n\n";

$log_data .= "GET DATA:\n";
$log_data .= print_r($_GET, true) . "\n\n";

$raw_input = file_get_contents('php://input');
$log_data .= "php://input RAW:\n";
$log_data .= $raw_input . "\n\n";

$decoded_input = json_decode($raw_input, true);
$log_data .= "php://input DECODED:\n";
$log_data .= print_r($decoded_input, true) . "\n\n";

if (isset($_POST['json'])) {
    $decoded_post_json = json_decode($_POST['json'], true);
    $log_data .= "DECODED \$_POST['json']:\n";
    $log_data .= print_r($decoded_post_json, true) . "\n\n";
}

file_put_contents($log_file, $log_data);

// Return a generic success response to prevent client-side errors during debug
echo json_encode(['status' => 'success', 'message' => 'Debug data logged.']);
?>
