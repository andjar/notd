<?php
// Load config if exists
if (file_exists('config.php')) {
    require_once 'config.php';
}

if (!defined('DB_PATH')) {
    define('DB_PATH', 'notedb.sqlite');
}

// Redirect to the current date page
$currentDate = date('Y-m-d');
header('Location: page.php?page=' . urlencode($currentDate));
exit;
