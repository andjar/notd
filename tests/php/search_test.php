<?php
require_once __DIR__ . '/support/server.php';

function corpus(): PDO
{
    $pdo = fresh_db();
    $p = new_id();
    $q = new_id();
    Ops::apply($pdo, [
        ['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'Kitchen renovation'],
        ['op' => 'create', 'parent' => $p, 'text' => 'Choose tiles for the kitchen floor'],
        ['op' => 'create', 'parent' => $p, 'text' => 'Bathroom tiles are cheaper'],
        ['op' => 'create', 'parent' => $p, 'text' => 'Trip to Ålesund in May'],
        ['op' => 'create', 'parent' => $p, 'text' => 'Alesund spelled without the ring'],
        ['op' => 'create', 'kind' => 'page', 'id' => $q, 'title' => 'Notes'],
        ['op' => 'create', 'parent' => $q, 'text' => '<script>alert(1)</script> kitchen'],
    ]);
    return $pdo;
}

function found(PDO $pdo, string $q): array
{
    return array_map(fn($r) => $r['node']['title'] ?? $r['node']['text'], Search::query($pdo, $q));
}

cases('ftsQuery turns typing into an FTS5 query', [
    'words are prefixes'   => ['kit til', '"kit"* "til"*'],
    'phrase'               => ['"kitchen floor"', '"kitchen floor"'],
    'exclusion'            => ['tiles -bath', '"tiles"* NOT "bath"*'],
    'punctuation dropped'  => ['AND OR NEAR( * ^', '"AND"* "OR"* "NEAR"*'],
    'only exclusions'      => ['-bath', null],
    'empty'                => ['  ', null],
], function (string $typed, ?string $fts) {
    eq($fts, Search::ftsQuery($typed));
});

test('words match as prefixes in any order, titles first', function () {
    $pdo = corpus();
    $hits = found($pdo, 'kitch');
    eq('Kitchen renovation', $hits[0], 'the title match first');
    $rest = array_slice($hits, 1);
    sort($rest);
    eq(['<script>alert(1)</script> kitchen', 'Choose tiles for the kitchen floor'], $rest);
    eq(['Choose tiles for the kitchen floor'], found($pdo, 'floor kitchen'));
});

test('phrases and exclusions', function () {
    $pdo = corpus();
    eq(['Choose tiles for the kitchen floor'], found($pdo, '"kitchen floor"'));
    eq([], found($pdo, '"floor kitchen"'));
    eq(['Choose tiles for the kitchen floor'], found($pdo, 'tiles -bathroom'));
});

test('case does not matter but diacritics do', function () {
    $pdo = corpus();
    eq(['Trip to Ålesund in May'], found($pdo, 'ålesund'));
    eq(['Trip to Ålesund in May'], found($pdo, 'ÅLESUND'));
    eq(['Alesund spelled without the ring'], found($pdo, 'alesund'));
});

test('snippets are HTML-escaped with marked matches', function () {
    $r = Search::query(corpus(), 'kitchen');
    $script = array_values(array_filter($r, fn($x) => str_contains($x['node']['text'], 'script')))[0];
    eq('&lt;script&gt;alert(1)&lt;/script&gt; <mark>kitchen</mark>', $script['snippet']);
    eq('Notes', $script['pageTitle']);
    eq('<mark>Kitchen</mark> renovation', $r[0]['snippet']);
});

test('edits, renames and deletes keep the index current', function () {
    $pdo = corpus();
    $node = Search::query($pdo, 'cheaper')[0]['node'];
    Ops::apply($pdo, [['op' => 'edit', 'id' => $node['id'], 'text' => 'Bathroom tiles cost more']]);
    eq([], found($pdo, 'cheaper'));
    eq(['Bathroom tiles cost more'], found($pdo, 'cost'));
    Ops::apply($pdo, [['op' => 'edit', 'id' => $node['page'], 'title' => 'House project']]);
    eq(['House project'], found($pdo, 'house'));
    Ops::apply($pdo, [['op' => 'delete', 'id' => $node['page']]]);
    eq([], found($pdo, 'tiles'));
});

test('limit and offset page through results; reindex rebuilds search', function () {
    $pdo = corpus();
    eq(1, count(Search::query($pdo, 'kitchen', 1)));
    eq(2, count(Search::query($pdo, 'kitchen', 50, 1)));
    $pdo->exec('DELETE FROM search');
    Indexer::reindex($pdo);
    eq(3, count(Search::query($pdo, 'kitchen')));
});
