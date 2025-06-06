<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

error_log("=== TEST UPLOAD SCRIPT ===");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST request received");
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
    
    echo json_encode(['status' => 'received', 'post' => $_POST, 'files' => $_FILES]);
} else {
    echo json_encode(['status' => 'ready', 'method' => $_SERVER['REQUEST_METHOD']]);
}
?> 