<?php
require_once __DIR__ . '/support/server.php';

test('a new database gets the schema, WAL and a schema version', function () {
    $pdo = fresh_db();
    eq('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
    eq(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    eq((string) NOTD_SCHEMA_VERSION, $pdo->query("SELECT value FROM meta WHERE key = 'schema_version'")->fetchColumn());
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'search_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    eq(['applied_ops', 'changes', 'fields', 'files', 'jobs', 'links', 'meta', 'nodes', 'search'], $tables);
});

test('opening an existing database keeps its data', function () {
    $pdo = fresh_db();
    $pdo->exec("INSERT INTO meta (key, value) VALUES ('probe', 'kept')");
    $again = Db::open(db_file($pdo));
    eq('kept', $again->query("SELECT value FROM meta WHERE key = 'probe'")->fetchColumn());
});

test('a database with another schema version is refused', function () {
    $pdo = fresh_db();
    $pdo->exec("UPDATE meta SET value = '99' WHERE key = 'schema_version'");
    throws(fn() => Db::open(db_file($pdo)), RuntimeException::class, 'schema version 99');
});

test('write commits and returns the result', function () {
    $pdo = fresh_db();
    $result = Db::write($pdo, function (PDO $pdo) {
        $pdo->exec("INSERT INTO meta (key, value) VALUES ('a', '1')");
        return 'done';
    });
    eq('done', $result);
    eq('1', $pdo->query("SELECT value FROM meta WHERE key = 'a'")->fetchColumn());
    ok(!$pdo->inTransaction(), 'no open transaction');
});

test('write rolls back everything when the function throws', function () {
    $pdo = fresh_db();
    throws(fn() => Db::write($pdo, function (PDO $pdo) {
        $pdo->exec("INSERT INTO meta (key, value) VALUES ('b', '1')");
        throw new DomainException('stop');
    }), DomainException::class, 'stop');
    eq(false, $pdo->query("SELECT value FROM meta WHERE key = 'b'")->fetchColumn());
    ok(!$pdo->inTransaction(), 'no open transaction');
});

test('a write inside a write joins it, and rolls back with it', function () {
    $pdo = fresh_db();
    throws(fn() => Db::write($pdo, function (PDO $pdo) {
        Db::write($pdo, fn(PDO $p) => $p->exec("INSERT INTO meta (key, value) VALUES ('inner', '1')"));
        throw new DomainException('outer fails');
    }), DomainException::class);
    eq(false, $pdo->query("SELECT value FROM meta WHERE key = 'inner'")->fetchColumn());
    Db::write($pdo, fn(PDO $p) => Db::write($p, fn(PDO $q) => $q->exec("INSERT INTO meta (key, value) VALUES ('inner', '2')")));
    eq('2', $pdo->query("SELECT value FROM meta WHERE key = 'inner'")->fetchColumn());
});

test('a locked database is retried, then the write gives up', function () {
    $pdo = fresh_db();
    $other = Db::open(db_file($pdo));
    $other->exec('BEGIN IMMEDIATE');
    $pdo->exec('PRAGMA busy_timeout = 5');
    [$retries, $pause] = [Db::$retries, Db::$retryPauseMs];
    Db::$retries = 3;
    Db::$retryPauseMs = 10;
    try {
        $start = microtime(true);
        throws(fn() => Db::write($pdo, fn() => null), PDOException::class, 'locked');
        $ms = (microtime(true) - $start) * 1000;
        ok($ms >= 70, "waited for the retries (10 + 20 + 40 ms), took $ms ms");
    } finally {
        [Db::$retries, Db::$retryPauseMs] = [$retries, $pause];
        $other->exec('ROLLBACK');
    }
    eq('ok', Db::write($pdo, fn() => 'ok'), 'works once the lock is gone');
});

test('the data folder comes from NOTD_DATA_DIR', function () {
    $dir = sys_get_temp_dir() . '/notd-datadir-' . bin2hex(random_bytes(4)) . '/nested';
    putenv("NOTD_DATA_DIR=$dir");
    try {
        eq($dir, Config::dataDir());
        ok(is_dir($dir), 'created');
    } finally {
        putenv('NOTD_DATA_DIR');
        rmdir($dir);
        rmdir(dirname($dir));
    }
});

test('node kinds are checked by the schema', function () {
    $pdo = fresh_db();
    throws(fn() => $pdo->exec("INSERT INTO nodes (id, kind, created, updated) VALUES ('x', 'banana', 0, 0)"), PDOException::class, 'CHECK');
});
