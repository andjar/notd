<?php
require_once __DIR__ . '/support/server.php';

/** Writes action files to a temporary folder and loads them. */
function with_actions(array $files): void
{
    $dir = sys_get_temp_dir() . '/notd-actions-' . bin2hex(random_bytes(4));
    mkdir($dir);
    foreach ($files as $name => $code) {
        file_put_contents("$dir/$name.php", "<?php\n$code");
    }
    register_shutdown_function(fn() => remove_tree($dir));
    Actions::$problems = [];
    Actions::load(['actions' => $dir]);
}

function act_page(PDO $pdo): string
{
    $p = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'Films']]);
    return $p;
}

function block_text(PDO $pdo, string $id): string
{
    return Nodes::get($pdo, $id)['text'];
}

test('an action tags blocks that match, in the same change', function () {
    $pdo = fresh_db();
    with_actions(['needs-rating' => "return ['on' => 'saved', 'match' => 'type:movie -has:rating',
        'do' => fn(array \$node, Actions \$notd) => \$notd->addTag(\$node, 'needs-rating')];"]);
    $p = act_page($pdo);
    $r = Ops::apply($pdo, [['op' => 'create', 'parent' => $p, 'text' => 'Dune {type::movie}'],
        ['op' => 'create', 'parent' => $p, 'text' => 'Heat {type::movie} {rating:: 4}'],
        ['op' => 'create', 'parent' => $p, 'text' => 'Solaris {type::book}']]);
    [$dune, $heat, $solaris] = array_column($r['results'], 'id');
    eq('Dune {type::movie} #needs-rating', block_text($pdo, $dune));
    eq('Heat {type::movie} {rating:: 4}', block_text($pdo, $heat));
    eq('Solaris {type::book}', block_text($pdo, $solaris));
    eq(['dune'], array_map(fn($n) => strtolower(strtok($n['text'], ' ')), Query::run($pdo, 'tag:needs-rating')['nodes']), 'the tag is indexed');
    Ops::apply($pdo, [['op' => 'edit', 'id' => $dune, 'text' => 'Dune {type::movie} {rating:: 5} #needs-rating']]);
    eq('Dune {type::movie} {rating:: 5} #needs-rating', block_text($pdo, $dune), 'tagging twice is avoided; removing is up to you');
    eq([], Actions::$problems);
});

test('setField adds or replaces a field; created, edited and deleted events', function () {
    $pdo = fresh_db();
    with_actions([
        'stamp' => "return ['on' => 'created', 'do' => fn(\$n, Actions \$a) => \$a->setField(\$n, 'seen', 'yes')];",
        'count' => "return ['on' => 'edited', 'match' => 'has:seen', 'do' => fn(\$n, Actions \$a) => \$a->setField(\$n, 'edits', 'some')];",
        'gone' => "return ['on' => 'deleted', 'do' => function (\$n) { file_put_contents(sys_get_temp_dir() . '/notd-gone-' . getmypid(), \$n['id']); }];",
    ]);
    $p = act_page($pdo);
    $id = Ops::apply($pdo, [['op' => 'create', 'parent' => $p, 'text' => "line one\nline two"]])['results'][0]['id'];
    eq("line one {seen:: yes} {edits:: some}\nline two", block_text($pdo, $id), 'the created action\'s own edit is an edit, so the edited action runs too');
    Ops::apply($pdo, [['op' => 'edit', 'id' => $id, 'text' => 'changed {seen:: yes}']]);
    eq('changed {seen:: yes} {edits:: some}', block_text($pdo, $id));
    Ops::apply($pdo, [['op' => 'delete', 'id' => $id]]);
    eq($id, file_get_contents(sys_get_temp_dir() . '/notd-gone-' . getmypid()));
    unlink(sys_get_temp_dir() . '/notd-gone-' . getmypid());
});

test('actions that keep causing each other are stopped, and the change still goes in', function () {
    $pdo = fresh_db();
    with_actions(['grow' => "return ['on' => 'saved', 'do' => fn(\$n, Actions \$a) => \$a->edit(\$n['id'], \$n['text'] . '+')];"]);
    $p = act_page($pdo);
    $id = Ops::apply($pdo, [['op' => 'create', 'parent' => $p, 'text' => 'x']])['results'][0]['id'];
    eq('x+++', block_text($pdo, $id), 'three levels deep, then it stops');
    ok(str_contains(implode("\n", Actions::$problems), 'kept causing each other'), implode("\n", Actions::$problems));
});

test('an action that throws is reported and skipped; a broken file is reported', function () {
    $pdo = fresh_db();
    with_actions([
        'boom' => "return ['on' => 'saved', 'do' => function () { throw new RuntimeException('boom'); }];",
        'bad' => "return 'not an action';",
        'fine' => "return ['on' => 'saved', 'do' => fn(\$n, Actions \$a) => \$a->addTag(\$n, 'ok')];",
    ]);
    $p = act_page($pdo);
    $id = Ops::apply($pdo, [['op' => 'create', 'parent' => $p, 'text' => 'still saved']])['results'][0]['id'];
    eq('still saved #ok', block_text($pdo, $id));
    $problems = implode("\n", Actions::$problems);
    ok(str_contains($problems, 'actions/boom: boom') && str_contains($problems, 'actions/bad:'), $problems);
});

test('what an action does is part of the change: a failing batch takes it back too', function () {
    $pdo = fresh_db();
    with_actions(['tag' => "return ['on' => 'saved', 'do' => fn(\$n, Actions \$a) => \$a->addTag(\$n, 'tagged')];"]);
    $p = act_page($pdo);
    throws(fn() => Ops::apply($pdo, [['op' => 'create', 'parent' => $p, 'text' => 'a'], ['op' => 'create', 'parent' => new_id(), 'text' => 'b']]), HttpError::class);
    eq(0, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'block'")->fetchColumn());
});
