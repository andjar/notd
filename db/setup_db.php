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
        $pdo->exec(notd_portable_schema($pdo, $schemaSql)); // exec runs every statement in the file
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * The schema asks for the unicode61 full-text tokenizer. A few SQLite builds
 * lack it; for those the default tokenizer is used (search then folds case
 * for ASCII letters only).
 */
function notd_portable_schema(PDO $pdo, string $sql): string
{
    try {
        $pdo->exec('CREATE VIRTUAL TABLE temp.notd_tokenizer_probe USING fts4(x, tokenize=unicode61)');
        $pdo->exec('DROP TABLE temp.notd_tokenizer_probe');
        return $sql;
    } catch (Exception $e) {
        return preg_replace('/,\s*tokenize=unicode61/', '', $sql);
    }
}
