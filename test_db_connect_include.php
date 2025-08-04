<?php

// Test including db_connect.php directly
echo "<h1>Testing db_connect.php Include</h1>";

// Step 1: Include config.php
echo "<h2>Step 1: Including config.php</h2>";
try {
    require_once __DIR__ . '/config.php';
    echo "<p>Config.php included successfully</p>";
} catch (Exception $e) {
    echo "<p>Config.php include failed: " . $e->getMessage() . "</p>";
    exit;
}

// Step 2: Include setup_db_fixed.php
echo "<h2>Step 2: Including setup_db_fixed.php</h2>";
try {
    require_once __DIR__ . '/db/setup_db_fixed.php';
    echo "<p>Setup_db_fixed.php included successfully</p>";
} catch (Exception $e) {
    echo "<p>Setup_db_fixed.php include failed: " . $e->getMessage() . "</p>";
    exit;
}

// Step 3: Include db_helpers.php
echo "<h2>Step 3: Including db_helpers.php</h2>";
try {
    require_once __DIR__ . '/api/db_helpers.php';
    echo "<p>Db_helpers.php included successfully</p>";
} catch (Exception $e) {
    echo "<p>Db_helpers.php include failed: " . $e->getMessage() . "</p>";
    exit;
}

// Step 4: Include db_connect.php
echo "<h2>Step 4: Including db_connect.php</h2>";
try {
    require_once __DIR__ . '/api/db_connect.php';
    echo "<p>Db_connect.php included successfully</p>";
} catch (Exception $e) {
    echo "<p>Db_connect.php include failed: " . $e->getMessage() . "</p>";
    exit;
}

echo "<p>All includes successful!</p>";
echo "<p>Test complete.</p>"; 