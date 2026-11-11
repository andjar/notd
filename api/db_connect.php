<?php
/**
 * Database access: get_db_connection() returns a PDO connection to the SQLite
 * database, creating the database on first use and checking that its schema
 * version matches this code.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/setup_db.php';
require_once __DIR__ . '/db_helpers.php';

/** Must equal `PRAGMA user_version` in db/schema.sql. */
const NOTD_SCHEMA_VERSION = 3;

class SchemaVersionException extends RuntimeException {}

/**
 * Opens the database. Each request gets its own connection.
 * On failure it answers with a JSON error (or a message on the CLI) and exits.
 */
function get_db_connection(): PDO
{
    try {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new Exception("Failed to create database directory: $dir");
        }

        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA cache_size = 10000;');
        $pdo->exec('PRAGMA temp_store = MEMORY;');

        if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() !== NOTD_SCHEMA_VERSION) {
            notd_prepare_database($pdo);
        }
        return $pdo;
    } catch (Exception $e) {
        error_log('[notd] database unavailable: ' . $e->getMessage());
        $public = $e instanceof SchemaVersionException || (defined('NOTD_DEBUG') && NOTD_DEBUG);
        $message = $public ? $e->getMessage() : 'The database could not be opened. See the server error log.';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    }
}

/**
 * Called when the stored schema version is not the one this code expects:
 * creates the schema in an empty database, stamps databases that predate
 * version stamping, and rejects anything else with instructions.
 */
function notd_prepare_database(PDO $pdo): void
{
    $lock = fopen(DB_PATH . '.setup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new Exception('Could not lock the database for setup.');
    }
    try {
        $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version === NOTD_SCHEMA_VERSION) {
            return; // another request finished the setup while we waited
        }
        $hasTables = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='Pages'")->fetch() !== false;

        if (!$hasTables) {
            run_database_setup($pdo);
            notd_add_welcome_notes($pdo);
            return;
        }
        if ($version === 0) {
            // Created before versions were stamped: v3 has Notes.sort_key, v2 does not.
            $columns = array_column($pdo->query('PRAGMA table_info(Notes)')->fetchAll(), 'name');
            if (in_array('sort_key', $columns, true)) {
                $pdo->exec('PRAGMA user_version = ' . NOTD_SCHEMA_VERSION);
                return;
            }
            throw new SchemaVersionException(
                'This database uses an older notd schema. Run "php db/migrate_v3.php" once to upgrade it (a backup is kept).'
            );
        }
        throw new SchemaVersionException($version > NOTD_SCHEMA_VERSION
            ? "This database (schema $version) was written by a newer notd than this one (schema " . NOTD_SCHEMA_VERSION . '). Update notd.'
            : "This database uses schema $version; this notd needs schema " . NOTD_SCHEMA_VERSION . '. See the upgrade notes in the README.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink(DB_PATH . '.setup.lock');
    }
}

/** Fills today's journal page of a brand-new database with the welcome notes. */
function notd_add_welcome_notes(PDO $pdo): void
{
    $path = __DIR__ . '/../assets/template/page/welcome_notes.json';
    $notes = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    if (!is_array($notes)) {
        return;
    }
    $pdo->beginTransaction();
    try {
        $pageId = _create_page_and_index_properties($pdo, date('Y-m-d'), '{type::journal}');
        foreach ($notes as $content) {
            _create_note_and_index_properties($pdo, (int)$pageId, $content);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
