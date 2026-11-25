<?php
// Imports a folder of Markdown: a notd export, a Logseq graph, or plain notes.
// Usage: php tools/import.php <folder>
// Pages that already exist get the imported blocks added at the end.

declare(strict_types=1);
PHP_SAPI === 'cli' || exit("Run this from the command line.\n");   // not over the web (phpdesktop serves every .php)

require dirname(__DIR__) . '/server/core.php';
require_once __DIR__ . '/lib/import.php';
Plugins::load(Db::get());   // plugins' indexers and actions take part

if (!isset($argv[1]) || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php tools/import.php <folder>\n");
    exit(1);
}
$start = microtime(true);
$s = Import::folder(Db::get(), $argv[1]);
printf("%d pages, %d blocks, %d file links in %.1f s\n", $s['pages'], $s['blocks'], $s['files'], microtime(true) - $start);
foreach ([...$s['warnings'], ...array_map(fn($p) => "action $p", Actions::$problems)] as $w) {
    echo "  warning: $w\n";
}
