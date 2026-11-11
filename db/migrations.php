<?php
/**
 * In-place schema upgrades, applied automatically by api/db_connect.php.
 *
 * Each entry upgrades a database *to* the version that is its key. To change
 * the schema: edit schema.sql (the schema of a new database), raise its
 * user_version and NOTD_SCHEMA_VERSION, and add the step here that brings an
 * existing database to the same state. Steps run inside one transaction, after
 * the database file has been copied to <name>.v<old>-backup.
 *
 * Version 3 was the last one that needed a manual migration (migrate_v3.php).
 */

return [
    4 => static function (PDO $pdo): void {
        // Full-text search that folds case for all letters, not only ASCII.
        $pdo->exec('DROP TABLE IF EXISTS Notes_fts');
        try {
            $pdo->exec('CREATE VIRTUAL TABLE Notes_fts USING fts4(content, tokenize=unicode61)');
        } catch (Exception $e) {
            $pdo->exec('CREATE VIRTUAL TABLE Notes_fts USING fts4(content)');
        }
        $pdo->exec('INSERT INTO Notes_fts(docid, content) SELECT rid, content FROM Notes');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_properties_name_value_nocase ON Properties(name, value COLLATE NOCASE)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pages_updated_at ON Pages(updated_at)');

        // Journal pages used to get their type as a bare index row; it belongs in the content.
        $pdo->exec("UPDATE Pages SET content = TRIM('{type::journal} ' || COALESCE(content, ''))
                     WHERE name GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                       AND COALESCE(content, '') NOT LIKE '%{type::journal}%'");
        $pdo->exec("INSERT INTO Properties (page_id, name, value, weight)
                    SELECT id, 'type', 'journal', 2 FROM Pages
                     WHERE name GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                       AND NOT EXISTS (SELECT 1 FROM Properties p WHERE p.page_id = Pages.id AND p.name = 'type' AND p.value = 'journal')");
    },
];
