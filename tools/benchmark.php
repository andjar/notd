<?php
// Builds a synthetic notebook as Markdown, imports it into a throw-away data
// folder and times the things that must stay fast. Exits 1 when a target is missed.
// Usage: php tools/benchmark.php [nodes=183000]

declare(strict_types=1);

$nodes = (int) ($argv[1] ?? 183000);
$work = getenv("NOTD_BENCH_DIR") ?: sys_get_temp_dir() . "/notd-bench-" . getmypid();
putenv("NOTD_DATA_DIR=$work/data");
require dirname(__DIR__) . '/server/core.php';

mt_srand(42);
$words = explode(' ', 'kitchen tiles report meeting idea garden budget travel book film music code server note plan review draft letter call friend family health');
$md = "$work/md";
@mkdir("$md/journals", 0777, true);
@mkdir("$md/pages", 0777, true);
$made = 0;
$pageCount = 0;
$big = null;
while ($made < $nodes) {
    $pageCount++;
    $isJournal = $pageCount % 3 === 0;
    $title = $isJournal ? date('Y-m-d', strtotime('2020-01-01') + $pageCount * 86400) : 'Page ' . $pageCount;
    $size = $pageCount === 7 ? 3000 : mt_rand(5, 60);   // page 7 is the 3,000-node page
    $out = "title:: $title\n\n";
    $depth = 0;
    for ($i = 0; $i < $size && $made < $nodes; $i++, $made++) {
        $depth = max(0, min($depth + mt_rand(-1, 1), 5));
        $text = implode(' ', array_map(fn() => $words[mt_rand(0, count($words) - 1)], range(1, mt_rand(3, 15))));
        if (mt_rand(0, 9) === 0) {
            $text .= ' [[Page ' . mt_rand(1, max(1, $pageCount)) . ']]';
        }
        if (mt_rand(0, 19) === 0) {
            $text .= ' {rating:: ' . mt_rand(1, 5) . '}';
        }
        $out .= str_repeat('  ', $depth) . "- $text\n";
    }
    $made++;
    file_put_contents($md . ($isJournal ? "/journals/$title.md" : "/pages/$title.md"), $out);
}

$results = [];
$time = function (string $name, float $limitMs, callable $fn) use (&$results) {
    $t = microtime(true);
    $fn();
    $ms = (microtime(true) - $t) * 1000;
    $results[] = [$name, $ms, $limitMs];
};
$pdo = Db::get();
if (!Nodes::byTitle($pdo, "Page 7")) {
    $time("import $nodes nodes", 120000, fn() => Import::folder($pdo, $md));
}
$time('open the 3,000-node page', 50, fn() => Read::page($pdo, 'Page 7'));
$time('open a journal page', 50, fn() => Read::page($pdo, date('Y-m-d', strtotime('2020-01-01') + 300 * 86400)));
$time('backlinks of a page', 50, fn() => Read::backlinks($pdo, Nodes::byTitle($pdo, 'Page 4')['id']));
$time('search "kitchen tiles" (words in a third of all notes)', 300, fn() => Search::query($pdo, 'kitchen tiles'));
$time('query rating>=4 sort:-rating', 200, fn() => Query::run($pdo, 'rating>=4 sort:-rating'));
$time('page names', 100, fn() => Read::pages($pdo));
if (getenv("NOTD_BENCH_SKIP_EXPORT")) { goto report; }
$time('export everything', 60000, fn() => Markdown::export($pdo, "$work/export"));
$time('export again (nothing changed)', 30000, fn() => Markdown::export($pdo, "$work/export"));

report:
$failed = false;
$table = "| Benchmark | Time | Target |\n| --- | ---: | ---: |\n";
foreach ($results as [$name, $ms, $limit]) {
    $ok = $ms <= $limit;
    $failed = $failed || !$ok;
    $table .= sprintf("| %s | %s | %s %s |\n", $name, $ms >= 1000 ? sprintf('%.1f s', $ms / 1000) : sprintf('%.1f ms', $ms),
        $limit >= 1000 ? ($limit / 1000) . ' s' : "$limit ms", $ok ? '' : '**missed**');
}
echo $table;
if ($summary = getenv('GITHUB_STEP_SUMMARY')) {
    file_put_contents($summary, "## Benchmarks\n\n$table", FILE_APPEND);
}
if (!getenv("NOTD_BENCH_DIR")) {
    exec("rm -rf " . escapeshellarg($work));
}
exit($failed ? 1 : 0);
