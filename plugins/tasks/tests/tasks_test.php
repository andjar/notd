<?php
require_once dirname(__DIR__, 3) . '/tests/php/support/server.php';

(require dirname(__DIR__) . '/server.php')(new PluginApi('tasks'));

function tasks_page(): PDO
{
    $pdo = fresh_db();
    $p = new_id();
    $ops = [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'Tasks']];
    foreach (['TODO buy milk {scheduled:: 2026-11-20}', 'DOING write report {start:: 2026-11-24 09:00}', 'WAITING reply from Ola',
        'DONE paint fence {done:: 2026-11-25 10:00}', 'CANCELLED old idea', 'not a TODO', 'todo lowercase is text'] as $t) {
        $ops[] = ['op' => 'create', 'parent' => $p, 'text' => $t];
    }
    Ops::apply($pdo, $ops);
    return $pdo;
}

function task_texts(PDO $pdo, string $q): array
{
    $t = array_map(fn($n) => strtok($n['text'], ' '), Query::run($pdo, $q)['nodes']);
    sort($t);
    return $t;
}

test('a task keyword is indexed as status', function () {
    $pdo = tasks_page();
    eq(['cancelled', 'doing', 'done', 'todo', 'waiting'],
        $pdo->query("SELECT value FROM fields WHERE key = 'status' ORDER BY value")->fetchAll(PDO::FETCH_COLUMN));
});

cases('task: filters by state', [
    'open'      => ['task:open', ['DOING', 'TODO', 'WAITING']],
    'done'      => ['task:done', ['DONE']],
    'any'       => ['task:any', ['CANCELLED', 'DOING', 'DONE', 'TODO', 'WAITING']],
    'not done'  => ['task!=done page:tasks', ['CANCELLED', 'DOING', 'TODO', 'WAITING', 'not', 'todo']],
    'scheduled' => ['task:open scheduled<=2026-11-25', ['TODO']],
], function (string $q, array $want) {
    eq($want, task_texts(tasks_page(), $q));
});

test('an unknown state is refused', function () {
    eq('bad_query', throws(fn() => Query::run(tasks_page(), 'task:later'), HttpError::class)->codeName);
});

test('ticking a task (an edit) updates its status', function () {
    $pdo = tasks_page();
    $id = Query::run($pdo, 'task:todo')['nodes'][0]['id'];
    Ops::apply($pdo, [['op' => 'edit', 'id' => $id, 'text' => 'DONE buy milk']]);
    eq([], Query::run($pdo, 'task:todo')['nodes']);
});
