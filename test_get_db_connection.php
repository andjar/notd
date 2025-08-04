<?php

// Test get_db_connection() function step by step
echo "<h1>Testing get_db_connection() Function</h1>";

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

// Step 4: Test get_db_connection() function
echo "<h2>Step 4: Testing get_db_connection()</h2>";
try {
    $pdo = get_db_connection();
    echo "<p>get_db_connection() successful</p>";
    
    // Test a simple query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM Pages");
    $result = $stmt->fetch();
    echo "<p>Pages count: " . $result['count'] . "</p>";
    
} catch (Exception $e) {
    echo "<p>get_db_connection() failed: " . $e->getMessage() . "</p>";
    echo "<p>Error trace: " . $e->getTraceAsString() . "</p>";
}

echo "<p>Test complete.</p>"; 