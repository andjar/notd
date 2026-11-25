<?php
require_once __DIR__ . '/support/server.php';

function mk(PDO $pdo, array ...$ops): void
{
    Ops::apply($pdo, $ops);
}

const PA = '01900000-0000-7000-8000-0000000000a1';
const PB = '01900000-0000-7000-8000-0000000000b1';
const B1 = '01900000-0000-7000-8000-000000000001';
const B2 = '01900000-0000-7000-8000-000000000002';
const B3 = '01900000-0000-7000-8000-000000000003';
const B4 = '01900000-0000-7000-8000-000000000004';

function sample(): PDO
{
    $pdo = fresh_db();
    mk($pdo,
        ['op' => 'create', 'kind' => 'page', 'id' => PA, 'title' => 'New York City', 'text' => '{alias:: NYC}'],
        ['op' => 'create', 'kind' => 'page', 'id' => PB, 'title' => 'Trips'],
        ['op' => 'create', 'id' => B1, 'parent' => PA, 'text' => 'first', 'sort' => 'a0'],
        ['op' => 'create', 'id' => B2, 'parent' => B1, 'text' => 'child of first', 'sort' => 'a0'],
        ['op' => 'create', 'id' => B3, 'parent' => PA, 'text' => 'second, links to [[NYC]] itself', 'sort' => 'a1'],
        ['op' => 'create', 'id' => B4, 'parent' => PB, 'text' => 'went to [[nyc]] and #[[New York City]], see ((' . B1 . '))'],
    );
    return $pdo;
}

test('a page comes with all its blocks in outline order', function () {
    $r = Read::page(sample(), 'new york city');
    eq(PA, $r['page']['id']);
    eq(null, $r['via']);
    eq(['first', 'child of first', 'second, links to [[NYC]] itself'], array_column($r['blocks'], 'text'));
});

test('outline order puts children before the next sibling, even with prefix sort keys', function () {
    $pdo = fresh_db();
    mk($pdo, ['op' => 'create', 'kind' => 'page', 'id' => PA, 'title' => 'P'],
        ['op' => 'create', 'id' => B1, 'parent' => PA, 'text' => 'x', 'sort' => 'a0'],
        ['op' => 'create', 'id' => B3, 'parent' => PA, 'text' => 'z', 'sort' => 'a0V'],
        ['op' => 'create', 'id' => B2, 'parent' => B1, 'text' => 'y', 'sort' => 'a1']);
    eq(['x', 'y', 'z'], array_column(Read::page($pdo, 'P')['blocks'], 'text'));
});

test('a page is found by alias and by id', function () {
    $pdo = sample();
    $r = Read::page($pdo, 'nyc');
    eq(PA, $r['page']['id']);
    eq('alias', $r['via']);
    eq(PB, Read::page($pdo, null, PB)['page']['id']);
});

test('an unknown page is not found, but a journal day is created', function () {
    $pdo = sample();
    eq('not_found', throws(fn() => Read::page($pdo, 'Nowhere'), HttpError::class)->codeName);
    eq('not_found', throws(fn() => Read::page($pdo, '2026-02-30'), HttpError::class)->codeName);
    $r = Read::page($pdo, '2026-11-25');
    eq('2026-11-25', $r['page']['title']);
    eq([], $r['blocks']);
    eq($r['page']['id'], Read::page($pdo, '2026-11-25')['page']['id'], 'the same page the second time');
    eq('create', $pdo->query('SELECT op FROM changes ORDER BY seq DESC LIMIT 1')->fetchColumn());
});

test('a block is not a page', function () {
    eq('not_found', throws(fn() => Read::page(sample(), null, B1), HttpError::class)->codeName);
});

test('node returns the node and its descendants', function () {
    $r = Read::node(sample(), B1);
    eq('first', $r['node']['text']);
    eq(['child of first'], array_column($r['descendants'], 'text'));
});

test('backlinks of a page include links, tags and alias links from other pages', function () {
    $groups = Read::backlinks(sample(), PA);
    eq(1, count($groups));
    eq(['id' => PB, 'title' => 'Trips'], $groups[0]['page']);
    eq([B4], array_column($groups[0]['blocks'], 'id'), 'one block, once, and not the page linking to itself');
});

test('backlinks of a block are its references', function () {
    $groups = Read::backlinks(sample(), B1);
    eq([B4], array_column($groups[0]['blocks'], 'id'));
    eq([], Read::backlinks(sample(), B2));
});

test('pages lists titles in order with aliases', function () {
    $pages = Read::pages(sample());
    eq(['New York City', 'Trips'], array_column($pages, 'title'));
    eq(['NYC'], $pages[0]['aliases']);
});

test('journal days are the days of a month whose journal has text', function () {
    $pdo = sample();
    Read::page($pdo, '2026-11-24');                 // empty: no dot
    $id = Read::page($pdo, '2026-11-25')['page']['id'];
    mk($pdo, ['op' => 'create', 'parent' => $id, 'text' => 'wrote something']);
    $id = Read::page($pdo, '2026-12-01')['page']['id'];
    mk($pdo, ['op' => 'create', 'parent' => $id, 'text' => 'next month']);
    eq(['2026-11-25'], Read::journalDays($pdo, '2026-11'));
    eq('bad_request', throws(fn() => Read::journalDays($pdo, '2026-13'), HttpError::class)->codeName);
});
