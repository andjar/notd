<?php
/**
 * Database Setup Script
 * Creates the database and tables for NotTD application
 * Version 4.0 - Unified Upsert with Separate Creation Timestamps
 */

require_once __DIR__ . '/../config.php';

try {
    // Create database connection
    $pdo = new PDO("sqlite:" . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to database successfully.\n";
    
    // Read and execute schema
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if (!$schema) {
        throw new Exception("Could not read schema file");
    }
    
    // Split schema into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        function($stmt) { return !empty($stmt) && !preg_match('/^--/', $stmt); }
    );
    
    echo "Executing schema statements...\n";
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "Executed: " . substr($statement, 0, 50) . "...\n";
        }
    }
    
    echo "Database setup completed successfully!\n";
    echo "Schema version: 4.0 (Unified Upsert with Separate Creation Timestamps)\n";
    
} catch (Exception $e) {
    echo "Error setting up database: " . $e->getMessage() . "\n";
    exit(1);
}