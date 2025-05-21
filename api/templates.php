<?php
header('Content-Type: application/json');

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