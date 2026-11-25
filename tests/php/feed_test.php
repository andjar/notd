<?php
require_once __DIR__ . '/support/server.php';

test('the feed lists changes after a number, one entry per node with its current state', function () {
    $pdo = fresh_db();
    $p = new_id();
    $b = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'P'], ['op' => 'create', 'id' => $b, 'parent' => $p, 'text' => 'v1']]);
    $mark = Ops::lastSeq($pdo);
    Ops::apply($pdo, [['op' => 'edit', 'id' => $b, 'text' => 'v2']]);
    Ops::apply($pdo, [['op' => 'edit', 'id' => $b, 'text' => 'v3']]);
    $f = Feed::since($pdo, $mark);
    eq(1, count($f['changes']));
    eq(['edit', $b, 'v3'], [$f['changes'][0]['op'], $f['changes'][0]['id'], $f['changes'][0]['node']['text']]);
    eq($mark + 2, $f['next']);
    eq(false, $f['more']);
    eq([], Feed::since($pdo, $f['next'])['changes']);
    eq($f['next'], Feed::since($pdo, $f['next'])['next']);
});

test('deleted nodes come with node null, after the changes before them', function () {
    $pdo = fresh_db();
    $p = new_id();
    $b = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'P'], ['op' => 'create', 'id' => $b, 'parent' => $p, 'text' => 'x']]);
    Ops::apply($pdo, [['op' => 'edit', 'id' => $b, 'text' => 'y'], ['op' => 'delete', 'id' => $b]]);
    $f = Feed::since($pdo, 0);
    eq([[$p, 'create'], [$b, 'delete']], array_map(fn($c) => [$c['id'], $c['op']], $f['changes']));
    eq(null, $f['changes'][1]['node']);
});

test('a node changed and then deleted in a later page reads as deleted', function () {
    $pdo = fresh_db();
    $p = new_id();
    $b = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'P'], ['op' => 'create', 'id' => $b, 'parent' => $p, 'text' => 'x']]);
    Ops::apply($pdo, [['op' => 'delete', 'id' => $b]]);
    $first = Feed::since($pdo, 0, 2);
    eq('delete', $first['changes'][1]['op'], 'already gone when the first page is read');
    eq(true, $first['more']);
});

test('limit pages through the feed', function () {
    $pdo = fresh_db();
    $p = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'P'],
        ...array_map(fn($i) => ['op' => 'create', 'parent' => $p, 'text' => "b$i"], range(1, 9))]);
    $seen = [];
    $since = 0;
    do {
        $f = Feed::since($pdo, $since, 4);
        array_push($seen, ...array_column($f['changes'], 'seq'));
        $since = $f['next'];
    } while ($f['more']);
    eq(range(1, 10), $seen);
});
