<?php
require_once __DIR__ . '/support/server.php';

function job_rows(PDO $pdo): array
{
    return $pdo->query('SELECT kind, status, attempts, output FROM jobs ORDER BY id')->fetchAll();
}

test('a program runs after it is queued, without a shell, and its output is kept', function () {
    $pdo = fresh_db();
    $file = Config::dataDir() . '/made-by-job';
    Jobs::run($pdo, [PHP_BINARY, '-r', 'file_put_contents($argv[1], "hi; rm -rf /"); echo "wrote";', $file]);
    eq(false, is_file($file), 'not before the queue runs');
    eq(1, Jobs::work($pdo));
    eq('hi; rm -rf /', file_get_contents($file), 'arguments are passed as they are, never through a shell');
    eq([['run', 'done', 1, 'wrote']], array_map(fn($r) => [$r['kind'], $r['status'], (int) $r['attempts'], $r['output']], job_rows($pdo)));
});

test('a waiting job with the same key is replaced', function () {
    $pdo = fresh_db();
    foreach ([1, 2, 3] as $i) {
        Jobs::run($pdo, [PHP_BINARY, '-r', "echo $i;"], 'commit');
    }
    Jobs::run($pdo, [PHP_BINARY, '-r', 'echo "other";'], 'other');
    Jobs::work($pdo);
    eq(['3', 'other'], array_column(job_rows($pdo), 'output'));
});

test('a failing job is tried again, then marked failed', function () {
    $pdo = fresh_db();
    Jobs::run($pdo, [PHP_BINARY, '-r', 'echo "nope"; exit(2);']);
    for ($i = 0; $i < 4; $i++) {
        Jobs::work($pdo);
    }
    $r = job_rows($pdo)[0];
    eq(['failed', JOBS_ATTEMPTS], [$r['status'], (int) $r['attempts']]);
    ok(str_contains($r['output'], 'nope') && str_contains($r['output'], 'exit code 2'), $r['output']);
});

test('a job that runs too long is stopped', function () {
    [$ok, $out] = Jobs::exec([PHP_BINARY, '-r', 'sleep(5);'], 1);
    eq(false, $ok);
    ok(str_contains($out, 'stopped after 1 s'), $out);
});

test('plugins handle jobs by name; an unknown kind fails clearly', function () {
    $pdo = fresh_db();
    Jobs::$handlers['test.echo'] = fn(array $p): string => 'got ' . $p['n'];
    Jobs::queue($pdo, 'test.echo', ['n' => 7]);
    Jobs::queue($pdo, 'nobody.home', []);
    Jobs::work($pdo);
    Jobs::work($pdo);
    $rows = job_rows($pdo);
    eq('got 7', $rows[0]['output']);
    ok(str_contains($rows[1]['output'], 'No handler for "nobody.home"'), $rows[1]['output']);
    unset(Jobs::$handlers['test.echo']);
});

test('a job queued by an action exists only if the change does', function () {
    $pdo = fresh_db();
    $dir = sys_get_temp_dir() . '/notd-jobact-' . bin2hex(random_bytes(3));
    mkdir($dir);
    file_put_contents("$dir/log.php", "<?php return ['on' => 'created', 'do' => fn(\$n, Actions \$a) => \$a->run(['true'], 'log')];");
    Actions::load(['actions' => $dir]);
    $p = new_id();
    Ops::apply($pdo, [['op' => 'create', 'kind' => 'page', 'id' => $p, 'title' => 'J']]);
    throws(fn() => Ops::apply($pdo, [['op' => 'create', 'parent' => $p], ['op' => 'create', 'parent' => new_id()]]), HttpError::class);
    eq(1, count(job_rows($pdo)), 'only the job from the page creation; the failed batch left none');
    remove_tree($dir);
});
