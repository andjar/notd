<?php
require_once dirname(__DIR__, 3) . '/tests/php/support/server.php';

(require dirname(__DIR__) . '/server.php')(new PluginApi('git'));

function git_book(): array
{
    $pdo = fresh_db();
    Db::$shared = $pdo;
    $p = new_id();
    $a = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'Diary'], ['op' => 'create', 'id' => $a, 'parent' => $p, 'text' => 'first version']]);
    return [$pdo, $p, $a];
}

test('changes queue one delayed commit job, replaced while waiting', function () {
    [$pdo, $p, $a] = git_book();
    Ops::apply($pdo, [['op' => 'edit', 'id' => $a, 'text' => 'again']]);
    $jobs = $pdo->query("SELECT kind, run_after - created AS wait FROM jobs WHERE status = 'waiting'")->fetchAll();
    eq(1, count($jobs));
    eq('git.commit', $jobs[0]['kind']);
    eq(60000, (int) $jobs[0]['wait']);
});

test('the commit job exports and commits; history lists versions; restore brings one back', function () {
    [$pdo, $p, $a] = git_book();
    eq(1, preg_match('/^[0-9a-f]+ notd: 1 written, 0 deleted$/', GitHistory::commit()), 'first commit');
    eq('Nothing changed.', GitHistory::commit());
    Ops::apply($pdo, [['op' => 'edit', 'id' => $a, 'text' => 'second version'], ['op' => 'create', 'parent' => $p, 'text' => 'more']]);
    sleep(1); // git times are in seconds
    GitHistory::commit();
    $h = GitHistory::history('Diary');
    eq(2, count($h));
    ok($h[0]['time'] >= $h[1]['time'], 'newest first');
    eq("title:: Diary\n\n- first version\n", GitHistory::version('Diary', $h[1]['commit']));
    GitHistory::restore($pdo, 'Diary', $h[1]['commit']);
    eq(['first version'], array_column(Read::page($pdo, 'Diary')['blocks'], 'text'));
    eq('bad_request', throws(fn() => GitHistory::version('Diary', 'HEAD; rm -rf /'), HttpError::class)->codeName);
    eq('not_found', throws(fn() => GitHistory::version('Nowhere', $h[0]['commit']), HttpError::class)->codeName);
    eq([], GitHistory::history('Nowhere'));
});
