<?php
// The database: where it lives, opening it, and the one way to write to it.

declare(strict_types=1);

const NOTD_SCHEMA_VERSION = 1;

final class Config
{
    public static string $file = __DIR__ . '/../config.local.php';

    /** A setting: $NOTD_<KEY> if set, else the key in config.local.php, else $default. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv('NOTD_' . strtoupper($key));
        if ($env !== false && $env !== '') {
            return $env;
        }
        $config = is_file(self::$file) ? (require self::$file) : [];
        return is_array($config) && isset($config[$key]) ? (string) $config[$key] : $default;
    }

    /** The data folder: data_dir, else data/ next to index.php. Created if missing. */
    public static function dataDir(): string
    {
        $dir = (string) self::get('data_dir', dirname(__DIR__) . '/data');
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create the data folder $dir");
        }
        return $dir;
    }
}

final class Db
{
    private static ?PDO $shared = null;
    private static array $depth = [];

    /** Retries of a write that finds the database locked, and the first pause in milliseconds (doubled each time). */
    public static int $retries = 5;
    public static int $retryPauseMs = 20;

    /** The connection for this request, opened (and set up) on first use. */
    public static function get(): PDO
    {
        return self::$shared ??= self::open(Config::dataDir() . '/notd.sqlite');
    }

    public static function open(string $file): PDO
    {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 2000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::setUp($pdo);
        return $pdo;
    }

    private static function setUp(PDO $pdo): void
    {
        $hasMeta = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'meta'")->fetchColumn();
        if ($hasMeta) {
            $version = (int) $pdo->query("SELECT value FROM meta WHERE key = 'schema_version'")->fetchColumn();
            if ($version !== NOTD_SCHEMA_VERSION) {
                throw new RuntimeException("The database has schema version $version; this notd needs "
                    . NOTD_SCHEMA_VERSION . '. Export it with the notd that made it and import the Markdown.');
            }
            return;
        }
        self::write($pdo, function (PDO $pdo): void {
            $pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
            $set = $pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)');
            $set->execute(['schema_version', (string) NOTD_SCHEMA_VERSION]);
            $set->execute(['created', (string) now_ms()]);
        });
    }

    /**
     * Runs $fn($pdo) in a write transaction and returns its result. BEGIN IMMEDIATE takes
     * the write lock up front, so two writers never deadlock; a locked database is retried
     * with a growing pause. An exception rolls everything back and is rethrown. A write
     * inside a write simply joins the outer transaction.
     */
    public static function write(PDO $pdo, callable $fn): mixed
    {
        $key = spl_object_id($pdo);
        if ((self::$depth[$key] ?? 0) > 0) {
            return $fn($pdo);
        }
        $pause = self::$retryPauseMs;
        for ($attempt = 0; ; $attempt++) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                break;
            } catch (PDOException $e) {
                if (!self::isBusy($e) || $attempt >= self::$retries) {
                    throw $e;
                }
                usleep($pause * 1000);
                $pause *= 2;
            }
        }
        self::$depth[$key] = 1;
        try {
            $result = $fn($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            self::$depth[$key] = 0;
        }
    }

    private static function isBusy(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'SQLITE_BUSY');
    }
}

function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}
