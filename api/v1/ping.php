<?php
header('Content-Type: application/json');
echo json_encode(['status' => 'pong', 'timestamp' => date('c')]);
?>
