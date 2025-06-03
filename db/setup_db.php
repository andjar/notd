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

    echo "Applying database schema...\n";
    $pdo->exec($schemaSql);
    echo "✓ Database schema applied successfully.\n";

    // Check if Journal page exists, if not, create it
    $stmt = $pdo->query("SELECT COUNT(*) FROM Pages WHERE name = 'Journal'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO Pages (name) VALUES ('Journal')");
        echo "✓ Default 'Journal' page created.\n";
    }
    
    // Apply property definitions to existing properties
    echo "\nApplying property definitions to existing properties...\n";
    
    $stmt = $pdo->prepare("SELECT name, internal FROM PropertyDefinitions WHERE auto_apply = 1");
    $stmt->execute();
    $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalUpdated = 0;
    foreach ($definitions as $definition) {
        $updateStmt = $pdo->prepare("
            UPDATE Properties 
            SET internal = ? 
            WHERE name = ? AND internal != ?
        ");
        $updateStmt->execute([
            $definition['internal'], 
            $definition['name'], 
            $definition['internal']
        ]);
        $updated = $updateStmt->rowCount();
        $totalUpdated += $updated;
        
        if ($updated > 0) {
            echo "✓ Applied '{$definition['name']}' definition to {$updated} properties\n";
        }
    }
    
    if ($totalUpdated === 0) {
        echo "✓ No existing properties needed updating\n";
    } else {
        echo "✓ Total properties updated: {$totalUpdated}\n";
    }

    echo "\n🎉 Database setup completed successfully!\n";
    echo "\nProperty Definitions System is ready:\n";
    echo "• Visit property_definitions_manager.php to manage property rules\n";
    echo "• Use property_manager.php to view individual property instances\n";
    echo "• New properties will automatically follow defined rules\n";

} catch (PDOException $e) {
    die("Database setup failed: " . $e->getMessage() . "\n");
}