<?php
// Set appropriate content type
header('Content-Type: text/html; charset=utf-8');

// Read and output the pomodoro timer HTML file
readfile(__DIR__ . '/pomodoro_timer.html');
?> 