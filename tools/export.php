<?php
// Writes the Markdown export. Usage: php tools/export.php [folder]
// (default: export_dir, else <data folder>/export)

declare(strict_types=1);

require dirname(__DIR__) . '/server/core.php';
Plugins::load(Db::get());   // plugins' indexers and actions take part

$dir = $argv[1] ?? Config::get('export_dir', Config::dataDir() . '/export');
$s = Markdown::export(Db::get(), $dir);
printf("%s: %d written, %d unchanged, %d deleted\n", $dir, $s['written'], $s['unchanged'], $s['deleted']);
