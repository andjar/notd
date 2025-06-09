<?php
// Set appropriate content type
header('Content-Type: text/html; charset=utf-8');

// Read and output the dashboard HTML file
readfile(__DIR__ . '/attachments_dashboard.html');
?> 