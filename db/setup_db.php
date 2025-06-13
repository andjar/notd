<?php
require_once __DIR__ . '/../config.php';

if (!function_exists('log_setup_local')) {
    function log_setup_local($message) {
        error_log("[Database Setup] " . $message);
    }
}

/**
 * Runs the entire database schema setup.
 * This function requires a valid PDO connection to be passed to it.
 *
 * @param PDO $pdo The database connection object.
 * @throws Exception if any part of the setup fails.
 */
function run_database_setup(PDO $pdo) {
    try {
        $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
        if ($schemaSql === false) {
            throw new Exception("Could not read schema.sql file.");
        }

        log_setup_local("Applying database schema from schema.sql...");
        
        $pdo->beginTransaction();
        try {
            // The 'exec' command can handle multiple statements separated by semicolons.
            // This is simpler and more robust for applying a full schema.
            $pdo->exec($schemaSql);
            
            $pdo->commit();
            log_setup_local("Database schema applied successfully.");
        } catch (Exception $e) {
            $pdo->rollBack();
            log_setup_local("Database setup failed during schema application: " . $e->getMessage());
            throw $e;
        }
        log_setup_local("Database setup completed successfully!");

    } catch (Exception $e) {
        log_setup_local("Database setup failed: " . $e->getMessage());
        throw $e; // Re-throw to be handled by the caller
    }
}