<?php
// Rebuilds the fields and links index from the text of every node.
// Usage: php tools/reindex.php   (uses the data folder notd uses)

declare(strict_types=1);

require dirname(__DIR__) . '/server/core.php';

$start = microtime(true);
$count = Indexer::reindex(Db::get());
printf("Reindexed %d nodes in %.2f s\n", $count, microtime(true) - $start);
