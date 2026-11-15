<?php
/**
 * Tests for the Logseq importer (api/LogseqImport.php), on throw-away
 * databases and upload folders:
 *
 *     php tests/php/logseq_import.test.php
 *
 * Each scenario runs in its own PHP process, because the database path and
 * the uploads folder are constants.
 */

$fixture = realpath(__DIR__ . '/../fixtures/logseq-graph');

// ------------------------------------------------------------------ child
if (($argv[1] ?? '') === '--scenario') {
    [, , $scenario, $tmp] = $argv;
    define('UPLOADS_DIR', getenv('TEST_UPLOADS_DIR') ?: "$tmp/uploads");
    require_once __DIR__ . '/../../api/bootstrap.php';
    $pdo = get_db_connection();
    $run = static function (array $options = [], ?string $graph = null) use ($pdo, $fixture) {
        return (new App\LogseqImport($pdo, $options))->run($graph ?? $fixture);
    };
    $result = (require __DIR__ . '/logseq_import.scenarios.php')[$scenario]($run, $pdo, $tmp, $fixture);
    echo json_encode($result);
    exit(0);
}

// ----------------------------------------------------------------- parent
$failures = 0;
$checks = 0;
function check(bool $ok, string $what): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "  FAIL: $what\n";
    }
}

function scenario(string $name, array $env = []): array
{
    $tmp = sys_get_temp_dir() . '/notd-logseq-test-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0777, true);
    $envs = array_merge(getenv(), ['NOTD_DB_PATH' => "$tmp/db.sqlite"], $env);
    $cmd = [PHP_BINARY, __FILE__, '--scenario', $name, $tmp];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $envs);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $data = json_decode($out, true);
    if (!is_array($data)) {
        echo "  scenario $name crashed:\n$out\n$err\n";
        $data = [];
    }
    $data['_tmp'] = $tmp;
    return $data;
}

function files_in(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $out[] = $f->getPathname();
    }
    return $out;
}

echo "parsing\n";
$r = scenario('parsing');
foreach ($r['checks'] ?? [] as $what => $ok) {
    check($ok, $what);
}

echo "fresh import\n";
$r = scenario('fresh');
$n = $r['notes'] ?? [];
$p = $r['pages'] ?? [];
check(($r['report']['failed'] ?? null) === [], 'no page fails');
check(($r['report']['pages_created'] ?? 0) === 10, 'ten pages created');
check(array_key_exists('2023-11-14', $p) && array_key_exists('2023-11-13', $p), 'journal pages are named by date');
check(count(array_intersect_key($p, array_flip(['Project Alpha', 'Projects/Alpha/Notes', 'Questions: what?', 'Weird (x) name']))) === 4, 'page names: title::, ___ namespaces, %-escapes, brackets');
check(!isset($p['ignored']) && !isset($p['orgpage']), 'logseq/bak and .org files are not imported');
check(($p['Project Alpha'] ?? '') === "{alias::Alpha, PA}\n{tags::project, [[Big Topic]]}\n{favorite::true}", 'page properties without title::, favorite from config.edn');
check(($p['Ola Nordmann'] ?? '') === "{type::person}\n{role::tester}", 'a first block of only properties holds the page properties');
check(in_array('TODO Write the report {priority::A} {scheduled::2023-11-14 09:00}', $n, true), 'LATER, priority, SCHEDULED; logbook dropped');
check(in_array('DOING Reviewing [[Project Alpha]] with [[design]] and [[Big Topic]] {deadline::2023-11-20}', $n, true), 'NOW, #tag, #[[tag]], DEADLINE');
check(in_array('CANCELLED Old idea', $n, true) && in_array('WAITING On hold', $n, true), 'CANCELED and WAIT');
check(in_array('Link to yesterday [[2023-11-13]] and a page with brackets [[Weird (x) name]]', $n, true), 'journal title links and renamed pages');
check(in_array('Yesterday entry referencing !{{6553a1b2-0000-4000-8000-000000000001}}', $n, true), '((block ref)) becomes an embed');
check(in_array('!{{6553a1b2-0000-4000-8000-000000000001}}', $n, true), '{{embed ((id))}}');
check(($r['ids']['6553a1b2-0000-4000-8000-000000000001'] ?? '') === 'TODO Write the report {priority::A} {scheduled::2023-11-14 09:00}', 'Logseq id:: becomes the note id');
check(in_array('ref to unknown ((6553a1b2-0000-4000-8000-00000000dead))', $n, true), 'unknown refs stay as text');
check(in_array("Code sample:\n```python\n- this is not a bullet\n# neither is this #notatag\n```\nafter the fence", $n, true), 'code blocks are kept exactly, dashes inside are not bullets');
check(in_array('> Quoted text', $n, true), '#+BEGIN_QUOTE');
check(in_array('## Overview with heading', $n, true), 'heading:: 2');
check(in_array('links to [[Project Alpha]] alias', $n, true), 'alias links go to the page');
check(in_array('URL with hash https://example.com/page#section and colour #fff', $n, true), 'URL fragments and colours are not tags');
check(in_array('Body line with key-looking text: not:: a property {status::active} {owner::[[Ola Nordmann]]}', $n, true), 'block properties, mid-line :: is text');
check(!in_array('', $n, true), 'empty placeholder blocks are skipped');
check(in_array('BOM and CRLF file', $n, true), 'BOM and CRLF');
check(in_array('Some text before any bullet', $n, true) && in_array('nested star', $n, true), 'plain Markdown with * bullets');
check(($r['collapsed'] ?? null) === ['collapsed parent'], 'collapsed:: true');
check(($r['created']['Ørret og blåbær 🐟'] ?? '') === '2023-11-14 22:13:19', 'created-at:: sets the creation time');
check(($r['children']['real child after code'] ?? '') === "Code sample:\n```python\n- this is not a bullet\n# neither is this #notatag\n```\nafter the fence", 'nesting after a code block');
check(($r['children']['hidden child'] ?? '') === 'collapsed parent', 'nesting with mixed tabs and spaces');
check(count($r['attachments'] ?? []) === 4, 'four attachments stored (the same image twice is stored once; svg and missing files are skipped)');
check(count(files_in($r['_tmp'] . '/uploads')) === 4, 'four files in uploads');
$byName = array_column($r['attachments'] ?? [], null, 'name');
check(isset($byName['my diagram.png'], $byName['report_1700000000001_0.pdf'], $byName['2023-11-01-10-00-00.excalidraw']), 'assets with spaces, PDFs, Excalidraw drawings');
// The test's uploads folder is outside notd, so files are served through attachments.php.
$img = 'api/v1/attachments.php?file=' . rawurlencode($byName['image_1700000000000_0.png']['path'] ?? '?');
check(in_array("Screenshot ![image.png]($img)", $n, true), 'image link points at the copy, Logseq size attributes dropped');
check(in_array("Same image again ![again]($img)", $n, true), 'a second link to the same file reuses the copy');
check(in_array('Not allowed type ![logo](../assets/logo.svg) and missing ![x](../assets/nope.png)', $n, true), 'links to skipped files are left alone');
check(count(array_filter($r['report']['warnings'] ?? [], fn($w) => strpos($w, 'used twice') !== false)) === 1, 'duplicate block ids are reported');

echo "re-run (merge)\n";
$r = scenario('rerun');
check(($r['second']['notes_created'] ?? -1) === 0 && ($r['second']['attachments_stored'] ?? -1) === 0, 'a second run adds nothing');
check(($r['counts_before'] ?? 1) === ($r['counts_after'] ?? 2), 'row counts unchanged after a second run');
check(($r['files'] ?? 0) === 4, 'no extra files after a second run');
check(($r['edited']['notes_created'] ?? 0) === 2, 'after editing a file, only the new and the changed note are added');
check(($r['welcome_kept'] ?? false) === true, 'notes already in notd are kept');

echo "replace\n";
$r = scenario('replace');
check(($r['report']['notes_created'] ?? 0) === 36, 'replace recreates every note');
check(($r['same_outline'] ?? false) === true, 'replace gives the same outline as a fresh import');
check(($r['files'] ?? 0) === 4, 'old attachment files are removed after replacing');
check(($r['edited_page'] ?? []) === ['- edited'], 'replace makes the page match its file');

echo "dry run\n";
$r = scenario('dry');
check(($r['report']['notes_created'] ?? 0) === 36, 'dry run reports what would be created');
check(($r['changed'] ?? true) === false, 'dry run changes no row');
check(count(files_in($r['_tmp'] . '/uploads')) === 0, 'dry run copies no file');

echo "failures\n";
$blocked = sys_get_temp_dir() . '/notd-logseq-test-blocker-' . bin2hex(random_bytes(4));
file_put_contents($blocked, 'a file, so no folder can be made below it');
$r = scenario('failure', ['TEST_UPLOADS_DIR' => "$blocked/uploads"]);
check(array_column($r['report']['failed'] ?? [], 'file') === ['journals/2023_11_14.md'], 'the page whose attachments cannot be stored fails alone');
check(($r['has_failed_page'] ?? true) === false, 'the failed page is rolled back completely');
check(($r['other_pages'] ?? 0) === 9, 'the other pages are imported');
$r = scenario('strict', ['TEST_UPLOADS_DIR' => "$blocked/uploads"]);
check(($r['threw'] ?? false) === true && ($r['pages'] ?? -1) === 0, '--strict rolls everything back');
unlink($blocked);

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
