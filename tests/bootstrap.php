<?php
// tests/bootstrap.php

// Define common constants that might be expected by config.php or other included files
// Adjust APP_BASE_URL as necessary if tests need to simulate web requests more accurately,
// though for direct script inclusion, it might not be heavily used.
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', 'http://localhost');
}

// If there's a vendor/autoload.php from Composer, include it:
// if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
//     require_once dirname(__DIR__) . '/vendor/autoload.php';
// }

// For this project, config.php seems to be the main entry point for settings.
// We need to ensure it's loaded, but BaseTestCase will handle DB_PATH specifically.
// We might need to define other constants that config.php or db_connect.php expect.

// Define a base path for the application root if not already defined.
// This helps in resolving paths for includes, especially for config.php.
if (!defined('APP_ROOT_PATH')) {
    define('APP_ROOT_PATH', dirname(__DIR__));
}

// Include config.php but be mindful of its output or direct actions.
// For tests, we often want to control these aspects.
// config.php might try to set headers or output things; this is generally fine
// for CLI tests but good to be aware of.
// require_once APP_ROOT_PATH . '/config.php'; // BaseTestCase will handle specific constants like DB_PATH

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Any other global setup needed for your tests can go here.
?>
