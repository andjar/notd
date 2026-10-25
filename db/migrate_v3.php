<?php
/**
 * Migrates a notd database from schema v2 (integer note ids, order_index)
 * to v3 (string note ids, fractional sort keys, version, AppliedOps).
 *
 *   php db/migrate_v3.php [path/to/database.sqlite]
 *
 * The original file is kept as <name>.v2-backup-<timestamp>. Existing note ids
 * keep their value as strings ("123"), so transclusions like !{{123}} and
 * #note-123 anchors keep working. Siblings keep their current order
 * (order_index, then id).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/Ordering.php';

use App\Ordering;

$path = $argv[1] ?? DB_PATH;
if (!is_file($path)) {
    fwrite(STDERR, "Database not found: $path\n");
    exit(1);
}

$old = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$cols = array_column($old->query("PRAGMA table_info(Notes)")->fetchAll(), 'name');
if (in_array('sort_key', $cols, true)) {
    echo "Already on schema v3, nothing to do.\n";
    exit(0);
}
$old->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$old = null;

$backup = $path . '.v2-backup-' . date('Ymd-His');
if (!copy($path, $backup)) {
    fwrite(STDERR, "Could not create backup $backup\n");
    exit(1);
}
$tmp = $path . '.v3-tmp';
@unlink($tmp);

$db = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(__DIR__ . '/schema.sql'));
$db->exec('PRAGMA foreign_keys = OFF');
$db->exec("ATTACH DATABASE " . $db->quote($backup) . " AS old");
$db->beginTransaction();

$copyTable = function (string $table, array $exclude = []) use ($db) {
    $newCols = array_column($db->query("PRAGMA main.table_info($table)")->fetchAll(), 'name');
    $oldCols = array_column($db->query("PRAGMA old.table_info($table)")->fetchAll(), 'name');
    $common = array_values(array_diff(array_intersect($newCols, $oldCols), $exclude));
    if (!$common) return 0;
    $list = implode(', ', $common);
    return $db->exec("INSERT INTO main.$table ($list) SELECT $list FROM old.$table");
};

$tables = array_column($db->query("SELECT name FROM old.sqlite_master WHERE type='table'")->fetchAll(), 'name');
$counts = [];
foreach (['Pages', 'Webhooks', 'WebhookEvents'] as $t) {
    if (in_array($t, $tables, true)) $counts[$t] = $copyTable($t);
}

// Notes: ids become strings; order_index becomes a fractional key per sibling group.
$notes = $db->query("SELECT * FROM old.Notes ORDER BY page_id, parent_note_id, order_index, id")->fetchAll();
$groups = [];
foreach ($notes as $n) {
    $groups[$n['page_id'] . '|' . ($n['parent_note_id'] ?? '')][] = $n;
}
$ins = $db->prepare("INSERT INTO main.Notes (id, page_id, parent_note_id, sort_key, content, internal, collapsed, active, version, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
$known = array_flip(array_map(fn($n) => (string)$n['id'], $notes));
foreach ($groups as $group) {
    $keys = Ordering::keysBetween(null, null, count($group));
    foreach ($group as $i => $n) {
        $parent = $n['parent_note_id'] !== null ? (string)$n['parent_note_id'] : null;
        if ($parent !== null && !isset($known[$parent])) $parent = null; // orphan -> top level
        $ins->execute([(string)$n['id'], $n['page_id'], $parent, $keys[$i], $n['content'], $n['internal'] ?? 0,
            $n['collapsed'] ?? 0, $n['active'] ?? 1, $n['created_at'], $n['updated_at']]);
    }
}
$counts['Notes'] = count($notes);

// Break cycles that the old API allowed: any note whose ancestor chain loops is moved to the top level.
$parents = [];
foreach ($db->query("SELECT id, parent_note_id FROM main.Notes")->fetchAll() as $r) $parents[$r['id']] = $r['parent_note_id'];
$fix = $db->prepare("UPDATE main.Notes SET parent_note_id = NULL,
    sort_key = (SELECT MAX(r.sort_key) FROM main.Notes r WHERE r.page_id = main.Notes.page_id AND r.parent_note_id IS NULL) WHERE id = ?");
$bump = $db->prepare("UPDATE main.Notes SET sort_key = ? WHERE id = ?");
$cycles = 0;
foreach (array_keys($parents) as $start) {
    $seen = [];
    $cur = (string)$start;
    while ($cur !== null && $cur !== '' && isset($parents[$cur])) {
        if (isset($seen[$cur])) {
            $fix->execute([$cur]);
            $max = $db->query("SELECT sort_key FROM main.Notes WHERE id = " . $db->quote($cur))->fetchColumn();
            $bump->execute([Ordering::keyBetween($max ?: null, null), $cur]); // after the last top-level note
            $parents[$cur] = null;
            $cycles++;
            break;
        }
        $seen[$cur] = true;
        $cur = $parents[$cur] !== null ? (string)$parents[$cur] : null;
    }
}

if (in_array('Properties', $tables, true)) {
    $counts['Properties'] = $db->exec("INSERT INTO main.Properties (id, note_id, page_id, name, value, weight, active, created_at, updated_at)
        SELECT id, CASE WHEN note_id IS NULL THEN NULL ELSE CAST(note_id AS TEXT) END, page_id, name, value, weight, active, created_at, updated_at
        FROM old.Properties WHERE note_id IS NULL OR CAST(note_id AS TEXT) IN (SELECT id FROM main.Notes)");
}
if (in_array('Attachments', $tables, true)) {
    $counts['Attachments'] = $db->exec("INSERT INTO main.Attachments (id, note_id, name, path, type, size, active, created_at)
        SELECT id, CASE WHEN note_id IS NULL OR CAST(note_id AS TEXT) NOT IN (SELECT id FROM main.Notes) THEN NULL ELSE CAST(note_id AS TEXT) END,
               name, path, type, size, active, created_at FROM old.Attachments");
}

$db->commit();
$db->exec('DETACH DATABASE old');
$bad = $db->query("PRAGMA foreign_key_check")->fetchAll();
$db = null;

if ($bad) {
    fwrite(STDERR, "Foreign key check failed; original left untouched. Details: " . json_encode(array_slice($bad, 0, 5)) . "\n");
    exit(1);
}
foreach (['-wal', '-shm'] as $suffix) @unlink($path . $suffix);
if (!rename($tmp, $path)) {
    fwrite(STDERR, "Could not replace $path (new database left at $tmp)\n");
    exit(1);
}
echo "Migrated to schema v3: " . json_encode($counts) . ($cycles ? ", broke $cycles cycle(s)" : '') . "\nBackup: $backup\n";
