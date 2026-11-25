<?php
require_once __DIR__ . '/support/server.php';

const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

function tmp_file(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'notd-up-');
    file_put_contents($path, $content);
    register_shutdown_function(fn() => @unlink($path));
    return $path;
}

test('a file is stored under files/YYYY/MM by its SHA-256 and gets a node', function () {
    $pdo = fresh_db();
    $png = base64_decode(PNG_1PX);
    $f = Files::store($pdo, tmp_file($png), 'dot.png');
    eq('file', $f['kind']);
    eq('dot.png', $f['title']);
    eq('image/png', $f['mime']);
    eq(strlen($png), $f['size']);
    eq(hash('sha256', $png), $f['sha256']);
    eq('files/' . gmdate('Y/m') . '/' . $f['sha256'] . '.png', $f['path']);
    eq($png, file_get_contents(Config::dataDir() . '/' . $f['path']));
    eq('api/v1/file?id=' . $f['id'], $f['url']);
    eq('create', $pdo->query('SELECT op FROM changes ORDER BY seq DESC LIMIT 1')->fetchColumn());
});

test('the same content is stored once, whatever its name', function () {
    $pdo = fresh_db();
    $a = Files::store($pdo, tmp_file('hello'), 'a.txt');
    $b = Files::store($pdo, tmp_file('hello'), 'b.txt');
    eq($a['id'], $b['id']);
    eq(1, (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    $c = Files::store($pdo, tmp_file('hello'), 'a.txt');
    eq($a['id'], $c['id']);
});

test('two files may share a name', function () {
    $pdo = fresh_db();
    ok(Files::store($pdo, tmp_file('one'), 'notes.txt')['id'] !== Files::store($pdo, tmp_file('two'), 'notes.txt')['id']);
});

cases('wrong types are refused', [
    'unknown extension' => ['MZ fake', 'tool.exe'],
    'no extension'      => ['text', 'README'],
    'content mismatch'  => ['plain text, not a png', 'fake.png'],
    'php in disguise'   => ['<?php echo 1;', 'x.php'],
], function (string $content, string $name) {
    eq('bad_type', throws(fn() => Files::store(fresh_db(), tmp_file($content), $name), HttpError::class)->codeName);
});

test('names lose slashes and control characters', function () {
    eq('evil.txt', Files::store(fresh_db(), tmp_file('x'), "../ev\x01il.txt")['title']);
});

test('a file node can be deleted with an operation, and verify finds the orphan', function () {
    $pdo = fresh_db();
    $f = Files::store($pdo, tmp_file('keep me'), 'k.txt');
    $g = Files::store($pdo, tmp_file('drop me'), 'd.txt');
    Ops::apply($pdo, [['op' => 'delete', 'id' => $g['id']]]);
    $r = Files::verify($pdo);
    eq([1, [], [], [$g['path']]], [$r['checked'], $r['missing'], $r['corrupt'], $r['orphans']]);
    Files::verify($pdo, true);
    ok(!is_file(Config::dataDir() . '/' . $g['path']), 'orphan deleted');
    ok(is_file(Config::dataDir() . '/' . $f['path']), 'the other kept');
});

test('verify finds missing and corrupt files', function () {
    $pdo = fresh_db();
    $a = Files::store($pdo, tmp_file('aaa'), 'a.txt');
    $b = Files::store($pdo, tmp_file('bbb'), 'b.txt');
    unlink(Config::dataDir() . '/' . $a['path']);
    file_put_contents(Config::dataDir() . '/' . $b['path'], 'changed');
    $r = Files::verify($pdo);
    eq([$a['path']], $r['missing']);
    eq([$b['path']], $r['corrupt']);
});

test('a link to a file is indexed', function () {
    $id = '01900000-0000-7000-8000-00000000000f';
    eq([['file', $id]], parse_text("![photo](file:$id)")['links']);
});
