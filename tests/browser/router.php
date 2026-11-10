<?php
// Router for `php -S` used by the tests: the application's own router, plus
// the artificial API latency (milliseconds) written to latency.txt.
$f = __DIR__ . '/latency.txt';
$ms = is_file($f) ? (int)trim(file_get_contents($f)) : 0;
if ($ms > 0 && strpos($_SERVER['REQUEST_URI'], '/api/v1/') === 0) {
    usleep($ms * 1000);
}
return require __DIR__ . '/../../router.php';
