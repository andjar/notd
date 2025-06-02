<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Enable foreign key constraints for this connection
    $pdo->exec('PRAGMA foreign_keys = ON;');
    // Use WAL mode for better concurrency and performance
    $pdo->exec('PRAGMA journal_mode = WAL;');

    $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
    if ($schemaSql === false) {
        die("Error: Could not read schema.sql file.\n");
    }

    $pdo->exec($schemaSql);

    echo "Database schema applied successfully.\n";

    // Check if Journal page exists, if not, create it
    $stmt = $pdo->query("SELECT COUNT(*) FROM Pages WHERE name = 'Journal'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO Pages (name) VALUES ('Journal')");
        echo "Default 'Journal' page created.\n";
    }

} catch (PDOException $e) {
    die("Database setup failed: " . $e->getMessage() . "\n");
}