<?php
require_once __DIR__ . '/support/server.php';

/** Applies ops and returns the result; throws like the endpoint does. */
function ops(PDO $pdo, array ...$ops): array
{
    return Ops::apply($pdo, $ops);
}

function texts(PDO $pdo, string $parent): array
{
    return array_column(Nodes::children($pdo, $parent), 'text');
}

function page(PDO $pdo, string $title = 'P'): string
{
    $id = new_id();
    ops($pdo, ['op' => 'create', 'kind' => 'page', 'id' => $id, 'title' => $title]);
    return $id;
}

test('create makes pages and blocks; blocks without a sort key go last', function () {
    $pdo = fresh_db();
    $p = page($pdo);
    ops($pdo, ['op' => 'create', 'parent' => $p, 'text' => 'one'], ['op' => 'create', 'parent' => $p, 'text' => 'two']);
    eq(['one', 'two'], texts($pdo, $p));
    $first = Nodes::children($pdo, $p)[0];
    ops($pdo, ['op' => 'create', 'parent' => $p, 'text' => 'zero', 'sort' => SortKeys::between(null, $first['sort'])]);
    eq(['zero', 'one', 'two'], texts($pdo, $p));
});

test('a batch is all or nothing', function () {
    $pdo = fresh_db();
    $p = page($pdo);
    $e = throws(fn() => ops($pdo,
        ['op' => 'create', 'parent' => $p, 'text' => 'kept?'],
        ['op' => 'create', 'parent' => new_id(), 'text' => 'orphan']), HttpError::class);
    eq('not_found', $e->codeName);
    ok(str_starts_with($e->getMessage(), 'Operation 1:'), $e->getMessage());
    eq([], texts($pdo, $p));
});

test('an opId seen before is skipped', function () {
    $pdo = fresh_db();
    $p = page($pdo);
    $op = ['op' => 'create', 'opId' => 'op-1', 'parent' => $p, 'text' => 'once'];
    eq('applied', ops($pdo, $op)['results'][0]['status']);
    eq('duplicate', ops($pdo, $op)['results'][0]['status']);
    eq(['once'], texts($pdo, $p));
});

test('edit changes text and titles; titles stay unique', function () {
    $pdo = fresh_db();
    $p = page($pdo, 'Old');
    page($pdo, 'Other');
    $b = new_id();
    ops($pdo, ['op' => 'create', 'id' => $b, 'parent' => $p, 'text' => 'a']);
    ops($pdo, ['op' => 'edit', 'id' => $b, 'text' => 'b'], ['op' => 'edit', 'id' => $p, 'title' => 'New']);
    eq('b', Nodes::get($pdo, $b)['text']);
    eq($p, Nodes::byTitle($pdo, 'new')['id']);
    eq(null, Nodes::byTitle($pdo, 'Old'));
    eq('title_taken', throws(fn() => ops($pdo, ['op' => 'edit', 'id' => $p, 'title' => 'OTHER']), HttpError::class)->codeName);
    ops($pdo, ['op' => 'edit', 'id' => $p, 'title' => 'NEW']);   // a case change of its own title is fine
    eq('NEW', Nodes::get($pdo, $p)['title']);
    eq('bad_request', throws(fn() => ops($pdo, ['op' => 'edit', 'id' => $b, 'title' => 'x']), HttpError::class)->codeName);
});

test('move re-parents a block and its subtree, across pages', function () {
    $pdo = fresh_db();
    [$p1, $p2] = [page($pdo, 'One'), page($pdo, 'Two')];
    [$a, $child] = [new_id(), new_id()];
    ops($pdo, ['op' => 'create', 'id' => $a, 'parent' => $p1, 'text' => 'a'], ['op' => 'create', 'id' => $child, 'parent' => $a, 'text' => 'c']);
    ops($pdo, ['op' => 'move', 'id' => $a, 'parent' => $p2]);
    eq([], texts($pdo, $p1));
    eq(['a'], texts($pdo, $p2));
    eq($p2, Nodes::get($pdo, $child)['page']);
});

test('a block cannot move into itself or its children', function () {
    $pdo = fresh_db();
    $p = page($pdo);
    [$a, $child] = [new_id(), new_id()];
    ops($pdo, ['op' => 'create', 'id' => $a, 'parent' => $p], ['op' => 'create', 'id' => $child, 'parent' => $a]);
    eq('cycle', throws(fn() => ops($pdo, ['op' => 'move', 'id' => $a, 'parent' => $child]), HttpError::class)->codeName);
    eq('cycle', throws(fn() => ops($pdo, ['op' => 'move', 'id' => $a, 'parent' => $a]), HttpError::class)->codeName);
    eq('bad_request', throws(fn() => ops($pdo, ['op' => 'move', 'id' => $p, 'parent' => $a]), HttpError::class)->codeName);
});

test('delete removes the whole subtree and frees the title', function () {
    $pdo = fresh_db();
    $p = page($pdo, 'Gone');
    [$a, $child] = [new_id(), new_id()];
    ops($pdo, ['op' => 'create', 'id' => $a, 'parent' => $p], ['op' => 'create', 'id' => $child, 'parent' => $a]);
    ops($pdo, ['op' => 'delete', 'id' => $p]);
    eq(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    page($pdo, 'Gone');
    $deletes = $pdo->query("SELECT node_id FROM changes WHERE op = 'delete' ORDER BY seq")->fetchAll(PDO::FETCH_COLUMN);
    eq([$child, $a, $p], $deletes, 'children reported before parents');
});

test('collapse sets the flag and checks its argument', function () {
    $pdo = fresh_db();
    $p = page($pdo);
    $a = new_id();
    ops($pdo, ['op' => 'create', 'id' => $a, 'parent' => $p], ['op' => 'collapse', 'id' => $a, 'collapsed' => true]);
    eq(true, Nodes::get($pdo, $a)['collapsed']);
    eq('bad_request', throws(fn() => ops($pdo, ['op' => 'collapse', 'id' => $a, 'collapsed' => 1]), HttpError::class)->codeName);
});

test('every change gets a clock that only goes up, and a numbered feed entry', function () {
    $pdo = fresh_db();
    $p = page($pdo);
    $r = ops($pdo, ...array_map(fn($i) => ['op' => 'create', 'parent' => $p, 'text' => "n$i"], range(1, 20)));
    $clocks = $pdo->query('SELECT clock FROM changes ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN);
    $sorted = $clocks;
    sort($sorted, SORT_STRING);
    eq($sorted, $clocks);
    eq(count($clocks), count(array_unique($clocks)), 'all different');
    eq(21, $r['seq']);
    ok(preg_match('/^\d{16}-[0-9a-f]{4}$/', $clocks[0]) === 1, $clocks[0]);
    eq(end($clocks), Nodes::get($pdo, $r['results'][19]['id'])['clock']);
});

cases('malformed operations are refused', [
    'unknown op'  => [['op' => 'explode'], 'bad_op'],
    'no op'       => [['id' => 'x'], 'bad_request'],
    'bad sort'    => [['op' => 'create', 'parent' => 'PAGE', 'sort' => '!'], 'bad_sort'],
    'number text' => [['op' => 'create', 'parent' => 'PAGE', 'text' => 5], 'bad_request'],
    'file kind'   => [['op' => 'create', 'kind' => 'file', 'title' => 'x'], 'bad_request'],
    'long opId'   => [['op' => 'create', 'opId' => str_repeat('x', 101), 'parent' => 'PAGE'], 'bad_request'],
], function (array $op, string $code) {
    $pdo = fresh_db();
    $p = page($pdo);
    array_walk($op, function (&$v) use ($p) { if ($v === 'PAGE') { $v = $p; } });
    eq($code, throws(fn() => ops($pdo, $op), HttpError::class)->codeName);
});

test('a batch over the limit is refused', function () {
    eq('bad_request', throws(fn() => Ops::apply(fresh_db(), array_fill(0, OPS_MAX + 1, ['op' => 'x'])), HttpError::class)->codeName);
});
