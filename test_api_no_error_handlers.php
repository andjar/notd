<?php

// Disable error handlers before including config.php
set_error_handler(null);
set_exception_handler(null);

// Include config.php
require_once __DIR__ . '/config.php';

// Test manual JSON output
header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'message' => 'API test without error handlers']);
exit; 