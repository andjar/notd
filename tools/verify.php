<?php
// Checks stored files against the database: missing, corrupt (SHA-256 differs), and
// orphans (files no node describes). Usage: php tools/verify.php [--clean]
// --clean deletes the orphans. Exit code 1 when anything is missing or corrupt.

declare(strict_types=1);
PHP_SAPI === 'cli' || exit("Run this from the command line.\n");   // not over the web (phpdesktop serves every .php)

require dirname(__DIR__) . '/server/core.php';

$r = Files::verify(Db::get(), in_array('--clean', $argv, true));
printf("%d files checked: %d missing, %d corrupt, %d orphans%s\n", $r['checked'], count($r['missing']), count($r['corrupt']),
    count($r['orphans']), in_array('--clean', $argv, true) && $r['orphans'] ? ' (deleted)' : '');
foreach (['missing', 'corrupt', 'orphans'] as $kind) {
    foreach ($r[$kind] as $path) {
        echo "  $kind: $path\n";
    }
}
exit($r['missing'] || $r['corrupt'] ? 1 : 0);
