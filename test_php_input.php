<?php
// test_php_input.php
$rawInput = file_get_contents('php://input');
header('Content-Type: application/json');
echo json_encode([
    'raw_input' => $rawInput,
    'decoded' => json_decode($rawInput, true)
]);
