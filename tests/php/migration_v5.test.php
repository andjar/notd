<?php
/**
 * Upgrading a schema-4 database to 5 re-derives the index of tags, aliases
 * and ((uuid)) references, and changes no content.
 *
 *     php tests/php/migration_v5.test.php
 */

if (($argv[1] ?? '') === '--open') {
    // Child process: opening the database runs the migration.
    require_once __DIR__ . '/../../api/bootstrap.php';
    get_db_connection();
    exit(0);
}

$dir = sys_get_temp_dir() . '/notd-migration-' . bin2hex(random_bytes(4));
mkdir($dir);
$db = "$dir/db.sqlite";

// A database as schema 4 left it: one row for {alias::A, B}, no links for tags.
$pdo = new PDO("sqlite:$db");
$pdo->exec(str_replace('PRAGMA user_version = 5;', 'PRAGMA user_version = 4;', file_get_contents(__DIR__ . '/../../db/schema.sql')));
$pdo->exec("INSERT INTO Pages (id, name, content) VALUES (1, 'Target', '{alias::Tgt, T2}'), (2, 'Other', NULL)");
$pdo->exec("INSERT INTO Properties (page_id, name, value, weight) VALUES (1, 'alias', 'Tgt, T2', 2)");
$ref = '0192f6a0-7c3e-7b1a-9f3e-2b1c4d5e6f70';
$pdo->exec("INSERT INTO Notes (id, page_id, sort_key, content) VALUES
    ('$ref', 2, 'a0', 'referenced'),
    ('n2', 2, 'a1', 'tagged {tag::Target} and {tags::x, y}'),
    ('n3', 2, 'a2', 'see (($ref))'),
    ('n4', 2, 'a3', 'plain')");
$pdo->exec("INSERT INTO Properties (note_id, name, value, weight) VALUES ('n2', 'tag', 'Target', 2), ('n2', 'tags', 'x, y', 2)");
$before = $pdo->query('SELECT id, content FROM Notes ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo = null;

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --open 2>&1';
putenv("NOTD_DB_PATH=$db");
exec($cmd, $out, $status);

$pdo = new PDO("sqlite:$db");
$props = static function (string $where) use ($pdo): array {
    return $pdo->query("SELECT name || '=' || value FROM Properties WHERE $where ORDER BY name, value")->fetchAll(PDO::FETCH_COLUMN);
};
$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) {
        $failures++;
        echo "  FAIL: $what\n";
    }
};
$check($status === 0, 'the database opens: ' . implode(' ', $out));
$check((int)$pdo->query('PRAGMA user_version')->fetchColumn() === 5, 'upgraded to schema 5');
$check($props('page_id = 1') === ['alias=T2', 'alias=Tgt'], 'aliases indexed one by one: ' . implode(', ', $props('page_id = 1')));
$check($props("note_id = 'n2'") === ['links_to_page=Target', 'links_to_page=x', 'links_to_page=y', 'tag=Target', 'tags=x', 'tags=y'], 'tags split and linked: ' . implode(', ', $props("note_id = 'n2'")));
$check($props("note_id = 'n3'") === ["references_block=$ref"], 'inline reference indexed: ' . implode(', ', $props("note_id = 'n3'")));
$check($props("note_id = 'n4'") === [], 'other notes untouched');
$check($pdo->query('SELECT id, content FROM Notes ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) === $before, 'no content changed');
$check(is_file("$db.v4-backup"), 'a backup was kept');

echo $failures ? "$failures failed\n" : "migration to schema 5: ok\n";
array_map('unlink', glob("$dir/*"));
rmdir($dir);
exit($failures ? 1 : 0);
