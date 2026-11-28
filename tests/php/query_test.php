<?php
require_once __DIR__ . '/support/server.php';

function library(): PDO
{
    $pdo = fresh_db();
    $p = new_id();
    $ops = [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'Library']];
    foreach ([
        'Dune {type::movie} {rating:: 4.5} {watched:: 2026-03-01} #scifi',
        'Alien {type::Movie} {rating:: 4} {watched:: 2025-12-24} #scifi #horror',
        'Heat {type::movie} {rating:: 3}',
        'Arrival {type::movie} [[Villeneuve]] {rating::10}',
        'Solaris {type::book} {rating:: 5} #scifi',
        'Østfold notes {type::place}',
        'plain note about space opera',
    ] as $text) {
        $ops[] = ['op' => 'create', 'parent' => $p, 'text' => $text];
    }
    Ops::apply($pdo, $ops);
    return $pdo;
}

function q(PDO $pdo, string $query): array
{
    return array_map(fn($n) => strtok($n['text'], ' '), Query::run($pdo, $query)['nodes']);
}

test('field equality ignores case of the value', function () {
    $r = q(library(), 'type:movie sort:title');
    sort($r);
    eq(['Alien', 'Arrival', 'Dune', 'Heat'], $r);
});

test('numbers compare as numbers, dates as text', function () {
    $pdo = library();
    eq(['Arrival', 'Solaris', 'Dune', 'Alien'], q($pdo, 'rating>=4 sort:-rating'));
    eq(['Heat'], q($pdo, 'type:movie rating<4'));
    eq(['Dune'], q($pdo, 'watched>2026-01-01'));
});

test('negation, has, and !=', function () {
    $pdo = library();
    eq(['Arrival', 'Heat'], q($pdo, 'type:movie -tag:scifi sort:title'));
    eq(['plain', 'Østfold'], q($pdo, 'kind:block -has:rating sort:text'));
    eq(['Østfold'], q($pdo, 'has:type type!=movie type!=book'));
});

test('OR joins the filters on either side', function () {
    eq(['Alien', 'Solaris'], q(library(), 'tag:horror OR type:book sort:title'));
    eq(['Solaris'], q(library(), 'tag:horror OR type:book rating>4.5'));
});

test('built-in filters: tag, links, page, kind', function () {
    $pdo = library();
    eq(['Alien', 'Dune', 'Solaris'], q($pdo, 'tag:#SciFi sort:title'));
    eq(['Arrival'], q($pdo, 'links:villeneuve'));
    eq(7, count(Query::run($pdo, 'page:library kind:block')['nodes']));
    eq(['Library'], array_column(Query::run($pdo, 'kind:page')['nodes'], 'title'));
});

test('free words and phrases search the text', function () {
    $pdo = library();
    eq(['plain'], q($pdo, '"space opera"'));
    eq(['plain'], q($pdo, 'spa ope'));
    eq([], q($pdo, '"opera space"'));
});

test('sort by a field puts nodes without it last; limit cuts', function () {
    $pdo = library();
    $all = q($pdo, 'page:library sort:rating');
    eq(['Heat', 'Alien', 'Dune', 'Solaris', 'Arrival'], array_slice($all, 0, 5));
    eq(2, count(q($pdo, 'page:library limit:2')));
});

test('results carry their fields and page title', function () {
    $node = Query::run(library(), 'tag:horror')['nodes'][0];
    eq(['type' => ['Movie'], 'rating' => ['4'], 'watched' => ['2025-12-24']], $node['fields']);
    eq('Library', $node['pageTitle']);
});

test('group puts nodes under each value, with nodes lacking it last', function () {
    $r = Query::run(library(), 'page:library group:type sort:title view:table');
    eq(['book', 'movie', 'place', null], array_map(fn($g) => is_null($g['value']) ? null : title_key($g['value']), $r['groups']));
    eq(4, count($r['groups'][1]['nodes']));
    eq(['view' => 'table'], (array) $r['options']);
});

test('group by a time: by day, hour, month or year of a date and time, in the order of the sort', function () {
    $pdo = fresh_db();
    $p = new_id();
    $feed = new_id();
    $ops = [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'Feeds'], ['op' => 'create', 'id' => $feed, 'parent' => $p, 'text' => 'Feed']];
    foreach (['a 2026-11-27 09:15', 'b 2026-11-28 14:05', 'c 2026-11-28 14:40', 'd 2026-11-28 16:00', 'e 2025-12-31 23:59'] as $t) {
        [$name, $when] = explode(' ', $t, 2);
        $ops[] = ['op' => 'create', 'parent' => $feed, 'text' => "$name {published:: $when}"];
    }
    $ops[] = ['op' => 'create', 'parent' => $p, 'text' => 'not under the feed {published:: 2026-11-28 15:00}'];
    Ops::apply($pdo, $ops);
    $groups = fn(string $q) => array_map(fn($g) => [$g['value'], implode('', array_map(fn($n) => $n['text'][0], $g['nodes']))], Query::run($pdo, $q)['groups']);
    eq([['2026-11-28', 'dcb'], ['2026-11-27', 'a'], ['2025-12-31', 'e']], $groups("parent:$feed group:published:day sort:-published"));
    eq([['2026-11-28 16', 'd'], ['2026-11-28 14', 'cb'], ['2026-11-27 09', 'a'], ['2025-12-31 23', 'e']], $groups("parent:(($feed)) group:published:hour sort:-published"));
    eq([['2025-12', 'e'], ['2026-11', 'abcd']], $groups("parent:$feed group:published:month sort:published"));
    eq([['2025', 'e'], ['2026', 'abcd']], $groups("parent:$feed group:published:year sort:published"));
    eq(6, count(Query::run($pdo, 'has:published')['nodes']));
    eq(5, count(Query::run($pdo, "parent:$feed")['nodes']), 'parent: takes the children only');
});

cases('bad queries are refused with a reason', [
    'empty'          => [''],
    'only options'   => ['sort:title'],
    'dangling OR'    => ['type:movie OR'],
    'leading OR'     => ['OR type:movie'],
    'bad limit'      => ['type:movie limit:0'],
    'bad date'       => ['created>yesterdayish'],
    'kind compare'   => ['kind>page'],
], function (string $query) {
    eq('bad_query', throws(fn() => Query::compile($query), HttpError::class)->codeName);
});

test('values are parameters, never SQL', function () {
    $c = Query::compile("type:\"x' OR 1=1 --\" rating>=4");
    ok(!str_contains($c['where'], 'OR 1=1'), $c['where']);
    eq([], Query::run(library(), "type:\"x' OR 1=1 --\"")['nodes']);
});
