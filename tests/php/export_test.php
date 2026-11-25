<?php
require_once __DIR__ . '/support/server.php';

const EP = '01900000-0000-7000-8000-0000000000e1';
const E1 = '01900000-0000-7000-8000-000000000001';
const E2 = '01900000-0000-7000-8000-000000000002';
const E3 = '01900000-0000-7000-8000-000000000003';

function notebook(): PDO
{
    $pdo = fresh_db();
    Ops::apply($pdo, [
        ['op' => 'create', 'kind' => 'page', 'id' => EP, 'title' => 'Østfold', 'text' => "{alias:: Ostfold}\n- not a block"],
        ['op' => 'create', 'id' => E1, 'parent' => EP, 'text' => 'Fredrikstad', 'sort' => 'a0'],
        ['op' => 'create', 'id' => E2, 'parent' => E1, 'text' => "Old town\nwith a second line\n\nand a paragraph", 'sort' => 'a0', 'collapsed' => true],
        ['op' => 'create', 'id' => E3, 'parent' => EP, 'text' => 'See ((' . E1 . '))', 'sort' => 'a1'],
    ]);
    return $pdo;
}

function export_dir(): string
{
    return Config::dataDir() . '/export';
}

test('a page becomes a bulleted outline with its title, text, ids and collapsed state', function () {
    $pdo = notebook();
    eq(['written' => 1, 'unchanged' => 0, 'deleted' => 0], Markdown::export($pdo, export_dir()));
    eq(implode("\n", [
        'title:: Østfold',
        '{alias:: Ostfold}',
        '\- not a block',
        '',
        '- Fredrikstad',
        '  id:: ' . E1,
        '  - Old town',
        '    collapsed:: true',
        '    with a second line',
        '',
        '    and a paragraph',
        '- See ((' . E1 . '))',
        '',
    ]), file_get_contents(export_dir() . '/pages/Østfold.md'));
});

test('exporting again writes nothing; a change rewrites only its page; a deleted page is removed', function () {
    $pdo = notebook();
    $other = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $other, 'title' => 'Other'], ['op' => 'create', 'parent' => $other, 'text' => 'x']]);
    Markdown::export($pdo, export_dir());
    $before = filemtime(export_dir() . '/pages/Other.md');
    eq(['written' => 0, 'unchanged' => 2, 'deleted' => 0], Markdown::export($pdo, export_dir()));
    Ops::apply($pdo, [['op' => 'edit', 'id' => E3, 'text' => 'changed']]);
    eq(['written' => 1, 'unchanged' => 1, 'deleted' => 0], Markdown::export($pdo, export_dir()));
    eq($before, filemtime(export_dir() . '/pages/Other.md'));
    Ops::apply($pdo, [['op' => 'delete', 'id' => $other]]);
    eq(['written' => 0, 'unchanged' => 1, 'deleted' => 1], Markdown::export($pdo, export_dir()));
    ok(!is_file(export_dir() . '/pages/Other.md'));
});

test('journals go to journals/, and empty journal days are left out', function () {
    $pdo = fresh_db();
    Read::page($pdo, '2026-11-24');
    $id = Read::page($pdo, '2026-11-25')['page']['id'];
    Ops::apply($pdo, [['op' => 'create', 'parent' => $id, 'text' => 'wrote']]);
    Markdown::export($pdo, export_dir());
    eq(["title:: 2026-11-25\n\n- wrote\n"], array_map('file_get_contents', glob(export_dir() . '/journals/*.md')));
});

cases('file names are safe on every file system', [
    'plain'      => ['Project Alpha', 'pages/Project Alpha.md'],
    'slash'      => ['A/B: c?', 'pages/A%2FB%3A c%3F.md'],
    'percent'    => ['100%', 'pages/100%25.md'],
    'dot start'  => ['.hidden', 'pages/%2Ehidden.md'],
    'journal'    => ['2026-11-25', 'journals/2026-11-25.md'],
    'not a date' => ['2026-13-45', 'pages/2026-13-45.md'],
], function (string $title, string $file) {
    eq($file, Markdown::fileName(['title' => $title]));
});

test('very long titles are cut and made unique with a hash', function () {
    $a = Markdown::fileName(['title' => str_repeat('æ', 150) . 'a']);
    $b = Markdown::fileName(['title' => str_repeat('æ', 150) . 'b']);
    ok($a !== $b && strlen(basename($a)) <= 210, $a);
    ok(mb_check_encoding($a, 'UTF-8'), 'cut on a character boundary');
});

test('stored files are copied and links to them become relative paths', function () {
    $pdo = notebook();
    $tmp = tempnam(sys_get_temp_dir(), 'notd');
    file_put_contents($tmp, 'file body');
    $f = Files::store($pdo, $tmp, 'note.txt');
    unlink($tmp);
    Ops::apply($pdo, [['op' => 'edit', 'id' => E3, 'text' => "Read [this](file:{$f['id']}) and [that](file:01900000-0000-7000-8000-0000000000ff)"]]);
    eq(['written' => 2, 'unchanged' => 0, 'deleted' => 0], Markdown::export($pdo, export_dir()));
    eq('file body', file_get_contents(export_dir() . '/' . $f['path']));
    ok(str_contains(file_get_contents(export_dir() . '/pages/Østfold.md'),
        "- Read [this](../{$f['path']}) and [that](file:01900000-0000-7000-8000-0000000000ff)"), 'unknown file ids stay as they are');
    eq(['written' => 0, 'unchanged' => 2, 'deleted' => 0], Markdown::export($pdo, export_dir()));
});
