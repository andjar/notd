<?php
// Runs every *_test.php file under tests/php/ (and plugins/*/tests/).
//
//   php tests/php/run.php            all tests
//   php tests/php/run.php ordering   only tests whose file or name contains "ordering"
//   php tests/php/run.php --dir=DIR  only test files under DIR
//
// Exit code 0 when every test passes, 1 otherwise.

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(function (int $no, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $no, $file, $line);
});

require __DIR__ . '/lib.php';

$root = dirname(__DIR__, 2);
$dirs = [__DIR__, ...glob($root . '/plugins/*/tests', GLOB_ONLYDIR)];
$filter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dirs = [substr($arg, 6)];
    } else {
        $filter = $arg;
    }
}

$files = [];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        $isFixture = str_contains($path, DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR)
            && !str_contains($dir, DIRECTORY_SEPARATOR . 'fixtures');
        if (str_ends_with($path, '_test.php') && !$isFixture) {
            $files[] = $path;
        }
    }
}
sort($files);

$passed = 0;
$failed = [];
$started = microtime(true);
foreach ($files as $file) {
    TestRegistry::$tests = [];
    if (class_exists(Indexer::class, false)) {   // what one file's plugins registered does not leak into the next
        Indexer::$extra = [];
        Query::$filters = [];
        Ops::$listeners = array_intersect_key(Ops::$listeners, ['index' => 1, 'search' => 1]);
    }
    require $file;
    $short = substr($file, strlen($root) + 1);
    foreach (TestRegistry::$tests as $t) {
        if ($filter !== null && !str_contains($short, $filter) && !str_contains($t['name'], $filter)) {
            continue;
        }
        try {
            ($t['fn'])();
            $passed++;
        } catch (Throwable $e) {
            $where = failureLocation($e, $file);
            $kind = $e instanceof TestFailure ? '' : get_class($e) . ': ';
            $failed[] = "FAIL $short: {$t['name']}\n     $kind{$e->getMessage()}\n     at $where";
        }
    }
}

foreach ($failed as $f) {
    echo $f, "\n";
}
$seconds = number_format(microtime(true) - $started, 2);
printf("%d passed, %d failed in %d files (%s s)\n", $passed, count($failed), count($files), $seconds);
exit($failed ? 1 : 0);

/** The line in the test file (or, failing that, the deepest frame) where the test failed. */
function failureLocation(Throwable $e, string $testFile): string
{
    foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $frame) {
        if (($frame['file'] ?? null) === $testFile) {
            return basename($testFile) . ':' . $frame['line'];
        }
    }
    return basename($e->getFile()) . ':' . $e->getLine();
}
