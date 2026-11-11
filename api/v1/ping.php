<?php
// GET /api/v1/ping.php: is the server there? Touches neither configuration nor database.
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['status' => 'pong', 'timestamp' => gmdate('c')]);
