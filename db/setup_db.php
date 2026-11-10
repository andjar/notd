<?php
/**
 * Creates the schema in an empty database.
 */

/**
 * @throws Exception if schema.sql cannot be read or applied.
 */
function run_database_setup(PDO $pdo): void
{
    $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
    if ($schemaSql === false) {
        throw new Exception('Could not read schema.sql.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec($schemaSql); // exec runs every statement in the file
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
