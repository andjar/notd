<?php
require_once __DIR__ . '/support/server.php';

cases('title_key folds case in any language and normalises', [
    'Norwegian'        => ['Østfold', 'østfold'],
    'Greek'            => ['ΣΟΦΙΑ', 'σοφια'],
    'Cyrillic'         => ['Москва', 'москва'],
    'composed accents' => ["Cafe\u{0301}", 'Café'],          // e + combining accent vs é
    'spaces'           => ["  Project \t Alpha ", 'project alpha'],
], function (string $a, string $b) {
    eq(title_key($b), title_key($a));
});

test('title_key keeps diacritics apart', function () {
    ok(title_key('Ålesund') !== title_key('Alesund'));
});

cases('clean_title refuses what cannot be a title', [
    'empty'    => [''],
    'spaces'   => ['   '],
    'brackets' => ['a [[b]]'],
    'control'  => ["a\x01b"],
    'too long' => [str_repeat('x', TITLE_MAX + 1)],
    'bad utf8' => ["\xC3\x28"],
], function (string $title) {
    throws(fn() => clean_title($title), HttpError::class);
});

test('new_id makes version 7 UUIDs that sort by time', function () {
    $a = new_id();
    usleep(2000);
    $b = new_id();
    ok(is_id($a) && is_id($b), "$a $b");
    eq('7', $a[14]);
    ok(in_array($a[19], ['8', '9', 'a', 'b'], true), 'variant');
    ok(strcmp($a, $b) < 0, 'later id sorts after');
});

test('a page is created once per title, whatever the case', function () {
    $pdo = fresh_db();
    $page = Nodes::createPage($pdo, 'Østfold');
    eq('page', $page['kind']);
    eq($page['id'], $page['page']);
    eq(null, $page['parent']);
    eq('Østfold', Nodes::byTitle($pdo, 'ØSTFOLD')['title']);
    $e = throws(fn() => Nodes::createPage($pdo, 'østfold'), HttpError::class);
    eq('title_taken', $e->codeName);
    eq(409, $e->status);
});

test('a page keeps the id it is given, and refuses a taken or malformed id', function () {
    $pdo = fresh_db();
    $id = new_id();
    eq($id, Nodes::createPage($pdo, 'A', $id)['id']);
    eq('id_taken', throws(fn() => Nodes::createPage($pdo, 'B', $id), HttpError::class)->codeName);
    eq('bad_id', throws(fn() => Nodes::createPage($pdo, 'C', 'ABC'), HttpError::class)->codeName);
});

test('blocks nest under pages and blocks and know their page', function () {
    $pdo = fresh_db();
    $page = Nodes::createPage($pdo, 'Journal');
    $a = Nodes::createBlock($pdo, $page['id'], 'first', 'a1');
    $b = Nodes::createBlock($pdo, $page['id'], 'second', 'a0');
    $child = Nodes::createBlock($pdo, $a['id'], 'child', 'a0');
    eq($page['id'], $child['page']);
    eq($a['id'], $child['parent']);
    eq(['second', 'first'], array_column(Nodes::children($pdo, $page['id']), 'text'));
    eq(['child'], array_column(Nodes::children($pdo, $a['id']), 'text'));
    ok(!isset($a['title']), 'blocks have no title');
});

test('a block needs an existing page or block as parent', function () {
    $pdo = fresh_db();
    eq('not_found', throws(fn() => Nodes::createBlock($pdo, new_id(), 'x', 'a0'), HttpError::class)->codeName);
    $doc = Nodes::createPage($pdo, 'Essay', null, 'doc');
    eq('bad_parent', throws(fn() => Nodes::createBlock($pdo, $doc['id'], 'x', 'a0'), HttpError::class)->codeName);
});

test('nodes come back in the protocol shape', function () {
    $pdo = fresh_db();
    $page = Nodes::createPage($pdo, 'Shape');
    eq(['id', 'kind', 'parent', 'page', 'sort', 'text', 'collapsed', 'clock', 'created', 'updated', 'title'], array_keys($page));
    ok(is_int($page['created']) && $page['created'] > 1700000000000, 'milliseconds');
    eq(false, $page['collapsed']);
});

test('line breaks and runs of spaces in a title become one space', function () {
    eq('a b', clean_title("a\n \tb"));
});
