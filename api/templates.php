<?php
header('Content-Type: application/json');

// Set error handling
error_reporting(E_ERROR);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

$templates = [];
$templateDir = __DIR__ . '/../templates/';

if (is_dir($templateDir)) {
    $files = scandir($templateDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'txt') {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $content = file_get_contents($templateDir . $file);
            $templates[$name] = $content;
        }
    }
}

echo json_encode($templates); 