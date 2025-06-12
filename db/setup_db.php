<?php
require_once __DIR__ . '/../config.php';

if (!function_exists('log_setup_local')) {
    function log_setup_local($message) {
        error_log("[Database Setup] " . $message);
    }
}

/**
 * Runs the entire database schema and initial data setup.
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

        log_setup_local("Applying database schema...");
        
        $pdo->beginTransaction();
        try {
            // Remove comments and execute statements one by one.
            $schemaSql = preg_replace('/--.*$/m', '', $schemaSql);
            $triggers = [];
            $schemaWithoutTriggers = preg_replace_callback(
                '/(CREATE TRIGGER.*?END;)/si',
                function($matches) use (&$triggers) {
                    $triggers[] = trim($matches[0]);
                    return ''; // Remove trigger from main SQL string
                },
                $schemaSql
            );
            $statements = explode(';', $schemaWithoutTriggers);
            
            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    $pdo->exec($statement);
                }
            }
            foreach ($triggers as $trigger) {
                $pdo->exec($trigger);
            }
            
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
        throw $e;
    }
}