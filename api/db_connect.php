<?php
/**
 * Database access: get_db_connection() returns a PDO connection to the SQLite
 * database, creating the database on first use and checking that its schema
 * version matches this code.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/setup_db.php';

/** Must equal `PRAGMA user_version` in db/schema.sql. */
const NOTD_SCHEMA_VERSION = 4;

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
            if (!in_array('sort_key', $columns, true)) {
                throw new SchemaVersionException(
                    'This database uses an older notd schema. Run "php db/migrate_v3.php" once to upgrade it (a backup is kept).'
                );
            }
            $version = 3;
        }
        if ($version > NOTD_SCHEMA_VERSION) {
            throw new SchemaVersionException(
                "This database (schema $version) was written by a newer notd than this one (schema " . NOTD_SCHEMA_VERSION . '). Update notd.'
            );
        }
        notd_migrate($pdo, $version);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink(DB_PATH . '.setup.lock');
    }
}

/**
 * Upgrades the database from $version to NOTD_SCHEMA_VERSION with the steps in
 * db/migrations.php. The file is copied first, and all steps run in one
 * transaction: the upgrade happens completely or not at all.
 */
function notd_migrate(PDO $pdo, int $version): void
{
    $steps = require __DIR__ . '/../db/migrations.php';
    for ($v = $version + 1; $v <= NOTD_SCHEMA_VERSION; $v++) {
        if (!isset($steps[$v])) {
            throw new SchemaVersionException("This database uses schema $version and there is no upgrade path to schema " . NOTD_SCHEMA_VERSION . '.');
        }
    }
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $backup = DB_PATH . '.v' . $version . '-backup';
    if (!is_file($backup) && !@copy(DB_PATH, $backup)) {
        throw new Exception("Could not back up the database to $backup before upgrading it.");
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        for ($v = $version + 1; $v <= NOTD_SCHEMA_VERSION; $v++) {
            $steps[$v]($pdo);
        }
        $pdo->exec('PRAGMA user_version = ' . NOTD_SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw new Exception('Upgrading the database failed (' . $e->getMessage() . "). Nothing was changed; a copy is at $backup.");
    }
    error_log("[notd] database upgraded from schema $version to " . NOTD_SCHEMA_VERSION . "; backup at $backup");
}

/** True if the error means "another connection holds the write lock". */
function notd_is_busy(Throwable $e): bool
{
    return $e instanceof PDOException
        && (in_array((int)($e->errorInfo[1] ?? 0), [5, 6], true) || stripos($e->getMessage(), 'database is locked') !== false);
}

/**
 * Runs $fn inside a write transaction and returns its result.
 *
 * Every change to the database goes through here. The transaction takes the
 * write lock up front (BEGIN IMMEDIATE), so concurrent writers queue instead
 * of failing halfway; if the lock cannot be had, the whole unit is retried.
 * When $fn throws, everything is rolled back and webhooks queued by the
 * failed work are dropped. Nested calls join the outer transaction.
 */
function notd_write(PDO $pdo, callable $fn)
{
    static $depth = 0;
    if ($depth > 0) {
        return $fn($pdo);
    }
    for ($attempt = 1; ; $attempt++) {
        $began = false;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $began = true;
            $depth++;
            try {
                $result = $fn($pdo);
            } finally {
                $depth--;
            }
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            if ($began) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) { /* already rolled back */ }
            }
            if (class_exists('App\PropertyTriggerService', false)) {
                \App\PropertyTriggerService::discardPendingWebhooks();
            }
            if (notd_is_busy($e) && $attempt < 5) {
                usleep(100000 * $attempt);
                continue;
            }
            throw $e;
        }
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
    require_once __DIR__ . '/bootstrap.php';
    notd_write($pdo, static function (PDO $pdo) use ($notes) {
        // The server's local date; the browser opens its own local "today", which is the same for most setups.
        $pageId = (new \App\PageStore($pdo))->ensure(date('Y-m-d'));
        (new \App\NoteOps($pdo))->appendTree($pageId, $notes);
    });
}
