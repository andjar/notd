<?php

require_once __DIR__ . '/../config.php';

if (!function_exists('log_setup_local')) {
    function log_setup_local($message) {
        error_log("[Database Setup] " . $message);
    }
}

/**
 * Runs the entire database schema setup with careful handling of FTS triggers.
 * This function requires a valid PDO connection to be passed to it.
 *
 * @param PDO $pdo The database connection object.
 * @throws Exception if any part of the setup fails.
 */
function run_database_setup_fixed(PDO $pdo) {
    try {
        $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
        if ($schemaSql === false) {
            throw new Exception("Could not read schema.sql file.");
        }

        log_setup_local("Applying database schema from schema.sql...");
        
        $pdo->beginTransaction();
        try {
            // Split the schema into individual statements for better control
            $statements = [];
            $currentStatement = '';
            $inTrigger = false;
            $triggerLines = [];
            
            $lines = explode("\n", $schemaSql);
            
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                
                // Skip comments and empty lines
                if (empty($trimmedLine) || strpos($trimmedLine, '--') === 0) {
                    continue;
                }
                
                // Check if we're starting a trigger
                if (strpos($trimmedLine, 'CREATE TRIGGER') === 0) {
                    $inTrigger = true;
                    $triggerLines = [$trimmedLine];
                    continue;
                }
                
                // If we're in a trigger, collect lines until END
                if ($inTrigger) {
                    $triggerLines[] = $trimmedLine;
                    if (strpos($trimmedLine, 'END;') !== false) {
                        $statements[] = implode("\n", $triggerLines);
                        $inTrigger = false;
                        $triggerLines = [];
                    }
                    continue;
                }
                
                // Regular statements
                $currentStatement .= $trimmedLine . "\n";
                
                // Check if statement ends with semicolon
                if (substr($trimmedLine, -1) === ';') {
                    $statements[] = trim($currentStatement);
                    $currentStatement = '';
                }
            }
            
            // Execute statements in order, with special handling for FTS
            foreach ($statements as $i => $statement) {
                try {
                    log_setup_local("Executing statement " . ($i + 1) . "/" . count($statements));
                    $pdo->exec($statement);
                } catch (Exception $e) {
                    log_setup_local("Failed to execute statement " . ($i + 1) . ": " . $e->getMessage());
                    log_setup_local("Statement: " . substr($statement, 0, 100) . "...");
                    throw $e;
                }
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
        throw $e; // Re-throw to be handled by the caller
    }
}
?> 